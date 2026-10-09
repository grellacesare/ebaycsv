import csv
import io
from ..domain import Diagnostic

REPORT_COLUMNS=['Riferimento','Codice prodotto','Livello','Errore','Messaggio','File','Riga','Campo','Valore','Correzione']

def import_report_row(d: Diagnostic):
    return ['',d.value if d.field=='code' else '',d.level,d.code,d.message,
        d.source.file if d.source else '',d.source.row if d.source else '',d.field or '',d.value or '',d.hint]

def import_report_bytes(diagnostics: list[Diagnostic]) -> bytes:
    buffer=io.StringIO(newline='')
    writer=csv.writer(buffer,delimiter=';',lineterminator='\r\n')
    writer.writerow(REPORT_COLUMNS)
    writer.writerows(import_report_row(d) for d in diagnostics)
    return buffer.getvalue().encode('utf-8-sig')
