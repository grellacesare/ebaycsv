from fastapi import APIRouter,Request
from ...jobs import Job

router=APIRouter()

@router.get('/jobs',response_model=list[Job])
def jobs(request: Request):return request.app.state.context.repo.list_jobs()[-20:][::-1]

@router.get('/jobs/{id}',response_model=Job)
def job(id: str,request: Request):return request.app.state.context.jobs.get(id)

@router.post('/jobs/{id}/resume',response_model=Job,status_code=202)
def resume(id: str,request: Request):return request.app.state.context.jobs.resume(id)
