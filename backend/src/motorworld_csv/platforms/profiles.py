from typing import Literal
from pydantic import Field
from ..domain import Model, Diagnostic

class EbayProfile(Model):
    destination: Literal['ebay'] = 'ebay'
    condition_id: str = '1000'
    location: str = '92019'
    shipping_profile: str = 'CORRIERE PREDEFINITA MOTOR WORLD'
    return_profile: str = 'RESTITUZIONE PREDEFINITA MW 60 GIORNI'
    payment_profile: str = 'eBay Managed Payments 1818820adfa4000'
    return_days: Literal[14,30,60] = 60
    return_shipping_paid_by: Literal['Buyer','Seller'] = 'Buyer'
    store_category: str = ''
    logo_url: str = ''
    manufacturer: dict[str,str] = Field(default_factory=dict)
    shipping_text: str = 'Spedizione gratuita. Evadiamo in 24/48h (lavorativi). Corrieri: GLS / BRT / Poste, secondo la destinazione.'
    free_shipping_confirmed: bool = False

class PrestashopProfile(Model):
    destination: Literal['prestashop'] = 'prestashop'
    tax_rule_id: str | None = None
    reference_prefix: str = ''
    free_shipping_confirmed: bool = False

PlatformProfile = EbayProfile | PrestashopProfile

def validate_platform_profile(profile: PlatformProfile) -> list[Diagnostic]:
    diagnostics=[]
    if isinstance(profile,PrestashopProfile):
        if not profile.tax_rule_id or not profile.tax_rule_id.isdigit() or int(profile.tax_rule_id)<=0:
            diagnostics.append(Diagnostic(level='error',code='tax_rule_required',message='Imposta l’ID reale del gruppo regole IVA PrestaShop.',hint='È un ID del negozio, non la percentuale 22%.',field='tax_rule_id'))
    else:
        for field in ('location','shipping_profile','return_profile','payment_profile'):
            if not getattr(profile,field).strip():
                diagnostics.append(Diagnostic(level='error',code='missing_platform_field',message=f'Imposta {field} nel profilo eBay.',field=field))
        if not profile.condition_id.isdigit():
            diagnostics.append(Diagnostic(level='error',code='invalid_condition',message='Usa un ID condizione numerico, ad esempio 1000.',field='condition_id'))
        if profile.store_category and not profile.store_category.isdigit():
            diagnostics.append(Diagnostic(level='error',code='invalid_store_category',message='La categoria del negozio eBay deve essere un ID.',field='store_category'))
        if profile.logo_url:
            from .descriptions import public_https_url
            if not public_https_url(profile.logo_url):
                diagnostics.append(Diagnostic(level='error',code='invalid_logo_url',message='Il logo esportato deve avere un URL pubblico HTTPS.',field='logo_url'))
    if not profile.free_shipping_confirmed:
        diagnostics.append(Diagnostic(level='error',code='shipping_confirmation_required',message='Verifica che il negozio o la policy eBay prevedano spedizione gratuita al cliente.',hint='Il prezzo include il costo, ma il CSV non modifica le tariffe del corriere.',field='free_shipping_confirmed'))
    return diagnostics
