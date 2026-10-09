import json
from pathlib import Path
from functools import lru_cache
from decimal import Decimal
from typing import Iterable
from pydantic import Field
from ..domain import Model, Product, Compatibility, ListingDraft, Diagnostic
from ..platforms.profiles import PlatformProfile, EbayProfile
from ..titles import fitment_label

RESOURCES=Path(__file__).parents[1]/'resources'
PS_HEADERS=['Active (0/1)','Name','Categories (x,y,z...)','Price tax included','Tax rule ID','Cost price',
    'Reference #','Supplier reference #','Supplier','Brand','Quantity','EAN13','Summary','Description','Image URLs (x,y,z...)']

class CsvParts(Model):
    paths: list[str]
    sizes: list[int]
    row_counts: list[int]
    diagnostics: list[Diagnostic] = Field(default_factory=list)

class Artifact(Model):
    name: str
    size: int
    rows: int | None = None
    kind: str

class ExportRecord(Model):
    id: str
    import_id: str
    destination: str
    included_ids: list[str]
    excluded_ids: list[str]
    snapshot: dict
    artifacts: list[Artifact]
    diagnostics: list[Diagnostic]
    directory: str
    created_at: str

@lru_cache
def csv_columns(destination: str) -> list[str]:
    return json.loads((RESOURCES/'ebay_headers.json').read_text()) if destination=='ebay' else PS_HEADERS.copy()

def monetary(value: Decimal) -> str:
    return f'{value:.{max(2,-value.as_tuple().exponent)}f}'

def snapshot_fields(product: Product,compatibility: Compatibility | None,profile: PlatformProfile) -> dict[str,str]:
    if not isinstance(profile,EbayProfile):
        return {'Active (0/1)':'1','Supplier reference #':product.code,'Supplier':product.supplier_id,
            'Brand':product.brand,'EAN13':product.ean or '','Cost price':monetary(product.cost) if product.cost is not None else '',
            'Image URLs (x,y,z...)':','.join(u.replace(',','%2C') for u in product.image_urls),
            'Tax rule ID':profile.tax_rule_id or '', 'Summary':product.name[:800]}
    fields={csv_columns('ebay')[0]:'Add','Condition ID':profile.condition_id,'Format':'FixedPrice','Duration':'GTC',
        'Location':profile.location,'Returns accepted option':'ReturnsAccepted','Returns within option':f'Days_{profile.return_days}',
        'Return shipping cost paid by':profile.return_shipping_paid_by,'Shipping profile name':profile.shipping_profile,
        'Return profile name':profile.return_profile,'Payment profile name':profile.payment_profile,'StoreCategory':profile.store_category,
        'C:Marca':product.brand,'C:Codice EAN':product.ean or '','Item photo URL':'|'.join(product.image_urls)}
    mapping={'C:Garanzia produttore':'warranty','C:Unita  di misura':'unit','C:MPN':'mpn','C:Codice OEM / Ref':'oem',
        'C:Posizione / Lato':'posizione','C:Colore / Finitura':'colore','C:Misure / Dimensioni':'misure',
        'C:Motore / Normativa':'motore-norm','C:Omologazione / Certificazioni':'omologazione','C:Voltaggio':'voltaggio',
        'C:Peso':'peso','C:Finitura (extra)':'finitura-extra','C:Ricambio moto classica':'ricambio-classica',
        'C:Inserzione bundle':'bundle','C:Destinazione d\'uso':'destinazione-uso','C:Altro codice identificativo':'altro-codice','C:Informazioni Aggiuntive':'info-agg'}
    fields.update({header:product.extra.get(key,'') for header,key in mapping.items()})
    fields.update({header:profile.manufacturer.get(header,'') for header in csv_columns('ebay') if header.startswith('Manufacturer ')})
    if compatibility:
        fields.update({'C:Tipo veicolo':{'moto':'Moto','scooter':'Scooter','other':'Altro','unspecified':''}[compatibility.vehicle_type],
            'C:Marca veicolo':compatibility.make,'C:Modello veicolo':compatibility.model,'C:Cilindrata':compatibility.displacement,
            'C:Anno compatibilità':f'{compatibility.year_from or "…"}–{compatibility.year_to or "…"}' if compatibility.year_from or compatibility.year_to else ''})
    return fields

def draft_to_row(draft: ListingDraft,product: Product,profile: PlatformProfile) -> dict[str,str]:
    if not draft.price:raise ValueError('Prezzo non calcolato')
    fields=draft.fields.copy()
    if draft.destination=='ebay':
        fields.update({'Custom label (SKU)':draft.reference,'Category Id':draft.category_ids[0],
            'Title':draft.title,'Start Price':f'{draft.price.final_gross:.2f}','Quantity':str(draft.quantity),'Description':draft.description_html})
    else:
        fields.update({'Name':draft.title,'Reference #':draft.reference,'Categories (x,y,z...)':','.join(draft.category_ids),
            'Price tax included':f'{draft.price.final_gross:.2f}','Quantity':str(draft.quantity),'Description':draft.description_html})
    return {header:fields.get(header,'') for header in csv_columns(draft.destination)}
