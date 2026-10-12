#!/usr/bin/env python3
"""Differential PHP CSV/import fixtures using the established Python contracts."""
from __future__ import annotations

import argparse
import base64
import hashlib
import json
import os
from pathlib import Path
import shlex
import subprocess
import sys
import tempfile
import zipfile

ROOT = Path(__file__).resolve().parents[1]
sys.path[:0] = [str(ROOT / "backend/src"), str(ROOT / "backend/tests")]

from motorworld_csv.importing.csv_reader import inspect_csv
from motorworld_csv.importing.models import FileBinding, ImportProfile, InputSource
from motorworld_csv.importing.service import import_catalog
from motorworld_csv.exporting.writer import encode_record, write_csv_parts
from motorworld_csv.errors import DomainError
from motorworld_csv.domain import ListingDraft, Diagnostic, PriceResult
from motorworld_csv.storage import Repository
from motorworld_csv.platforms.profiles import PrestashopProfile
from motorworld_csv.exporting.service import export_selected
from motorworld_csv.exporting.contracts import PS_HEADERS


def fixtures(work: Path) -> dict:
    cases = []
    inspections = []
    sources = {
        "catalog.csv": '\ufeffcode;name;ean;available;extra\r\n00123;"Filtro; ""speciale""\nseconda riga";0001234567890;0;raw\r\n00123;Altro;0002;si;second\r\n00123;Altro;0002;si;second\r\n00002;Normale;0000000000001;strano;\r\n;Senza codice;;;\r\nmalformed;Riga;0003\r\n',
        "prices.csv": "code;cost\n00123;10\n00123;10.00\n00002;NaN\n99999;7\n",
        "packaging.csv": "code;qty;weight;volume\n00123;1;2;3\n00123;10;20;\n00002;-1;NaN;2\n99999;1;1;\n",
    }
    for name, content in sources.items():
        (work / name).write_text(content, encoding="utf-8", newline="")
    profile = ImportProfile(id="profile", supplier_id="demo", name="Demo", bindings=[
        FileBinding(role="catalog", column_map={"code": "code", "name": "name", "ean": "ean", "available": "available"}),
        FileBinding(role="prices", column_map={"code": "code", "cost": "cost"}),
        FileBinding(role="packaging", column_map={"code": "code", "quantity": "qty", "gross_weight": "weight", "volume": "volume"}),
    ])
    inputs = [InputSource(id=name, path=work/name, role=role) for name, role in zip(sources, ["catalog", "prices", "packaging"])]
    result = import_catalog(inputs, profile, lambda _: None)
    cases.append({"name": "multiline_join_conflicts", "profile": profile.model_dump(mode="json"),
                  "sources": [s.model_dump(mode="json") for s in inputs], "expected": result.model_dump(mode="json")})
    repo=Repository(work/"reference.sqlite")
    repo.save_import("multiline_join_conflicts",result)
    platform=PrestashopProfile(tax_rule_id="1")
    repo.put_record("validation","multiline_join_conflicts:prestashop",{"profile":platform.model_dump(mode="json")})
    price=PriceResult(calculated_gross="19.90",final_gross="19.90",net_revenue="1",commission="1",profit="1",margin="1",minimum_gross=None,below_minimum=False)
    base={"destination":"prestashop","category_ids":["12"],"price":price,"quantity":1,"description_html":'<p>È; "test"</p>',"fields":{"EAN13":"0001234567890","Brand":"Marca"}}
    good=ListingDraft(**base,id="good",product_id=result.products[0].id,reference="00123",title="Titolo valido",valid=True)
    bad=ListingDraft(**base,id="bad",product_id=result.products[1].id,reference="00002",title="Titolo lungo",valid=False,diagnostics=[Diagnostic(level="error",code="title_too_long",message="Troppo lungo",hint="Correggi titolo")])
    repo.save_drafts("multiline_join_conflicts",[good,bad])
    reference=export_selected(repo,"multiline_join_conflicts","prestashop",["good","bad","good"],work/"reference-export",lambda _:None)
    export_expected={name:hashlib.sha256(Path(reference.directory,name).read_bytes()).hexdigest() for name in ["prodotti.csv","rapporto.csv"]}
    stress_description='x'*10_000+'é; "test"\n'
    stress_rows=({"Name":f"Stress {i}","Reference #":f"stress-{i}","Categories (x,y,z...)":"12","Price tax included":"19.90", "Quantity":"1",
                  "Description":stress_description,"EAN13":"0001234567890","Brand":"Marca"} for i in range(2000))
    stress_parts=write_csv_parts(stress_rows,PS_HEADERS,work/"stress-reference")
    stress_expected={"sizes":stress_parts.sizes,"row_counts":stress_parts.row_counts,"sha256":[hashlib.sha256(Path(p).read_bytes()).hexdigest() for p in stress_parts.paths]}
    for name, content, decimal_separator, thousands_separator in [
        ("unicode_trim", "code;name;cost;ean\n\u00a000001\u2003;\u00a0È speciale\u2003;\u00a01.234,50\u2003;\u00a00000001\u2003\n", ",", "."),
        ("whitespace_quote", 'code;name;cost;ean\n001;  "test";10;0001\n', ".", None),
        ("carriage_return", "code;name;cost;ean\r001;Primo;10;0001\r002;Secondo;12;0002\r", ".", None),
        ("negative_zero", "code;name;cost;ean\n001;Zero;-0000.00;0001\n002;Frazione;+.5000;0002\n", ".", None),
    ]:
        path=work/(name+".csv"); path.write_text(content, encoding="utf-8", newline="")
        selected_profile=ImportProfile(id=name,supplier_id="demo",name=name,bindings=[FileBinding(role="catalog",column_map={"code":"code","name":"name","cost":"cost","ean":"ean"},decimal_separator=decimal_separator,thousands_separator=thousands_separator)])
        selected_sources=[InputSource(id=name,path=path,role="catalog")]
        expected=import_catalog(selected_sources,selected_profile,lambda _:None)
        cases.append({"name":name,"profile":selected_profile.model_dump(mode="json"),"sources":[s.model_dump(mode="json") for s in selected_sources],"expected":expected.model_dump(mode="json")})
    encoding_cases = [("catalog.csv", None, None), ("duplicate.csv", None, None), ("encoding.csv", None, None),
                      ("encoding.csv", "cp1252", ";"), ("ambiguous.csv", None, None), ("broken.csv", "utf-8", ";"),
                      ("sniff.csv", None, None), ("header_multiline.csv", None, None), ("casefold.csv", "utf-8", ";"), ("mixed_delimiters.csv",None,None)]
    (work/"duplicate.csv").write_text("Códice;codice\n1;2\n")
    (work/"encoding.csv").write_bytes("code;name\n0001;È speciale\n".encode("cp1252"))
    (work/"ambiguous.csv").write_text("code\n0001\n")
    (work/"broken.csv").write_text('code;name\n0001;"never closed\n')
    (work/"sniff.csv").write_text("code,name;type\n001,Filter\n002,Motor; A\n")
    (work/"header_multiline.csv").write_text('"co\nde";name\n001;Filter\n')
    (work/"casefold.csv").write_text("Straße;Strasse\n001;002\n")
    (work/"mixed_delimiters.csv").write_text("code,name;more;desc\n001,A;B;C\n002,X;Y;Z\n")
    for name, encoding, delimiter in encoding_cases:
        inspections.append({"path": str(work/name), "encoding": encoding, "delimiter": delimiter,
                            "expected": inspect_csv(work/name, encoding, delimiter).model_dump(mode="json")})
    records = [["uno spazio", "tab\t", "a;b", 'a"b', "a\nb", "a\rb", "è", "", "00001"], [""], ["x", ""], ["😀", None]]
    # Python's csv.writer represents None as an empty field.
    record_cases = [{"values": values, "expected": base64.b64encode(encode_record(values)).decode()} for values in records]
    csv_cases = []
    definitions = [
        ("minimal", ["code", "html"], [{"code": f"00{i}", "html": 'é; "immagine"\n' + "dato "*40} for i in range(10)], 1500),
        ("exact", ["code", "x"], [{"code":"1", "x":"x"*7_499_990}, {"code":"2", "x":"x"*7_499_991}], 15_000_000),
        ("above", ["code", "x"], [{"code":"1", "x":"x"*7_499_990}, {"code":"2", "x":"x"*7_499_992}], 15_000_000),
        ("tie", ["a"], [{"a":"x"*8}, {"a":"y"*8}, {"a":"z"*8}], 20),
        ("large_parts", ["a"], [{"a":"x"*100}, {"a":"y"*100}], 50),
        ("oversized", ["a"], [{"a":"x"*100}], 50),
        ("empty", ["a"], [], 15_000_000),
    ]
    for name, headers, rows, threshold in definitions:
        try:
            part = write_csv_parts(rows, headers, work/(name+"-python"), threshold)
            expected = {"sizes":part.sizes, "row_counts":part.row_counts, "diagnostics":[d.model_dump(mode="json") for d in part.diagnostics],
                        "sha256":[hashlib.sha256(Path(p).read_bytes()).hexdigest() for p in part.paths]}
        except DomainError as exc:
            expected = {"error": exc.code}
        # Keep fixtures compact while still producing real 15 MB files in both runtimes.
        compact = [{k: {"repeat":v[0], "count":len(v)} if v and len(v)>1000 and len(set(v))==1 else v for k,v in row.items()} for row in rows]
        csv_cases.append({"name":name, "headers":headers, "rows":compact, "threshold":threshold, "expected":expected})
    return {"work":str(work), "imports":cases, "inspections":inspections, "records":record_cases, "csv":csv_cases,"export_expected":export_expected,"stress_expected":stress_expected}


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--php-command", default=os.environ.get("MW_PHP_COMMAND", "php"), help="Command prefix, e.g. docker exec -i mw-php php")
    args = parser.parse_args()
    with tempfile.TemporaryDirectory(prefix="mw-csv-", dir="/tmp") as directory:
        fixture = fixtures(Path(directory))
        process = subprocess.run([*shlex.split(args.php_command), str(ROOT/"hosting/php/tests/import_export.php")],
                                 input=json.dumps(fixture, ensure_ascii=False), text=True, capture_output=True, cwd=ROOT)
        if process.stdout:
            print(process.stdout, end="")
        if process.stderr:
            print(process.stderr, end="", file=sys.stderr)
        if process.returncode:
            raise SystemExit(process.returncode)
        for zip_path in json.loads(Path(directory,"zip-path.txt").read_text()):
            with zipfile.ZipFile(zip_path) as archive:
                assert archive.testzip() is None, "Streamed ZIP CRC differs from file bytes"
                assert archive.namelist() in [["prodotti.csv","rapporto.csv"],["prodotti_parte_1.csv","prodotti_parte_2.csv","rapporto.csv"]]
                for name in archive.namelist():
                    assert archive.read(name)==Path(zip_path).with_name(name).read_bytes()
        print("Python zipfile: streamed archive names, CRC and artifact bytes verified")


if __name__ == "__main__":
    main()
