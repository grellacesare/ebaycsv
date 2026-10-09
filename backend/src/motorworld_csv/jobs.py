from pathlib import Path
from typing import Literal, Callable
from datetime import datetime, timezone
from queue import Queue
from threading import Event, Lock, Thread
from uuid import uuid4
from pydantic import Field
from .domain import Model, Progress
from .errors import DomainError

JobHandler = Callable[[dict, Callable[[Progress], None]], dict]

class Job(Model):
    id: str
    kind: str
    payload: dict
    request_key: str
    state: Literal['queued','running','completed','failed','interrupted'] = 'queued'
    progress: Progress = Field(default_factory=lambda: Progress(stage='In coda',completed=0,total=1))
    result: dict | None = None
    error: str | None = None
    created_at: str = ''
    updated_at: str = ''
    attempts: int = 0

class JobService:
    def __init__(self,repo,data_dir: Path):
        self.repo,self.data_dir=repo,Path(data_dir)
        self.handlers: dict[str,JobHandler]={}
        self.queue=Queue()
        self.stop=Event()
        self.lock=Lock()
        self.scheduled=set()
        for job in self.repo.list_jobs(['running']):
            job.state='interrupted';job.error='Il processo è stato interrotto. Puoi riprendere la lavorazione.'
            self.repo.save_job(job)
        self.worker=Thread(target=self._work,daemon=True,name='motorworld-jobs')
        self.worker.start()

    def register(self,kind: str,handler: JobHandler):
        self.handlers[kind]=handler
        for job in self.repo.list_jobs(['queued']):
            if job.kind==kind:self._schedule(job.id)

    def _schedule(self,id):
        with self.lock:
            if id not in self.scheduled:
                self.scheduled.add(id);self.queue.put(id)

    def submit(self,kind: str,payload: dict,request_key: str) -> Job:
        if kind not in self.handlers:
            raise DomainError('unknown_job','Tipo di lavorazione non disponibile.')
        now=datetime.now(timezone.utc).isoformat()
        candidate=Job(id=str(uuid4()),kind=kind,payload=payload,request_key=request_key,created_at=now,updated_at=now)
        job=self.repo.create_job(candidate)
        if job.kind!=kind or job.payload!=payload:
            raise DomainError('request_key_conflict','La chiave della richiesta è già utilizzata con dati diversi.')
        if job.state=='queued':self._schedule(job.id)
        return job

    def get(self,id: str) -> Job:
        return self.repo.get_job(id)

    def resume(self,id: str) -> Job:
        job=self.get(id)
        if job.state not in ('failed','interrupted'):
            raise DomainError('job_not_resumable','Questo lavoro non richiede una ripresa.')
        job.state='queued';job.error=None;self.repo.save_job(job)
        self._schedule(id)
        return job

    def _work(self):
        while not self.stop.is_set():
            id=self.queue.get()
            if id is None:break
            job=self.get(id)
            if job.state!='queued':
                with self.lock:self.scheduled.discard(id)
                continue
            job.state='running';job.attempts+=1;self.repo.save_job(job)
            def progress(value):
                if self.stop.is_set():raise InterruptedError('Lavorazione interrotta durante la chiusura.')
                job.progress=value;job.updated_at=datetime.now(timezone.utc).isoformat();self.repo.save_job(job)
            try:
                job.result=self.handlers[job.kind](job.payload | {'_job_id':job.id},progress)
                job.state='completed';job.error=None
            except InterruptedError as exc:
                job.state='interrupted';job.error=str(exc)
            except Exception as exc:
                job.state='failed';job.error=str(exc)
            job.updated_at=datetime.now(timezone.utc).isoformat()
            self.repo.save_job(job)
            with self.lock:self.scheduled.discard(id)

    def close(self) -> None:
        self.stop.set();self.queue.put(None);self.worker.join(timeout=10)
