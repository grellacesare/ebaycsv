from pathlib import Path
from uuid import uuid4
from fastapi import APIRouter, Request, UploadFile, Query
from fastapi.responses import FileResponse, Response
from ..schemas import UploadResult, UploadInfo, ImportRequest, ImportSummary, StoredUpload
from ...importing.models import ImportProfile, CsvInspection
from ...importing.csv_reader import inspect_csv
from ...jobs import Job
from ...errors import DomainError
from ...importing.reporting import import_report_bytes

router=APIRouter()

@router.get('/uploads',response_model=list[StoredUpload])
def stored_uploads(request: Request):return request.app.state.context.repo.list_records('upload')[:30]

@router.get('/uploads/{id}/original')
def original(id: str,request: Request):
    ctx=request.app.state.context;meta=ctx.repo.get_record('upload',id)
    return FileResponse(ctx.data_dir/'uploads'/(id+'.csv'),filename=meta['name'],media_type='text/csv')

@router.post('/uploads',response_model=UploadResult)
async def upload(request: Request,files: list[UploadFile]):
    ctx=request.app.state.context;directory=ctx.data_dir/'uploads';directory.mkdir(parents=True,exist_ok=True)
    infos=[]
    for file in files:
        id=str(uuid4());name=(file.filename or 'fornitore.csv').replace('\\','/').rsplit('/',1)[-1]
        path=directory/(id+'.csv');size=0
        with path.open('wb') as handle:
            while chunk:=await file.read(1024*1024):handle.write(chunk);size+=len(chunk)
        ctx.repo.put_record('upload',id,{'id':id,'name':name,'size':size})
        view=inspect_csv(path)
        infos.append(UploadInfo(id=id,name=name,size=size,inspection=view))
    return UploadResult(uploads=infos)

@router.get('/uploads/{id}/inspect',response_model=CsvInspection)
def inspect(id: str,request: Request,encoding: str | None=None,delimiter: str | None=None):
    ctx=request.app.state.context;ctx.repo.get_record('upload',id)
    return inspect_csv(ctx.data_dir/'uploads'/(id+'.csv'),encoding,delimiter)

@router.get('/supplier-profiles',response_model=list[ImportProfile])
def profiles(request: Request):return request.app.state.context.repo.list_records('supplier_profile')

@router.put('/supplier-profiles/{id}',response_model=ImportProfile)
def save_profile(id: str,profile: ImportProfile,request: Request):
    if id!=profile.id:raise DomainError('profile_id_mismatch','L’ID del profilo non corrisponde.')
    request.app.state.context.repo.save_profile(profile);return profile

@router.post('/imports',response_model=Job,status_code=202)
def start_import(payload: ImportRequest,request: Request):
    ctx=request.app.state.context
    profile=ctx.repo.get_profile(payload.profile_id)
    for item in payload.uploads:ctx.repo.get_record('upload',item.upload_id)
    return ctx.jobs.submit('import',{'profile':profile.model_dump(mode='json'),'uploads':[r.model_dump() for r in payload.uploads]},payload.request_key)

@router.get('/imports',response_model=list[ImportSummary])
def imports(request: Request):
    ctx=request.app.state.context
    return [ImportSummary.model_validate(r) for r in ctx.repo.list_records('import_summary')]

@router.get('/imports/{id}',response_model=ImportSummary)
def detail(id: str,request: Request):return request.app.state.context.import_summary(id)

@router.get('/imports/{id}/report')
def report(id: str,request: Request):
    summary=request.app.state.context.import_summary(id)
    return Response(import_report_bytes(summary.diagnostics),media_type='text/csv; charset=utf-8',
        headers={'Content-Disposition':'attachment; filename="rapporto-importazione.csv"'})
