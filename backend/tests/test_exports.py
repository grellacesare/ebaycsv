import csv
from pathlib import Path
import pytest
from motorworld_csv.exporting.writer import write_csv_parts
from motorworld_csv.errors import DomainError

def read_parts(parts):
    rows=[]
    for p in parts.paths:
        with Path(p).open(encoding='utf-8-sig',newline='') as f:
            rows.extend(csv.DictReader(f,delimiter=';'))
    return rows

def test_split_preserves_multiline_utf8_records(tmp_path):
    rows=[{'code':f'00{i}','html':'é; "immagine"\n'+('dato '*40)} for i in range(10)]
    parts=write_csv_parts(iter(rows),['code','html'],tmp_path,1500)
    assert len(parts.paths)==2 and all(n<=1500 for n in parts.sizes)
    assert read_parts(parts)==rows

def test_exact_threshold_and_one_byte_above(tmp_path):
    header=11 # BOM plus code;x CRLF
    rows=[{'code':'1','x':'x'*7_499_990},{'code':'2','x':'x'*7_499_991}]
    first=write_csv_parts(rows,['code','x'],tmp_path/'exact')
    assert first.sizes==[15_000_000] and len(first.paths)==1
    rows[1]['x']+='x'
    second=write_csv_parts(rows,['code','x'],tmp_path/'above')
    assert len(second.paths)==2 and max(second.sizes)<=15_000_000

def test_two_parts_over_limit_warn(tmp_path):
    parts=write_csv_parts([{'a':'x'*100},{'a':'y'*100}],['a'],tmp_path,50)
    assert len(parts.paths)==2
    assert any(d.code=='parts_over_limit' for d in parts.diagnostics)

def test_single_oversized_record_is_explained(tmp_path):
    with pytest.raises(DomainError,match='record'):
        write_csv_parts([{'a':'x'*100}],['a'],tmp_path,50)

def test_no_valid_rows_does_not_create_csv(tmp_path):
    with pytest.raises(DomainError):write_csv_parts([],['a'],tmp_path)
