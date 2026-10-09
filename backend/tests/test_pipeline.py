from pathlib import Path
from decimal import Decimal as D
from factories import product,vehicle,price_settings
from motorworld_csv.domain import ListingOverrides
from motorworld_csv.compatibility import listing_identity
from motorworld_csv.categories.catalog import load_catalog
from motorworld_csv.pipeline import compile_drafts
from motorworld_csv.platforms.profiles import EbayProfile,PrestashopProfile
from motorworld_csv.exporting.contracts import draft_to_row,csv_columns

def catalog():return load_catalog(Path(__file__).parents[1]/'src/motorworld_csv/resources')

def test_ebay_expands_and_prestashop_does_not():
    p=product();fits=[vehicle(),vehicle(id='second',model='CB 500')]
    eb=compile_drafts(p,fits,price_settings(),EbayProfile(free_shipping_confirmed=True),catalog(),[],{})
    ps=compile_drafts(product(department='Impianto Frenante'),fits,price_settings(),PrestashopProfile(tax_rule_id='1',free_shipping_confirmed=True),catalog(),[],{})
    assert len(eb)==2 and len(ps)==1
    assert all(r.valid for r in eb+ps)
    assert 'CBR 600' in ps[0].description_html and 'CB 500' in ps[0].description_html

def test_only_invalid_variant_is_excluded():
    p=product();fits=[vehicle(),vehicle(id='second',model='CB 500')]
    id=listing_identity(p,fits[1],'ebay')
    rows=compile_drafts(p,fits,price_settings(),EbayProfile(free_shipping_confirmed=True),catalog(),[],{id:ListingOverrides(listing_id=id,title='X'*81)})
    assert rows[0].valid and not rows[1].valid

def test_price_warning_does_not_raise_price():
    p=product();fit=vehicle();id=listing_identity(p,fit,'ebay')
    row=compile_drafts(p,[fit],price_settings(),EbayProfile(free_shipping_confirmed=True),catalog(),[],{id:ListingOverrides(listing_id=id,gross_price='188.90')})[0]
    assert row.valid and row.price.final_gross==D('188.90') and row.price.below_minimum

def test_target_contracts_and_net_cost():
    p=product(department='Impianto Frenante')
    profile=PrestashopProfile(tax_rule_id='1',free_shipping_confirmed=True)
    id=listing_identity(p,None,'prestashop')
    draft=compile_drafts(p,[],price_settings(quantity=7),profile,catalog(),[],{id:ListingOverrides(listing_id=id,gross_price='188.90')})[0]
    row=draft_to_row(draft,p,profile)
    assert row['Price tax included']=='188.90' and row['Cost price']=='100.00'
    assert 'Price tax excluded' not in csv_columns('prestashop') and 'ID' not in row
    assert row['Quantity']=='7' and row['Tax rule ID']=='1' and row['Categories (x,y,z...)']=='1116'
    ebay=EbayProfile(free_shipping_confirmed=True)
    ed=compile_drafts(p,[vehicle()],price_settings(),ebay,catalog(),[],{})[0]
    er=draft_to_row(ed,p,ebay)
    assert er['Condition ID']=='1000' and er[csv_columns('ebay')[0]]=='Add'

def test_base_listing_has_no_invented_compatibility():
    p=product();id=listing_identity(p,None,'ebay')
    row=compile_drafts(p,[],price_settings(),EbayProfile(free_shipping_confirmed=True),catalog(),[],{id:ListingOverrides(listing_id=id,category_id='177925')})[0]
    assert row.valid and row.compatibility_id is None
    assert 'Compatibilita / Fitment' not in row.description_html
