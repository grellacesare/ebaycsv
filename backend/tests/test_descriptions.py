import pytest
from factories import product,vehicle
from motorworld_csv.platforms.profiles import EbayProfile,PrestashopProfile,validate_platform_profile
from motorworld_csv.platforms.descriptions import render_description
from motorworld_csv.errors import DomainError

def test_each_ebay_description_has_its_own_vehicle():
    p=product();profile=EbayProfile(free_shipping_confirmed=True)
    first=render_description(p,[vehicle()],profile,'Pastiglie Honda','SKU1',1)
    second=render_description(p,[vehicle(model='TMAX',make='Yamaha')],profile,'Pastiglie Yamaha','SKU2',1)
    assert 'CBR 600' in first and 'TMAX' not in first
    assert 'TMAX' in second and 'CBR 600' not in second
    assert 'data:image' not in first and 'Motor World' in first

def test_supplier_text_is_escaped():
    html=render_description(product(name='<script>alert(1)</script>'),[],EbayProfile(),'Titolo','SKU',1)
    assert '&lt;script&gt;' in html and '<script>' not in html
    assert '<form' not in html and 'onclick=' not in html

def test_return_policy_is_consistent():
    html=render_description(product(),[],EbayProfile(return_days=60),'Titolo','SKU',1)
    assert '60 giorni' in html and '30 giorni' not in html

def test_tax_rule_id_is_required():
    assert any(d.code=='tax_rule_required' for d in validate_platform_profile(PrestashopProfile()))

def test_prestashop_has_all_models_without_ebay_layout():
    html=render_description(product(),[vehicle(),vehicle(id='2',model='CB 500')],PrestashopProfile(tax_rule_id='1'),'Titolo','00123',1)
    assert 'CBR 600' in html and 'CB 500' in html and 'mw-wrap' not in html

def test_public_images_and_optional_fields():
    html=render_description(product(image_urls=['https://example.com/image.jpg']),[],EbayProfile(),'Titolo','SKU',1)
    assert 'https://example.com/image.jpg' in html
    assert 'Codice OEM / Ref' not in html and 'Compatibilita / Fitment' not in html
    with pytest.raises(DomainError):
        render_description(product(image_urls=['http://localhost/a.jpg']),[],EbayProfile(),'Titolo','SKU',1)
