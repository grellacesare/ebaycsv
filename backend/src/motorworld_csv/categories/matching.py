import re
import unicodedata
from .models import CategoryCatalog, CategoryRule, CategoryDecision
from ..domain import Product, Compatibility, Diagnostic

def normalize_key(value: str) -> str:
    return re.sub(r'[^a-z0-9]','',unicodedata.normalize('NFKD',value.casefold()))

def resolve_category(product: Product,compatibility: Compatibility | None,destination: str,catalog: CategoryCatalog,rules: list[CategoryRule],override: str | None = None) -> CategoryDecision:
    context=compatibility.vehicle_type if compatibility else product.extra.get('vehicle_type','unspecified')
    family=normalize_key(product.family)
    entries={r.id:r for r in (catalog.ebay if destination=='ebay' else catalog.prestashop)}
    def unresolved(message,code='unresolved_category'):
        return CategoryDecision(diagnostics=[Diagnostic(level='error',code=code,message=message,hint='Seleziona una categoria e salva l’associazione per famiglia e contesto.',product_id=product.id,field='category')])
    def selected(ids,method):
        if len(ids)!=1:return unresolved('Più categorie possibili: occorre una scelta esplicita.','ambiguous_category')
        id=next(iter(ids))
        if id not in entries:return unresolved('La categoria scelta non è presente nel repertorio.','unknown_category')
        entry=entries[id]
        if destination=='ebay' and context in ('moto','scooter') and entry.context in ('moto','scooter') and entry.context!=context:
            return unresolved('Categoria incompatibile con il tipo di veicolo della variante.','category_context_mismatch')
        diagnostics=[]
        if destination=='prestashop' and not entry.shown:
            diagnostics.append(Diagnostic(level='warning',code='hidden_store_category',message='Questa categoria del negozio è nascosta.',hint='Verifica che debba essere utilizzata.',product_id=product.id))
        return CategoryDecision(category_ids=[id],method=method,reason='Corrispondenza verificata nel repertorio fornito.',diagnostics=diagnostics)
    if override:return selected({override},'explicit')
    relevant=[r for r in rules if r.supplier_id==product.supplier_id and normalize_key(r.family)==family and r.destination==destination]
    scoped=[r for r in relevant if r.context==context]
    saved={r.category_id for r in (scoped or [r for r in relevant if r.context=='unspecified'])}
    if saved:return selected(saved,'saved_rule')
    if destination=='prestashop':
        values={normalize_key(product.family),normalize_key(product.department)}- {''}
        ids={r.id for r in catalog.prestashop if normalize_key(r.name) in values}
        return selected(ids,'recognized') if ids else unresolved('Categoria del negozio non associata.')
    allowed={'moto','scooter','shared'} if context=='unspecified' else {context,'shared'}
    ids={r.id for r in catalog.ebay if r.context in allowed and normalize_key(r.path)==family}
    if not ids and context in ('moto','scooter'):
        ids={r.id for r in catalog.ebay if r.context in allowed and normalize_key(r.path.rsplit('>',1)[-1])==family}
    if not ids and context in ('moto','scooter'):
        ids={r['category_id'] for r in catalog.seed_rules if r['destination']=='ebay' and normalize_key(r['family'])==family and r['context'] in allowed and r['category_id'] in entries and entries[r['category_id']].context in allowed}
    if ids:return selected(ids,'recognized')
    return unresolved('Nessun riconoscimento univoco Moto/Scooter per questa famiglia e contesto.')
