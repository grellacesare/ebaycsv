from pathlib import Path
import json
from hashlib import sha256
from uuid import uuid4
from fastapi import APIRouter, Request, UploadFile
from ..schemas import Destination, SettingsBundle, SettingsUpdate, ValidateRequest, PricePreviewRequest
from ..context import RESOURCES
from ...domain import PriceSettings, PriceResult
from ...pricing import calculate_price
from ...categories.models import CategoryCatalog,CategoryRule,MarketplaceCategory
from ...categories.catalog import import_prestashop_categories,validate_catalog_replacement
from ...jobs import Job
from ...errors import DomainError

router=APIRouter()

@router.post('/pricing/preview',response_model=PriceResult)
def price_preview(payload: PricePreviewRequest):
    return calculate_price(payload.cost,payload.price_settings,payload.override_gross)

@router.get('/settings/{destination}',response_model=SettingsBundle)
def settings(destination: Destination,request: Request):
    repo=request.app.state.context.repo
    try:price=PriceSettings.model_validate(repo.get_record('price_settings',destination))
    except KeyError:price=None
    return SettingsBundle(platform_profile=repo.get_platform_profile(destination),price_settings=price)

@router.put('/settings/{destination}',response_model=SettingsBundle)
def save_settings(destination: Destination,payload: SettingsUpdate,request: Request):
    ctx=request.app.state.context;ctx.ensure_idle()
    if payload.platform_profile.destination!=destination:raise DomainError('wrong_destination','Il profilo deve corrispondere alla destinazione.')
    ctx.repo.save_platform_profile(payload.platform_profile)
    ctx.repo.put_record('price_settings',destination,payload.price_settings.model_dump(mode='json'))
    ctx.repo.mark_stale(destination=destination)
    return payload

@router.get('/categories',response_model=CategoryCatalog)
def categories(request: Request):return request.app.state.context.catalog()

@router.get('/ebay-store-categories',response_model=list[dict[str,str]])
def store_categories():return json.loads((RESOURCES/'ebay_store_categories.json').read_text())

@router.get('/category-rules',response_model=list[CategoryRule])
def rules(request: Request,supplier_id: str):return request.app.state.context.repo.list_category_rules(supplier_id)

@router.put('/category-rules',response_model=CategoryRule)
def save_rule(rule: CategoryRule,request: Request):
    ctx=request.app.state.context;ctx.ensure_idle();catalog=ctx.catalog()
    ids={r.id for r in (catalog.ebay if rule.destination=='ebay' else catalog.prestashop)}
    if rule.category_id not in ids:raise DomainError('unknown_category','Scegli un ID presente nel repertorio.')
    ctx.repo.save_category_rule(rule);ctx.repo.mark_stale(destination=rule.destination)
    return rule

@router.post('/categories/{destination}/replace')
async def replace(destination: Destination,request: Request,file: UploadFile):
    ctx=request.app.state.context;ctx.ensure_idle();data=await file.read()
    directory=ctx.data_dir/'category-imports';directory.mkdir(exist_ok=True)
    path=directory/(str(uuid4())+('.csv' if destination=='prestashop' else '.json'));path.write_bytes(data)
    current=ctx.catalog()
    if destination=='prestashop':updated=current.model_copy(update={'prestashop':import_prestashop_categories(path)})
    else:
        try:
            rows=json.loads(data)
            categories=[]
            for r in rows:
                path=r['path'].casefold()
                context='scooter' if 'scooter' in path else ('moto' if path.startswith('moto:') else 'unknown')
                categories.append(MarketplaceCategory(**(r | {'id':str(r['id']),'context':r.get('context',context)})))
        except (ValueError,KeyError,TypeError):raise DomainError('invalid_category_file','Usa un array JSON con id, path e contesto opzionale.')
        if not categories or len({r.id for r in categories})!=len(categories) or any(not r.id.isdigit() or int(r.id)<=0 for r in categories):raise DomainError('invalid_category_file','ID categoria non numerici, vuoti o duplicati.')
        updated=current.model_copy(update={'ebay':categories})
    updated.version=sha256(data).hexdigest()
    all_rules=[CategoryRule.model_validate(r) for r in ctx.repo.list_records('category_rule')]
    diagnostics=validate_catalog_replacement(current,updated,all_rules)
    ctx.repo.put_record('catalog','active',updated.model_dump(mode='json'));ctx.repo.mark_stale(destination=destination)
    return {'counts':{'ebay':len(updated.ebay),'prestashop':len(updated.prestashop)},'diagnostics':diagnostics}

@router.post('/imports/{id}/validate',response_model=Job,status_code=202)
def validate(id: str,payload: ValidateRequest,request: Request):
    ctx=request.app.state.context;ctx.repo.get_import_summary(id)
    try:price=ctx.repo.get_record('price_settings',payload.destination)
    except KeyError:raise DomainError('price_settings_required','Imposta commissioni, ricarichi, spedizione e quantità nella schermata Prezzi.')
    return ctx.jobs.submit('validate',{'import_id':id,'destination':payload.destination,'price_settings':price,
        'profile':ctx.repo.get_platform_profile(payload.destination).model_dump(mode='json'),'catalog':ctx.catalog().model_dump(mode='json')},payload.request_key)
