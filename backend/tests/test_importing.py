from pathlib import Path
from decimal import Decimal as D
import pytest
from motorworld_csv.importing.models import FileBinding, ImportProfile, InputSource
from motorworld_csv.importing.csv_reader import inspect_csv
from motorworld_csv.importing.service import import_catalog
from motorworld_csv.errors import DomainError


def run_import(tmp_path, catalog, weights='code;qty;weight\n00123;1;2\n00123;10;20\n', encoding='utf-8', **binding_changes):
    a, w = tmp_path/'catalog.csv', tmp_path/'weights.csv'
    a.write_text(catalog, encoding=encoding, newline='')
    w.write_text(weights, encoding='utf-8')
    profile = ImportProfile(id='profile', supplier_id='demo', name='Demo', bindings=[
        FileBinding(role='catalog', encoding=encoding, delimiter=';', decimal_separator=',',
            column_map={'code':'code','name':'name','cost':'cost','ean':'ean','available':'available'}, **binding_changes),
        FileBinding(role='packaging', column_map={'code':'code','quantity':'qty','gross_weight':'weight'})])
    return import_catalog([InputSource(id='a', path=a, role='catalog'), InputSource(id='w', path=w, role='packaging')], profile, lambda p: None)


def test_codes_and_multiline_round_trip(tmp_path):
    result=run_import(tmp_path, '\ufeffcode;name;cost;ean;available\n00123;"Filtro; ""speciale""\nseconda riga";12,30;0001234567890;0\n')
    p=result.products[0]
    assert p.code=='00123' and p.ean=='0001234567890'
    assert p.name=='Filtro; "speciale"\nseconda riga'
    assert p.cost==D('12.30') and p.available is False


def test_profile_requires_cost_choice():
    with pytest.raises(ValueError):
        ImportProfile(id='p',supplier_id='s',name='X',bindings=[FileBinding(role='catalog',column_map={'code':'code'})])


def test_conflicting_ean_is_not_arbitrarily_selected(tmp_path):
    result=run_import(tmp_path,'code;name;cost;ean;available\n00123;Filtro;10;0001;1\n00123;Filtro;10;0002;1\n')
    assert len(result.products)==1
    assert result.products[0].ean is None
    assert any(d.code=='conflicting_ean' and d.source.row==3 for d in result.products[0].diagnostics)


def test_packaging_is_not_product_duplication(tmp_path):
    p=run_import(tmp_path,'code;name;cost;ean;available\n00123;Filtro;10;001;1\n').products[0]
    assert len(p.packaging)==2 and p.packaging[1].quantity==D('10')
    assert p.packaging[0].weight_unit is None


def test_cp1252_requires_choice(tmp_path):
    path=tmp_path/'source.csv'
    path.write_bytes('code;name\n0001;È speciale\n'.encode('cp1252'))
    result=inspect_csv(path)
    assert any(d.code=='encoding_required' for d in result.diagnostics)
    assert inspect_csv(path,encoding='cp1252',delimiter=';').preview[0]['name']=='È speciale'


def test_duplicate_headers_are_blocked(tmp_path):
    path=tmp_path/'bad.csv'; path.write_text('code;code\n1;2\n')
    assert any(d.code=='duplicate_headers' and d.level=='error' for d in inspect_csv(path).diagnostics)


def test_orphan_prices_do_not_create_products(tmp_path):
    a,b=tmp_path/'a.csv',tmp_path/'b.csv'
    a.write_text('code;name\n001;Filtro\n'); b.write_text('code;cost\n001;12\n999;3\n')
    profile=ImportProfile(id='p',supplier_id='s',name='S',bindings=[
        FileBinding(role='catalog',column_map={'code':'code','name':'name'}),
        FileBinding(role='prices',column_map={'code':'code','cost':'cost'})])
    r=import_catalog([InputSource(id='a',path=a,role='catalog'),InputSource(id='b',path=b,role='prices')],profile,lambda _:None)
    assert len(r.products)==1 and r.products[0].cost==D('12')
    assert any(d.code=='orphan_prices' for d in r.diagnostics)


def test_nonfinite_price_is_row_error(tmp_path):
    r=run_import(tmp_path,'code;name;cost;ean;available\n00123;Filtro;NaN;001;1\n')
    assert r.products[0].cost is None
    assert any(d.code=='invalid_cost' for d in r.products[0].diagnostics)


def test_mapping_missing_column_is_not_silently_used(tmp_path):
    with pytest.raises(DomainError):
        run_import(tmp_path,'code;name;wrong;ean;available\n00123;Filtro;10;001;1\n')


def test_source_row_points_to_start_of_multiline_record(tmp_path):
    r=run_import(tmp_path,'code;name;cost;ean;available\n00123;"Filtro\nspeciale";12;001;1\n')
    assert r.products[0].sources[0].row == 2
