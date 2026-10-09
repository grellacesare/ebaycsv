from pathlib import Path
from fastapi import APIRouter,Request
from fastapi.responses import FileResponse
from ..schemas import ExportRequest,ExportSummary,summarize_export
from ...jobs import Job
from ...errors import DomainError

router=APIRouter()

@router.post('/exports',response_model=Job,status_code=202)
def export(payload: ExportRequest,request: Request):
    ctx=request.app.state.context;ctx.repo.get_import_summary(payload.import_id)
    # A request key identifies submitted intent, not the changing set of ready rows.
    return ctx.jobs.submit('export',payload.model_dump(mode='json',exclude={'request_key'}),payload.request_key)

@router.get('/exports',response_model=list[ExportSummary])
def history(request: Request,import_id: str | None=None):
    return [summarize_export(r) for r in request.app.state.context.repo.list_exports(import_id)]

@router.get('/exports/{id}',response_model=ExportSummary)
def detail(id: str,request: Request):return summarize_export(request.app.state.context.repo.get_export(id))

@router.get('/exports/{id}/files/{name}')
def download(id: str,name: str,request: Request):
    ctx=request.app.state.context;record=ctx.repo.get_export(id)
    artifact=next((a for a in record.artifacts if a.name==name),None)
    if not artifact or Path(name).name!=name:raise KeyError(name)
    path=Path(record.directory)/artifact.name
    if not path.is_file():raise DomainError('missing_export_file','Il file storico non è più presente nella cartella dati.')
    return FileResponse(path,filename=name,media_type='application/zip' if artifact.kind=='zip' else 'text/csv; charset=utf-8')
