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

def test_reimport_preserves_manual_edits_and_invalidates_existing_drafts(tmp_path):
    from decimal import Decimal
    from motorworld_csv.domain import ListingDraft
    repo=Repository(tmp_path/'a.sqlite');raw=product()
    repo.save_import('first',ImportResult(products=[raw],diagnostics=[],source_counts={}))
    edited=raw.model_copy(deep=True);edited.name='Nome corretto';edited.extra={'ebay_category_id':'177925','notes':'Nota manuale'}
    repo.save_product(edited)
    repo.save_drafts('first',[ListingDraft(id='draft',product_id=raw.id,destination='ebay',reference='sku',title=edited.name,valid=True)])
    incoming=raw.model_copy(update={'name':'Nuovo nome fornitore','cost':Decimal('999'),'extra':{'Qta1':'8'}})
    repo.save_import('next',ImportResult(products=[incoming],diagnostics=[],source_counts={}))
    current=repo.get_product(raw.id)
    assert current.name=='Nome corretto' and current.cost==Decimal('999')
    assert current.extra=={'Qta1':'8','ebay_category_id':'177925','notes':'Nota manuale'}
    assert repo.get_draft('draft','first').stale is True
    assert repo.get_import('next')['original_products'][0]['name']=='Nuovo nome fornitore'
    reset=repo.reset_product_corrections(raw.id)
    assert reset.name=='Nuovo nome fornitore' and reset.cost==Decimal('999') and reset.extra=={'Qta1':'8'}

def test_resolved_ean_and_manual_cost_survive_reimport_with_warning(tmp_path):
    from decimal import Decimal
    from motorworld_csv.domain import Diagnostic
    repo=Repository(tmp_path/'a.sqlite');p=product(ean=None,cost=None,diagnostics=[Diagnostic(level='error',code='conflicting_ean',message='Conflitto',field='ean')])
    result=ImportResult(products=[p],diagnostics=[],source_counts={})
    repo.save_import('first',result)
    edited=p.model_copy(update={'cost':Decimal('100'),'diagnostics':[]})
    repo.save_product(edited,resolved_fields=['ean'])
    repo.save_import('next',result)
    saved=repo.get_product(p.id)
    assert saved.cost==Decimal('100') and saved.ean is None
    assert not any(d.code=='conflicting_ean' for d in saved.diagnostics)
    assert any(d.code=='manual_correction_retained' for d in saved.diagnostics)
