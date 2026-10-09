from motorworld_csv.storage import Repository, ProductFilters
from motorworld_csv.importing.models import ImportResult
from factories import product, vehicle

def test_archive_survives_reopen(tmp_path):
    repo=Repository(tmp_path/'archive.sqlite')
    p=product()
    repo.save_import('import-1',ImportResult(products=[p],diagnostics=[],source_counts={'file':1}))
    repo.save_compatibilities(p.id,[vehicle()])
    reopened=Repository(tmp_path/'archive.sqlite')
    assert reopened.get_product(p.id).code=='00123'
    assert reopened.get_compatibilities(p.id)[0].id=='fitment-1'
    assert reopened.list_products('import-1',ProductFilters(query='001'),0,50).total==1

def test_correction_keeps_original_snapshot(tmp_path):
    repo=Repository(tmp_path/'archive.sqlite');p=product()
    repo.save_import('i',ImportResult(products=[p],diagnostics=[],source_counts={}))
    p.name='Corretto'; repo.save_product(p)
    assert repo.get_product(p.id).name=='Corretto'
    assert repo.get_import('i')['original_products'][0]['name']=='Pastiglie freno'
