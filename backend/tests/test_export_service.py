from pathlib import Path
import csv
from factories import product,vehicle,price_settings
from motorworld_csv.importing.models import ImportResult
from motorworld_csv.categories.catalog import load_catalog
from motorworld_csv.platforms.profiles import EbayProfile
from motorworld_csv.storage import Repository
from motorworld_csv.pipeline import compile_drafts
from motorworld_csv.exporting.service import export_selected
from motorworld_csv.domain import ListingOverrides

def setup_catalog(tmp_path):
    repo=Repository(tmp_path/'a.sqlite')
    good=product();bad=product(id='bad',code='00999',name='X'*81,extra={'ebay_category_id':'177925'})
    profile=EbayProfile(free_shipping_confirmed=True);repo.save_platform_profile(profile)
    repo.save_import('i',ImportResult(products=[good,bad],diagnostics=[],source_counts={}))
    c=load_catalog(Path(__file__).parents[1]/'src/motorworld_csv/resources')
    drafts=compile_drafts(good,[vehicle()],price_settings(),profile,c,[],{})+compile_drafts(bad,[],price_settings(),profile,c,[],{})
    repo.save_drafts('i',drafts)
    repo.put_record('validation','i:ebay',{'profile':profile.model_dump(mode='json')})
    return repo,good,bad,profile,c,drafts

def test_valid_export_then_corrected_group_and_history(tmp_path):
    repo,good,bad,profile,c,drafts=setup_catalog(tmp_path)
    first=export_selected(repo,'i','ebay',[d.id for d in drafts],tmp_path/'first',lambda _:None)
    assert first.included_ids==[drafts[0].id] and first.excluded_ids==[drafts[1].id]
    original=(Path(first.directory)/'prodotti.csv').read_bytes()
    assert 'title_too_long' in (Path(first.directory)/'rapporto.csv').read_text(encoding='utf-8-sig')
    bad.name='Pastiglie corrette';repo.save_product(bad)
    corrected=compile_drafts(bad,[],price_settings(),profile,c,[],{})
    repo.save_drafts('i',[drafts[0]]+corrected)
    second=export_selected(repo,'i','ebay',[corrected[0].id],tmp_path/'second',lambda _:None)
    assert second.included_ids==[drafts[1].id]
    assert repo.list_drafts('i','ebay',None,0,50).counts['exported']==2
    assert (Path(repo.get_export(first.id).directory)/'prodotti.csv').read_bytes()==original
    assert export_selected(repo,'i','ebay',[d.id for d in drafts],tmp_path/'first',lambda _:None).id==first.id

def test_duplicate_reference_is_blocked(tmp_path):
    repo,good,bad,profile,c,drafts=setup_catalog(tmp_path)
    bad.name='Secondo prodotto'
    override=ListingOverrides(listing_id=drafts[1].id,reference=drafts[0].reference)
    other=compile_drafts(bad,[],price_settings(),profile,c,[],{override.listing_id:override})
    repo.save_drafts('i',[drafts[0]]+other)
    page=repo.list_drafts('i','ebay',None,0,50)
    assert all(not d.valid for d in page.items)
    assert all(any(e.code=='duplicate_reference' for e in d.diagnostics) for d in page.items)
