from threading import Event
import time
from motorworld_csv.storage import Repository
from motorworld_csv.jobs import JobService, Job
from motorworld_csv.domain import Progress

def await_job(service,id):
    for _ in range(100):
        job=service.get(id)
        if job.state in ('completed','failed'):return job
        time.sleep(.01)
    raise AssertionError('Lavoro non completato')

def test_same_request_key_returns_same_job(tmp_path):
    repo=Repository(tmp_path/'a.sqlite');service=JobService(repo,tmp_path)
    service.register('export',lambda payload,progress:{'value':payload['n']})
    first=service.submit('export',{'n':3},'request-1')
    duplicate=service.submit('export',{'n':3},'request-1')
    assert first.id==duplicate.id
    assert await_job(service,first.id).result=={'value':3}
    service.close()

def test_interrupted_job_can_resume(tmp_path):
    repo=Repository(tmp_path/'a.sqlite')
    repo.save_job(Job(id='job-1',kind='test',payload={'n':3},request_key='key',state='running'))
    service=JobService(repo,tmp_path)
    assert service.get('job-1').state=='interrupted'
    service.register('test',lambda payload,progress:{'n':payload['n']})
    service.resume('job-1')
    assert await_job(service,'job-1').state=='completed'
    service.close()

def test_progress_is_persisted(tmp_path):
    repo=Repository(tmp_path/'a.sqlite');service=JobService(repo,tmp_path)
    def handler(payload,progress):
        progress(Progress(stage='Lettura',completed=5,total=5))
        return {'done':True}
    service.register('read',handler)
    j=service.submit('read',{},'key')
    assert await_job(service,j.id).progress.completed==5
    assert Repository(tmp_path/'a.sqlite').get_job(j.id).progress.stage=='Lettura'
    service.close()
