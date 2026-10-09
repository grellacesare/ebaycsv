from pathlib import Path
from functools import lru_cache
from urllib.parse import urlsplit
import ipaddress
from jinja2 import Environment, FileSystemLoader
from markupsafe import Markup, escape
from .profiles import PlatformProfile, EbayProfile
from ..domain import Product, Compatibility
from ..errors import DomainError
from ..titles import fitment_label

RESOURCES=Path(__file__).parents[1]/'resources'

def public_https_url(value: str) -> bool:
    try:
        u=urlsplit(value);host=u.hostname
        if u.scheme!='https' or not host or u.username or u.password or u.port not in (None,443):return False
        if host=='localhost' or host.endswith(('.localhost','.local','.internal','.invalid')):return False
        try:return ipaddress.ip_address(host).is_global
        except ValueError:return '.' in host
    except ValueError:return False

@lru_cache
def environment():
    env=Environment(loader=FileSystemLoader(str(RESOURCES)),autoescape=True)
    env.filters['nl2br']=lambda v: Markup('<br/>').join(escape(v).split('\n'))
    return env

def render_description(product: Product,fitments: list[Compatibility],profile: PlatformProfile,title: str,reference: str,quantity: int) -> str:
    images=list(product.image_urls)
    if any(not public_https_url(u) for u in images):
        raise DomainError('invalid_image_url','Correggi le immagini: servono URL pubblici HTTPS.')
    labels=[fitment_label(row) for row in fitments]
    description=product.extra.get('description',product.name)
    if not isinstance(profile,EbayProfile):
        return environment().from_string('<h2>{{ title }}</h2><p>{{ description | nl2br }}</p>{% if labels %}<h3>Modelli compatibili</h3><ul>{% for item in labels %}<li>{{ item }}</li>{% endfor %}</ul>{% endif %}').render(title=title,description=description,labels=labels)
    if len(fitments)>1:
        raise DomainError('invalid_fitment_scope','Ogni descrizione eBay deve corrispondere a una sola compatibilità.')
    if profile.logo_url and not public_https_url(profile.logo_url):
        raise DomainError('invalid_logo_url','Configura un logo pubblico HTTPS.')
    details={key:product.extra.get(key,'') for key in ('modello','materiale','specifica','oem','mpn','posizione','colore','misure','motore-norm','omologazione','voltaggio','peso','finitura-extra','ricambio-classica','bundle','destinazione-uso','altro-codice','info-agg')}
    details.update(marca=product.brand,ean=product.ean or '',qty=str(quantity),condizione={'1000':'Nuovo','3000':'Usato'}.get(profile.condition_id,profile.condition_id))
    details.update({'tipo-veicolo':'','marca-veicolo':'','modello-veicolo':'','anno-compat':'','cilindrata':''})
    if fitments:
        row=fitments[0]
        details.update({'tipo-veicolo':{'moto':'Moto','scooter':'Scooter','other':'Altro','unspecified':''}[row.vehicle_type],
            'marca-veicolo':row.make,'modello-veicolo':row.model,'cilindrata':row.displacement,
            'anno-compat':f'{row.year_from or "…"}–{row.year_to or "…"}' if row.year_from or row.year_to else ''})
    return environment().get_template('ebay_description.html.j2').render(product=product,profile=profile,title=title,reference=reference,details=details,
        image_urls=images,description=description,fitment_labels=labels,features=product.extra.get('features','').splitlines(),
        box=product.extra.get('box','').splitlines(),notes=product.extra.get('notes',''),css=(RESOURCES/'ebay_template.css').read_text())
