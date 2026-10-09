import re
from .domain import Product, Compatibility, PriceSettings, ListingOverrides, ListingDraft, Diagnostic
from .compatibility import listing_identity, validate_compatibilities
from .titles import propose_title, normalize_title, validate_title, validate_prestashop_reference
from .categories.models import CategoryCatalog, CategoryRule
from .categories.matching import resolve_category
from .pricing import calculate_price
from .platforms.profiles import PlatformProfile, EbayProfile, validate_platform_profile
from .platforms.descriptions import render_description
from .exporting.contracts import snapshot_fields
from .errors import DomainError

def compile_drafts(product: Product,fitments: list[Compatibility],price_settings: PriceSettings,platform_profile: PlatformProfile,
    catalog: CategoryCatalog,rules: list[CategoryRule],overrides: dict[str,ListingOverrides]) -> list[ListingDraft]:
    destination=platform_profile.destination
    variants=(fitments or [None]) if destination=='ebay' else [None]
    drafts=[]
    common=product.diagnostics + validate_platform_profile(platform_profile) + validate_compatibilities(fitments)
    for compatibility in variants:
        id=listing_identity(product,compatibility,destination)
        override=overrides.get(id,ListingOverrides(listing_id=id))
        title=normalize_title(override.title if override.title is not None else propose_title(product,compatibility))
        reference=override.reference if override.reference is not None else (id if destination=='ebay' else platform_profile.reference_prefix+product.code)
        diagnostics=[d.model_copy(update={'product_id':product.id,'listing_id':id}) for d in common]
        if destination=='ebay':
            diagnostics.extend(validate_title(title))
        else:
            diagnostics.extend(validate_prestashop_reference(reference))
            if not title or len(title)>128 or re.search(r'[<>;=#{}]',title):
                diagnostics.append(Diagnostic(level='error',code='invalid_product_name',message='Nome PrestaShop: massimo 128 caratteri, senza < > ; = # { }.',hint='Modifica il nome della bozza.',field='title'))
            if product.ean and (not product.ean.isdigit() or len(product.ean) not in (8,13)):
                diagnostics.append(Diagnostic(level='error',code='invalid_ean',message='EAN PrestaShop non valido: 8 o 13 cifre.',hint='Correggi o rimuovi il codice non confermato.',field='ean',value=product.ean))
        decision=resolve_category(product,compatibility,destination,catalog,rules,override.category_id or product.extra.get(destination+'_category_id'))
        diagnostics.extend(decision.diagnostics)
        price=None
        try:
            if product.cost is None:raise DomainError('missing_cost','Imposta il costo di acquisto IVA esclusa.')
            price=calculate_price(product.cost,price_settings,override.gross_price)
            diagnostics.extend(price.diagnostics)
        except DomainError as exc:
            diagnostics.append(Diagnostic(level='error',code=exc.code,message=str(exc),field='cost'))
        html=''
        try:
            html=render_description(product,[compatibility] if compatibility else (fitments if destination=='prestashop' else []),platform_profile,title,reference,price_settings.quantity)
        except DomainError as exc:
            diagnostics.append(Diagnostic(level='error',code=exc.code,message=str(exc),field='image_urls'))
        fields=snapshot_fields(product,compatibility,platform_profile)
        if destination=='ebay':fields['VAT%']=str(price_settings.vat_rate*100)
        drafts.append(ListingDraft(id=id,product_id=product.id,compatibility_id=compatibility.id if compatibility else None,
            destination=destination,reference=reference,title=title,category_ids=decision.category_ids,price=price,quantity=price_settings.quantity,
            description_html=html,fields=fields,diagnostics=diagnostics,valid=not any(d.level=='error' for d in diagnostics)))
    return drafts
