from pathlib import Path
from contextlib import asynccontextmanager
import os
from fastapi import FastAPI, Request
from fastapi.responses import JSONResponse, FileResponse
from fastapi.staticfiles import StaticFiles
from fastapi.exceptions import RequestValidationError
from .context import AppContext, RESOURCES
from .routes import imports,catalog,settings,exports,jobs
from ..errors import DomainError

def create_app(data_dir: Path | None=None) -> FastAPI:
    root=Path(__file__).resolve().parents[4]
    context=AppContext(Path(data_dir or os.environ.get('MW_DATA_DIR',root/'var')).resolve())
    @asynccontextmanager
    async def lifespan(app):
        context.start()
        yield
        context.jobs.close()
    app=FastAPI(title='Motor World CSV',version='0.1.0',lifespan=lifespan)
    app.state.context=context
    @app.exception_handler(DomainError)
    async def domain_error(request,exc):
        return JSONResponse(status_code=409 if exc.code in ('job_busy','request_key_conflict') else 422,content={'code':exc.code,'message':str(exc)})
    @app.exception_handler(KeyError)
    async def not_found(request,exc):
        return JSONResponse(status_code=404,content={'code':'not_found','message':'Elemento non trovato nell’archivio.'})
    @app.exception_handler(RequestValidationError)
    async def validation_error(request,exc):
        return JSONResponse(status_code=422,content={'code':'invalid_input','message':'Correggi i campi indicati.',
            'diagnostics':[{'field':'.'.join(str(x) for x in e['loc'][1:]),'message':e['msg']} for e in exc.errors()]})
    for router in (imports.router,catalog.router,settings.router,exports.router,jobs.router):app.include_router(router,prefix='/api')
    @app.get('/api/health')
    def health():return {'status':'ok','schema_version':2,'version':'0.1.0'}
    @app.get('/api/brand/logo',include_in_schema=False)
    def brand():return FileResponse(RESOURCES/'motorworld-logo.png',media_type='image/png')
    dist=root/'frontend'/'dist'
    if (dist/'assets').is_dir():app.mount('/assets',StaticFiles(directory=dist/'assets'),name='assets')
    @app.get('/{path:path}',include_in_schema=False)
    def frontend(path: str):
        if path.startswith('api/') or not (dist/'index.html').is_file():
            return JSONResponse(status_code=404,content={'message':'Avvia il frontend oppure esegui npm run build.'})
        return FileResponse(dist/'index.html',media_type='text/html')
    return app
