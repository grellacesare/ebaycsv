import csv
from pathlib import Path
from datetime import datetime, timezone
from zipfile import ZipFile, ZIP_DEFLATED
from .contracts import ExportRecord, Artifact, csv_columns, draft_to_row
from .writer import write_csv_parts
from ..domain import Progress, ProgressCallback, Diagnostic
from ..storage import Repository
from ..platforms.profiles import EbayProfile, PrestashopProfile
from ..errors import DomainError

def export_selected(repo: Repository,import_id: str,destination: str,draft_ids: list[str],output_dir: Path,progress: ProgressCallback) -> ExportRecord:
    output_dir=Path(output_dir)
    try:return repo.get_export(output_dir.name)
    except KeyError:pass
    selected=list(dict.fromkeys(draft_ids))
    included,excluded,summaries,report=[],[],[],[]
    try:
        settings=repo.get_record('validation',import_id+':'+destination)
        profile=(EbayProfile if destination=='ebay' else PrestashopProfile).model_validate(settings['profile'])
    except KeyError:
        raise DomainError('validation_required','Controlla prima il lotto con le impostazioni attuali.')
    output_dir.mkdir(parents=True,exist_ok=True)
    def rows():
        for i,id in enumerate(selected,1):
            draft=repo.get_draft(id,import_id)
            if draft.destination!=destination:raise DomainError('wrong_destination','La selezione contiene righe di un’altra destinazione.')
            for d in draft.diagnostics:
                report.append((draft,d))
            if not draft.valid or draft.stale:
                excluded.append(id)
                if draft.stale:report.append((draft,Diagnostic(level='error',code='stale_draft',message='Dati modificati: ricontrolla il lotto.')))
            else:
                if draft.exported:report.append((draft,Diagnostic(level='warning',code='previously_exported',message='Riga già inclusa in un precedente file: non importare due volte lo stesso prodotto.')))
                included.append(id)
                summaries.append({'id':id,'product_id':draft.product_id,'reference':draft.reference,'title':draft.title,'price':str(draft.price.final_gross),'category_ids':draft.category_ids})
                yield draft_to_row(draft,repo.get_product(draft.product_id),profile)
            if i%100==0 or i==len(selected):progress(Progress(stage='Generazione CSV',completed=i,total=len(selected)))
    parts=write_csv_parts(rows(),csv_columns(destination),output_dir)
    report_path=output_dir/'rapporto.csv'
    with report_path.open('w',encoding='utf-8-sig',newline='') as handle:
        writer=csv.writer(handle,delimiter=';',lineterminator='\r\n')
        writer.writerow(['Riferimento','Codice prodotto','Livello','Errore','Messaggio','File','Riga','Campo','Valore','Correzione'])
        for draft,d in report:
            writer.writerow([draft.reference,repo.get_product(draft.product_id).code,d.level,d.code,d.message,
                d.source.file if d.source else '',d.source.row if d.source else '',d.field or '',d.value or '',d.hint])
        for d in parts.diagnostics:writer.writerow(['','',d.level,d.code,d.message,'','','','',d.hint])
    paths=[Path(p) for p in parts.paths]+[report_path]
    zip_path=output_dir/'esportazione.zip'
    with ZipFile(zip_path.with_suffix('.zip.tmp'),'w',ZIP_DEFLATED) as z:
        for p in paths:z.write(p,arcname=p.name)
    zip_path.with_suffix('.zip.tmp').replace(zip_path)
    artifacts=[Artifact(name=Path(p).name,size=size,rows=count,kind='csv') for p,size,count in zip(parts.paths,parts.sizes,parts.row_counts)]
    artifacts.extend([Artifact(name=report_path.name,size=report_path.stat().st_size,kind='report'),Artifact(name=zip_path.name,size=zip_path.stat().st_size,kind='zip')])
    record=ExportRecord(id=output_dir.name,import_id=import_id,destination=destination,included_ids=included,excluded_ids=excluded,
        snapshot={'profile':profile.model_dump(mode='json'),'rows':summaries},artifacts=artifacts,diagnostics=parts.diagnostics,
        directory=str(output_dir),created_at=datetime.now(timezone.utc).isoformat())
    repo.record_export(record)
    return record
