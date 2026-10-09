import re
import unicodedata
from .domain import Product, Compatibility, Diagnostic

def normalize_title(title: str) -> str:
    return ' '.join(unicodedata.normalize('NFC',title).split())

def fitment_label(row: Compatibility) -> str:
    years=''
    if row.year_from is not None or row.year_to is not None:
        years=f'{row.year_from or "…"}–{row.year_to or "…"}'
    return normalize_title(' '.join([row.make,row.model,row.displacement,years]))

def propose_title(product: Product,compatibility: Compatibility | None) -> str:
    parts=[product.name]
    if product.brand and product.brand.casefold() not in product.name.casefold():parts.append(product.brand)
    if compatibility:parts.append(fitment_label(compatibility))
    return normalize_title(' '.join(parts))

def validate_title(title: str) -> list[Diagnostic]:
    title=normalize_title(title)
    if not title:
        return [Diagnostic(level='error',code='missing_title',message='Il titolo è obbligatorio.',field='title')]
    if len(title)>80:
        return [Diagnostic(level='error',code='title_too_long',message=f'Titolo di {len(title)} caratteri: massimo 80.',hint='Abbrevia il testo senza perdere modello o codice.',field='title',value=title)]
    if any(unicodedata.category(c)=='Cc' for c in title):
        return [Diagnostic(level='error',code='invalid_title',message='Il titolo contiene caratteri di controllo.',field='title')]
    return []

def validate_prestashop_reference(reference: str) -> list[Diagnostic]:
    if not reference or len(reference)>64 or re.search(r'[<>;={}\x00-\x1f]',reference):
        return [Diagnostic(level='error',code='invalid_reference',message='Riferimento PrestaShop non valido: massimo 64 caratteri, senza < > ; = { }.',hint='Imposta un prefisso/riferimento valido; il codice fornitore resta separato.',field='reference',value=reference)]
    return []
