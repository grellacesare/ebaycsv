from fastapi import APIRouter, Request, Query
from ..schemas import ProductUpdate,CompatibilityUpdate,Destination
from ...domain import Product,Compatibility,ListingOverrides
from ...storage import ProductFilters,ProductPage,DraftPage
from ...compatibility import validate_compatibilities
from ...errors import DomainError

router=APIRouter()

@router.get('/products',response_model=ProductPage)
def products(request: Request,import_id: str | None=None,query: str='',offset: int=Query(0,ge=0),limit: int=Query(50,ge=1,le=200),
    available: bool | None=None,category_id: str | None=None,state: str | None=None,destination: Destination | None=None):
    return request.app.state.context.repo.list_products(import_id,ProductFilters(query=query,available=available,category_id=category_id,state=state,destination=destination),offset,limit)

@router.get('/products/{id}',response_model=Product)
def product(id: str,request: Request):return request.app.state.context.repo.get_product(id)

@router.patch('/products/{id}',response_model=Product)
def edit(id: str,payload: ProductUpdate,request: Request):
    ctx=request.app.state.context;ctx.ensure_idle()
    product=ctx.repo.get_product(id);updates=payload.updates.model_dump(exclude_unset=True)
    if updates.get('cost') is not None and (not updates['cost'].is_finite() or updates['cost']<0):
        raise DomainError('invalid_cost','Il costo IVA esclusa deve essere finito e non negativo.')
    if 'extra' in updates:updates['extra']=product.extra | (updates['extra'] or {})
    fixed=set(payload.resolve_fields)&set(updates)
    updates['diagnostics']=[d for d in product.diagnostics if d.field not in fixed]
    product=Product.model_validate(product.model_dump() | updates)
    ctx.repo.save_product(product,resolved_fields=list(fixed));ctx.repo.mark_stale(product_id=id)
    return product

@router.post('/products/{id}/reset-corrections',response_model=Product)
def reset_corrections(id: str,request: Request):
    ctx=request.app.state.context;ctx.ensure_idle();ctx.repo.get_product(id)
    return ctx.repo.reset_product_corrections(id)

@router.get('/products/{id}/compatibilities',response_model=list[Compatibility])
def fitments(id: str,request: Request):return request.app.state.context.repo.get_compatibilities(id)

@router.put('/products/{id}/compatibilities',response_model=list[Compatibility])
def save_fitments(id: str,payload: CompatibilityUpdate,request: Request):
    ctx=request.app.state.context;ctx.ensure_idle();ctx.repo.get_product(id)
    rows=[r.model_copy(update={'product_id':id}) for r in payload.rows]
    errors=validate_compatibilities(rows)
    if errors:raise DomainError(errors[0].code,errors[0].message)
    ctx.repo.save_compatibilities(id,rows);ctx.repo.mark_stale(product_id=id)
    return rows

@router.put('/listing-overrides/{id}',response_model=ListingOverrides)
def override(id: str,payload: ListingOverrides,request: Request):
    ctx=request.app.state.context;ctx.ensure_idle()
    if id!=payload.listing_id:raise DomainError('listing_id_mismatch','La bozza non corrisponde.')
    draft=ctx.repo.get_draft(id)
    ctx.repo.save_overrides([payload]);ctx.repo.mark_stale(product_id=draft.product_id,destination=draft.destination)
    return payload

@router.get('/listing-overrides/{id}',response_model=ListingOverrides)
def get_override(id: str,request: Request):
    repo=request.app.state.context.repo;draft=repo.get_draft(id)
    return repo.get_overrides(draft.product_id).get(id,ListingOverrides(listing_id=id))

@router.get('/imports/{id}/drafts',response_model=DraftPage)
def drafts(id: str,request: Request,destination: Destination,state: str | None=None,offset: int=Query(0,ge=0),limit: int=Query(50,ge=1,le=200),
    query: str='',category_id: str | None=None,available: bool | None=None):
    return request.app.state.context.repo.list_drafts(id,destination,state,offset,limit,query,category_id,available)
