from pathlib import Path
from factories import product,vehicle
from motorworld_csv.categories.catalog import load_catalog,validate_catalog_replacement
from motorworld_csv.categories.models import CategoryCatalog,MarketplaceCategory,CategoryRule
from motorworld_csv.categories.matching import resolve_category

RESOURCES=Path(__file__).parents[1]/'src/motorworld_csv/resources'

def test_catalog_has_supplied_ids():
    c=load_catalog(RESOURCES)
    assert len(c.ebay)==714 and len({r.id for r in c.ebay})==714
    assert len(c.prestashop)==21 and sum(not r.shown for r in c.prestashop)==3

def test_ambiguous_path_is_unresolved():
    c=CategoryCatalog(ebay=[MarketplaceCategory(id='1',path='Moto: ricambi > Luci',context='moto'),MarketplaceCategory(id='2',path='Moto: ricambi > Luci',context='moto')])
    r=resolve_category(product(family='Moto: ricambi > Luci'),vehicle(),'ebay',c,[])
    assert r.category_ids==[] and r.diagnostics[0].level=='error'

def test_moto_and_scooter_do_not_share_incompatible_override():
    c=load_catalog(RESOURCES);p=product(family='Carburatori')
    assert resolve_category(p,vehicle(),'ebay',c,[]).category_ids==['177773']
    assert resolve_category(p,vehicle(vehicle_type='scooter'),'ebay',c,[]).category_ids==['82205']
    r=resolve_category(p,vehicle(vehicle_type='scooter'),'ebay',c,[],override='177773')
    assert r.category_ids==[] and r.diagnostics

def test_unspecified_context_does_not_guess_air_filter():
    r=resolve_category(product(family='Filtri aria'),None,'ebay',load_catalog(RESOURCES),[])
    assert not r.category_ids

def test_saved_rule_is_reused():
    c=load_catalog(RESOURCES)
    rule=CategoryRule(supplier_id='demo',family='Le mie pastiglie',context='moto',destination='ebay',category_id='177925')
    r=resolve_category(product(family='Le mie pastiglie'),vehicle(),'ebay',c,[rule])
    assert r.category_ids==['177925'] and r.method=='saved_rule'

def test_deleted_category_invalidates_rule():
    c=load_catalog(RESOURCES)
    rule=CategoryRule(supplier_id='demo',family='Carburatori',context='moto',destination='ebay',category_id='177773')
    replacement=c.model_copy(update={'ebay':[r for r in c.ebay if r.id!='177773']})
    assert any(d.code=='stale_category_rule' for d in validate_catalog_replacement(c,replacement,[rule]))

def test_hidden_store_category_warns():
    r=resolve_category(product(),None,'prestashop',load_catalog(RESOURCES),[],override='1179')
    assert r.category_ids==['1179']
    assert any(d.code=='hidden_store_category' for d in r.diagnostics)

def test_unknown_store_id_is_rejected():
    r=resolve_category(product(),None,'prestashop',load_catalog(RESOURCES),[],override='999999')
    assert r.category_ids==[]

def test_generic_brake_pad_family_does_not_imply_moto():
    r=resolve_category(product(family='Pastiglie Freno'),None,'ebay',load_catalog(RESOURCES),[])
    assert not r.category_ids

def test_specific_saved_context_takes_priority_over_common_rule():
    rules=[CategoryRule(supplier_id='demo',family='Carburatori',context='unspecified',destination='ebay',category_id='177773'),
        CategoryRule(supplier_id='demo',family='Carburatori',context='scooter',destination='ebay',category_id='82205')]
    r=resolve_category(product(family='Carburatori'),vehicle(vehicle_type='scooter'),'ebay',load_catalog(RESOURCES),rules)
    assert r.category_ids==['82205']
