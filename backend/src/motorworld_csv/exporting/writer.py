import csv
import io
import shutil
from pathlib import Path
from tempfile import NamedTemporaryFile
from typing import Iterable
from .contracts import CsvParts
from ..errors import DomainError
from ..domain import Diagnostic

def encode_record(values: list[str]) -> bytes:
    handle=io.StringIO(newline='')
    csv.writer(handle,delimiter=';',lineterminator='\r\n').writerow(values)
    return handle.getvalue().encode('utf-8')

def write_csv_parts(rows: Iterable[dict[str,str]],headers: list[str],output_dir: Path,threshold_bytes: int=15_000_000) -> CsvParts:
    output_dir=Path(output_dir);output_dir.mkdir(parents=True,exist_ok=True)
    header=b'\xef\xbb\xbf'+encode_record(headers)
    lengths=[]
    with NamedTemporaryFile(dir=output_dir,prefix='.records-',delete=False) as spool:
        spool_path=Path(spool.name)
        try:
            for row in rows:
                data=encode_record([row.get(h,'') for h in headers]);spool.write(data);lengths.append(len(data))
        except BaseException:
            spool_path.unlink(missing_ok=True);raise
    try:
        if not lengths:raise DomainError('no_valid_rows','Nessuna riga valida selezionata.')
        total=sum(lengths)
        if total+len(header)<=threshold_bytes:
            groups=[(total,len(lengths))]
        else:
            if len(lengths)==1:raise DomainError('unsplittable_record','Un solo record supera 15 MB: riduci la descrizione prima di esportare.')
            partial=0;best=None
            for i,length in enumerate(lengths[:-1],1):
                partial+=length;distance=abs(total-2*partial)
                if best is None or distance<best[0]:best=(distance,i,partial)
            _,cut,first_size=best
            groups=[(first_size,cut),(total-first_size,len(lengths)-cut)]
        paths=[];sizes=[];counts=[]
        with spool_path.open('rb') as source:
            for i,(body_size,count) in enumerate(groups,1):
                target=output_dir/('prodotti.csv' if len(groups)==1 else f'prodotti_parte_{i}.csv')
                pending=target.with_suffix('.csv.tmp')
                with pending.open('wb') as dest:
                    dest.write(header);remaining=body_size
                    while remaining:
                        chunk=source.read(min(remaining,1024*1024));dest.write(chunk);remaining-=len(chunk)
                pending.replace(target)
                paths.append(str(target));sizes.append(target.stat().st_size);counts.append(count)
        diagnostics=[]
        if any(size>threshold_bytes for size in sizes):
            diagnostics.append(Diagnostic(level='warning',code='parts_over_limit',message='Le due parti superano ancora la soglia di 15 MB.',hint='Seleziona meno righe. Non sono create ulteriori parti.'))
        return CsvParts(paths=paths,sizes=sizes,row_counts=counts,diagnostics=diagnostics)
    finally:
        spool_path.unlink(missing_ok=True)
