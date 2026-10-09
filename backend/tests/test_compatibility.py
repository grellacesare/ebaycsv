from factories import product,vehicle
from motorworld_csv.compatibility import validate_compatibilities,listing_identity

def test_reordering_models_keeps_listing_identity():
    p,a,b=product(),vehicle(),vehicle(id='fitment-2',model='CB 500')
    reordered=[b,a]
    assert listing_identity(p,a,'ebay')==listing_identity(p,reordered[1],'ebay')
    assert listing_identity(p,a,'ebay')!=listing_identity(p,b,'ebay')
    assert listing_identity(p,a,'prestashop')==listing_identity(p,b,'prestashop')

def test_duplicate_model_requires_correction():
    rows=[vehicle(),vehicle(id='other',model='  CBR   600 ')]
    assert any(d.code=='duplicate_compatibility' for d in validate_compatibilities(rows))

def test_inverted_years_require_correction():
    assert any(d.code=='invalid_years' for d in validate_compatibilities([vehicle(year_from=2020,year_to=2019)]))

def test_supplier_references_do_not_merge():
    assert listing_identity(product(),None,'ebay') != listing_identity(product(supplier_id='other'),None,'ebay')
