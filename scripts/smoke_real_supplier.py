"""Verification only: explicit source mapping and temporary, disposable archive."""
import argparse
from collections import Counter, defaultdict
import csv
from decimal import Decimal
from pathlib import Path
from tempfile import TemporaryDirectory

from motorworld_csv.importing.models import FileBinding, ImportProfile, InputSource
from motorworld_csv.importing.service import import_catalog
from motorworld_csv.domain import Compatibility, PriceSettings, ListingOverrides
from motorworld_csv.categories.catalog import load_catalog
from motorworld_csv.platforms.profiles import EbayProfile, PrestashopProfile
from motorworld_csv.pipeline import compile_drafts
from motorworld_csv.storage import Repository
from motorworld_csv.exporting.service import export_selected


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    for role in ('anagrafica', 'prezzi', 'pesi'):
        parser.add_argument('--'+role, type=Path, required=True)
    parser.add_argument('--cost-field', required=True, help='Explicit net purchase cost column; never a production default.')
    args = parser.parse_args()
    profile = ImportProfile(id='real-smoke', supplier_id='real-smoke', name='Verifica allegati', bindings=[
        FileBinding(role='catalog', column_map={'code':'CodiceProdotto','name':'DescrizioneITA','brand':'Brand','ean':'Ean',
            'family':'CategoriaITA','department':'CompartoITA','image_urls':'Foto','available':'Disponibile','cost':args.cost_field}),
        FileBinding(role='prices', column_map={'code':'CodiceProdotto'}),
        FileBinding(role='packaging', column_map={'code':'codiceProdotto','quantity':'quantitaConfezione','gross_weight':'pesoLordo','volume':'volume'}),
    ])
    sources=[InputSource(id=role,path=path,role=role,name=path.name) for role,path in
        [('catalog',args.anagrafica),('prices',args.prezzi),('packaging',args.pesi)]]
    seen_stage = None
    def progress(p):
        nonlocal seen_stage
        if p.stage != seen_stage or p.completed == p.total:
            print(f'{p.stage}: {p.completed}/{p.total}', flush=True)
            seen_stage = p.stage
    result = import_catalog(sources,profile,progress)
    assert result.source_counts == {'catalog':17184,'prices':17603,'packaging':23455}, result.source_counts
    assert len(result.products) == 17173
    by_code={p.code:p for p in result.products}
    conflicts=[p for p in result.products if any(d.code=='conflicting_ean' for d in p.diagnostics)]
    assert len(conflicts)==11 and all(p.ean is None for p in conflicts)
    assert sum(d.code=='orphan_prices' for d in result.diagnostics)==430
    assert {p.code for p in result.products if not p.packaging}=={'D0042','P0125'}
    weight_rows=Counter()
    packages=defaultdict(set)
    with args.pesi.open(encoding='utf-8-sig',newline='') as f:
        for row in csv.DictReader(f,delimiter=';'):
            code=row['codiceProdotto'].strip()
            weight_rows[code]+=1
            packages[code].add(tuple(sorted(row.items())))
    assert sum(n>1 for n in weight_rows.values())==5437
    for code,product in by_code.items():
        actual={tuple(sorted(pack.source.columns.items())) for pack in product.packaging}
        assert actual==packages[code], ('Lost packaging',code)
    resources=Path(__file__).parents[1]/'backend/src/motorworld_csv/resources'
    catalog=load_catalog(resources)
    sample=next(p.model_copy(deep=True) for p in result.products if p.family.casefold()=='pastiglie freno' and p.cost is not None and not any(d.level=='error' for d in p.diagnostics))
    # These are deliberately entered test compatibilities, not claims about the supplied item.
    fits=[Compatibility(product_id=sample.id,make='Honda',model=model,vehicle_type='moto') for model in ('CBR 600','CB 500')]
    price=PriceSettings(shipping_cost=Decimal('5'),commission_rate=Decimal('.10'),product_markup=Decimal('.31'),shipping_markup=Decimal('0'),quantity=1)
    ebay=EbayProfile(free_shipping_confirmed=True)
    ps=PrestashopProfile(tax_rule_id='1',free_shipping_confirmed=True)  # fixture ID, not the user's tax configuration
    drafts=compile_drafts(sample,fits,price,ebay,catalog,[],{})
    # Supplier wording may exceed 80 chars; simulate an explicit operator title correction.
    overrides={d.id:ListingOverrides(listing_id=d.id,title='Pastiglie freno Honda '+fit.model) for d,fit in zip(drafts,fits)}
    drafts=compile_drafts(sample,fits,price,ebay,catalog,[],overrides)
    assert len(drafts)==2 and all(d.valid for d in drafts), [d.diagnostics for d in drafts]
    ps_drafts=compile_drafts(sample,fits,price,ps,catalog,[],{})
    assert len(ps_drafts)==1
    if not ps_drafts[0].valid:
        # A manual listing title can correct an overlong supplier product name on PS too.
        ps_drafts=compile_drafts(sample,fits,price,ps,catalog,[],{ps_drafts[0].id:ListingOverrides(listing_id=ps_drafts[0].id,title='Pastiglie freno')})
    assert ps_drafts[0].valid, ps_drafts[0].diagnostics
    assert all(f.model in ps_drafts[0].description_html for f in fits)
    with TemporaryDirectory(prefix='motorworld-real-smoke-') as tmp:
        root=Path(tmp);repo=Repository(root/'archive.sqlite3')
        repo.save_import('real',result)
        repo.save_compatibilities(sample.id,fits)
        repo.save_drafts('real',drafts)
        repo.put_record('validation','real:ebay',{'profile':ebay.model_dump(mode='json')})
        record=export_selected(repo,'real','ebay',[d.id for d in drafts],root/'exports'/'first',lambda p:None)
        csv_file=next(a for a in record.artifacts if a.kind=='csv')
        with (Path(record.directory)/csv_file.name).open(encoding='utf-8-sig',newline='') as f:
            rows=list(csv.DictReader(f,delimiter=';'))
        assert len(rows)==2 and {r['Title'] for r in rows}=={d.title for d in drafts}
        bad=conflicts[0].model_copy(deep=True)
        assert any(d.code=='conflicting_ean' for d in bad.diagnostics)
        bad.ean=None  # operator explicitly discards an unconfirmed barcode
        bad.diagnostics=[d for d in bad.diagnostics if d.field!='ean']
        repo.save_product(bad)
        assert not any(d.code=='conflicting_ean' for d in repo.get_product(bad.id).diagnostics)
        reopened=Repository(root/'archive.sqlite3')
        assert reopened.get_export(record.id).artifacts==record.artifacts
    print('OK: 17.184 righe → 17.173 prodotti; 11 EAN discordanti; 430 prezzi orfani; 2 senza pesi; 5.437 codici con confezioni multiple.')
    print('OK: confezioni conservate, compatibilità manuali, due destinazioni, CSV riletto, conflitto corretto e archivio riaperto.')


if __name__=='__main__':
    main()
