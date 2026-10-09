import csv
import re
import unicodedata
from pathlib import Path
from .models import CsvInspection, FileBinding
from ..domain import Diagnostic, SourceRef
from ..errors import DomainError

csv.field_size_limit(100_000_000)

ALIASES = {
    'code': ['codiceprodotto','codice','code','sku','reference'],
    'name': ['descrizioneita','descrizione','name','nome','title','titolo'],
    'brand': ['brand','marca','manufacturer'],
    'ean': ['ean','barcode','gtin'],
    'family': ['categoriaita','categoria','category','famiglia'],
    'department': ['compartoita','comparto','department'],
    'image_urls': ['foto','imageurls','immagini','images','image'],
    'available': ['disponibile','available'],
    'quantity': ['quantitaconfezione','packquantity'],
    'gross_weight': ['pesolordo','grossweight','weight'],
    'volume': ['volume'],
}

def normalized(value: str) -> str:
    return re.sub(r'[^a-z0-9]', '', unicodedata.normalize('NFKD', value.casefold()))

def propose_mapping(columns: list[str]) -> dict[str,str]:
    result = {}
    for field, aliases in ALIASES.items():
        matched = [c for c in columns if normalized(c) in aliases]
        if len(matched) == 1:
            result[field] = matched[0]
    return result

def text_encoding(encoding: str) -> str:
    return 'utf-8-sig' if encoding.lower().replace('_','-') in ('utf-8','utf-8-sig') else encoding

def inspect_csv(path: Path, encoding: str | None = None, delimiter: str | None = None) -> CsvInspection:
    result = CsvInspection(encoding=encoding or 'utf-8')
    try:
        with path.open(encoding=text_encoding(result.encoding), newline='') as handle:
            sample = handle.read(8192)
            if delimiter is None:
                try:
                    delimiter = csv.Sniffer().sniff(sample, delimiters=';,\t|').delimiter
                except csv.Error:
                    first = sample.splitlines()[0] if sample else ''
                    candidates = [(len(next(csv.reader([first],delimiter=d))),d) for d in ';,\t|']
                    candidates.sort(reverse=True)
                    if candidates[0][0] <= 1 or candidates[0][0] == candidates[1][0]:
                        result.diagnostics.append(Diagnostic(level='error',code='delimiter_required',message='Scegli il delimitatore del CSV.',hint='Controlla l’anteprima con ;, virgola, tab o |.'))
                        return result
                    delimiter = candidates[0][1]
            if len(delimiter) != 1:
                raise DomainError('invalid_delimiter','Il delimitatore deve avere un solo carattere.')
            result.delimiter = delimiter
            handle.seek(0)
            reader = csv.DictReader(handle,delimiter=delimiter,strict=True)
            result.columns = reader.fieldnames or []
            if not result.columns or len(set(normalized(c) for c in result.columns)) != len(result.columns):
                result.diagnostics.append(Diagnostic(level='error',code='duplicate_headers',message='Intestazioni vuote o duplicate.',hint='Correggi la prima riga del file.'))
                return result
            for row in reader:
                result.record_count += 1
                if None in row or any(v is None for v in row.values()):
                    result.diagnostics.append(Diagnostic(level='warning',code='malformed_row',message='Numero colonne diverso dall’intestazione.',source=SourceRef(file=path.name,row=reader.line_num),hint='Correggi delimitatori e virgolette.'))
                if len(result.preview) < 5:
                    result.preview.append({k:v or '' for k,v in row.items() if k is not None})
            result.proposed_map = propose_mapping(result.columns)
    except (UnicodeError, LookupError):
        result.encoding = None
        result.diagnostics.append(Diagnostic(level='error',code='encoding_required',message='La codifica del file deve essere scelta.',hint='Prova Windows-1252 o UTF-8; controlla gli accenti.'))
    except csv.Error as exc:
        result.diagnostics.append(Diagnostic(level='error',code='invalid_csv',message=f'CSV non leggibile: {exc}',hint='Correggi virgolette e delimitatori del file.'))
    return result

def iter_rows(path: Path, binding: FileBinding):
    with path.open(encoding=text_encoding(binding.encoding),newline='') as handle:
        reader = csv.DictReader(handle,delimiter=binding.delimiter,strict=True)
        _ = reader.fieldnames
        previous = reader.line_num
        for values in reader:
            source = SourceRef(file=path.name,row=previous+1,columns={k:v or '' for k,v in values.items() if k is not None})
            previous = reader.line_num
            yield source, values
