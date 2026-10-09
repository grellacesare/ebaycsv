from hashlib import sha256
from .domain import Product, Compatibility, Diagnostic
from .titles import normalize_title

def validate_compatibilities(rows: list[Compatibility]) -> list[Diagnostic]:
    diagnostics,seen,ids=[],set(),set()
    for row in rows:
        if not row.model.strip():
            diagnostics.append(Diagnostic(level='error',code='missing_model',message='Inserisci il modello compatibile.',field='model'))
        if row.year_from is not None and row.year_to is not None and row.year_from > row.year_to:
            diagnostics.append(Diagnostic(level='error',code='invalid_years',message='L’anno iniziale supera quello finale.',hint='Inverti o correggi gli anni.',field='year_from'))
        key=tuple(normalize_title(s).casefold() for s in (row.make,row.model,row.displacement))+ (row.year_from,row.year_to,row.vehicle_type)
        if key in seen or row.id in ids:
            diagnostics.append(Diagnostic(level='error',code='duplicate_compatibility',message='Modello compatibile duplicato.',hint='Mantieni una sola riga per la stessa compatibilità.',product_id=row.product_id))
        seen.add(key);ids.add(row.id)
    return diagnostics

def listing_identity(product: Product,compatibility: Compatibility | None,destination: str) -> str:
    variant=compatibility.id if destination=='ebay' and compatibility else 'base'
    digest=sha256((product.supplier_id+'\0'+product.code+'\0'+variant).encode()).hexdigest()[:24]
    return ('MW-' if destination=='ebay' else 'PS-')+digest
