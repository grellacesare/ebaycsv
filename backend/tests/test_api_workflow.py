import csv
import io
import time
from fastapi.testclient import TestClient
from motorworld_csv.api.app import create_app

def wait(client,id):
    for _ in range(1000):
        j=client.get('/api/jobs/'+id).json()
        if j['state'] in ('completed','failed'):
            assert j['state']=='completed',j
            return j
        time.sleep(.01)
    raise AssertionError('Job timeout')

def prepare(client):
    data='code;name;cost;family;department\n00123;Pastiglie;100;Pastiglie Freno;Impianto Frenante\n00999;'+('X'*81)+';10;Pastiglie Freno;Impianto Frenante\n'
    up=client.post('/api/uploads',files=[('files',('fornitore.csv',data.encode(),'text/csv'))])
    assert up.status_code==200,up.text
    upload=up.json()['uploads'][0]
    profile={'id':'demo','supplier_id':'demo','name':'Fornitore demo','bindings':[{'role':'catalog','encoding':'utf-8','delimiter':';','decimal_separator':'.','column_map':{'code':'code','name':'name','cost':'cost','family':'family','department':'department'}}]}
    assert client.put('/api/supplier-profiles/demo',json=profile).status_code==200
    response=client.post('/api/imports',json={'profile_id':'demo','uploads':[{'upload_id':upload['id'],'role':'catalog'}],'request_key':'import-key'})
    assert response.status_code==202,response.text
    id=wait(client,response.json()['id'])['result']['import_id']
    products=client.get('/api/products',params={'import_id':id}).json()['items']
    return id,{p['code']:p for p in products}

def settings(client):
    profile=client.get('/api/settings/ebay').json()['platform_profile']
    profile['free_shipping_confirmed']=True
    r=client.put('/api/settings/ebay',json={'platform_profile':profile,'price_settings':{'shipping_cost':'5','product_markup':'.20','shipping_markup':'0','commission_rate':'.10','commission_fixed':'0','vat_rate':'.22','quantity':1}})
    assert r.status_code==200,r.text

def validate(client,id,key):
    r=client.post(f'/api/imports/{id}/validate',json={'destination':'ebay','request_key':key})
    assert r.status_code==202,r.text
    wait(client,r.json()['id'])
    return client.get(f'/api/imports/{id}/drafts',params={'destination':'ebay'}).json()

def test_manual_workflow_and_partial_export(tmp_path):
    with TestClient(create_app(tmp_path/'data')) as c:
        id,products=prepare(c);settings(c)
        p=products['00123']
        assert c.put(f"/api/products/{p['id']}/compatibilities",json={'rows':[{'product_id':p['id'],'make':'Honda','model':'CBR 600','vehicle_type':'moto'},{'product_id':p['id'],'make':'Honda','model':'CB 500','vehicle_type':'moto'}]}).status_code==200
        bad=products['00999']
        assert c.patch('/api/products/'+bad['id'],json={'updates':{'extra':{'ebay_category_id':'177925'}}}).status_code==200
        page=validate(c,id,'check-1')
        assert page['counts']['ready']==2 and page['counts']['errors']==1
        r=c.post('/api/exports',json={'import_id':id,'destination':'ebay','selection_mode':'new_valid','request_key':'export-1'})
        export_id=wait(c,r.json()['id'])['result']['export_id']
        report=c.get('/api/exports/'+export_id).json()
        assert report['included_count']==2 and report['excluded_count']==1
        data=c.get(f'/api/exports/{export_id}/files/prodotti.csv').content.decode('utf-8-sig')
        assert len(list(csv.DictReader(io.StringIO(data),delimiter=';')))==2
        assert c.patch('/api/products/'+bad['id'],json={'updates':{'name':'Pastiglie corrette'},'resolve_fields':['name']}).status_code==200
        page=validate(c,id,'check-2')
        assert page['counts']['ready']==1 and page['counts']['exported']==2
        r=c.post('/api/exports',json={'import_id':id,'destination':'ebay','selection_mode':'new_valid','request_key':'export-2'})
        second=wait(c,r.json()['id'])['result']['export_id']
        assert c.get('/api/exports/'+second).json()['included_count']==1

def test_restart_keeps_export_download_and_duplicate_request(tmp_path):
    app=create_app(tmp_path/'data')
    with TestClient(app) as c:
        id,products=prepare(c);settings(c)
        for p in products.values():c.patch('/api/products/'+p['id'],json={'updates':{'name':'Pastiglie','extra':{'ebay_category_id':'177925'}}})
        validate(c,id,'check')
        payload={'import_id':id,'destination':'ebay','request_key':'export-key','selection_mode':'new_valid'}
        first=c.post('/api/exports',json=payload);dup=c.post('/api/exports',json=payload)
        assert first.json()['id']==dup.json()['id']
        export_id=wait(c,first.json()['id'])['result']['export_id']
        original=c.get(f'/api/exports/{export_id}/files/prodotti.csv').content
    with TestClient(create_app(tmp_path/'data')) as c:
        assert c.get(f'/api/exports/{export_id}/files/prodotti.csv').content==original
        assert c.get(f'/api/exports/{export_id}/files/non-esiste.csv').status_code==404
        assert len(c.get('/api/imports').json())==1

def test_cp1252_can_be_reinspected_and_original_is_unchanged(tmp_path):
    with TestClient(create_app(tmp_path/'data')) as c:
        raw='code;name\n001;È speciale\n'.encode('cp1252')
        up=c.post('/api/uploads',files={'files':('cp.csv',raw,'text/csv')}).json()['uploads'][0]
        assert up['inspection']['encoding'] is None
        inspected=c.get(f"/api/uploads/{up['id']}/inspect",params={'encoding':'cp1252','delimiter':';'}).json()
        assert inspected['preview'][0]['name']=='È speciale'
        path=app_path=tmp_path/'data'/'uploads'/(up['id']+'.csv')
        assert path.read_bytes()==raw

def test_error_reports_original_supplier_filename(tmp_path):
    with TestClient(create_app(tmp_path/'data')) as c:
        raw=b'code;name;cost\n001;Filtro;non-numerico\n'
        upload=c.post('/api/uploads',files={'files':('listino-originale.csv',raw,'text/csv')}).json()['uploads'][0]
        profile={'id':'demo','supplier_id':'demo','name':'Demo','bindings':[{'role':'catalog','column_map':{'code':'code','name':'name','cost':'cost'}}]}
        c.put('/api/supplier-profiles/demo',json=profile)
        job=c.post('/api/imports',json={'profile_id':'demo','uploads':[{'upload_id':upload['id'],'role':'catalog'}],'request_key':'bad-cost'}).json()
        id=wait(c,job['id'])['result']['import_id']
        d=c.get('/api/products',params={'import_id':id}).json()['items'][0]['diagnostics'][0]
        assert d['source']['file']=='listino-originale.csv' and d['source']['row']==2

def test_bad_category_position_is_actionable_error(tmp_path):
    with TestClient(create_app(tmp_path/'data')) as c:
        raw=b'Id;Nome;Mostrata;Posizione\n1;Moto;1;errore\n'
        result=c.post('/api/categories/prestashop/replace',files={'file':('categorie.csv',raw,'text/csv')})
        assert result.status_code==422 and result.json()['code']=='invalid_category_file'

def test_changes_are_blocked_during_processing(tmp_path):
    from threading import Event
    release=Event()
    app=create_app(tmp_path/'data')
    with TestClient(app) as c:
        id,products=prepare(c)
        app.state.context.jobs.register('hold',lambda payload,progress: {'done':release.wait(2)})
        job=app.state.context.jobs.submit('hold',{},'hold-key')
        try:
            response=c.patch('/api/products/'+products['00123']['id'],json={'updates':{'name':'Modificato'}})
            assert response.status_code==409 and response.json()['code']=='job_busy'
        finally:release.set()
        wait(c,job.id)

def test_uploaded_files_can_be_reused_from_archive(tmp_path):
    with TestClient(create_app(tmp_path/'data')) as c:
        raw=b'code;name\n001;Filtro\n'
        up=c.post('/api/uploads',files={'files':('source.csv',raw,'text/csv')}).json()['uploads'][0]
        assert c.get('/api/uploads').json()[0]['id']==up['id']
        assert c.get('/api/uploads/'+up['id']+'/original').content==raw

def test_price_preview_preserves_warning_and_existing_override(tmp_path):
    with TestClient(create_app(tmp_path/'data')) as c:
        id,products=prepare(c);settings(c)
        p=products['00123'];c.patch('/api/products/'+p['id'],json={'updates':{'extra':{'ebay_category_id':'177925'}}})
        page=validate(c,id,'preview-check');draft=next(d for d in page['items'] if d['product_id']==p['id'])
        payload={'listing_id':draft['id'],'title':'Titolo corretto'}
        c.put('/api/listing-overrides/'+draft['id'],json=payload)
        response=c.get('/api/listing-overrides/'+draft['id'])
        assert response.status_code==200 and response.json()['title']=='Titolo corretto'
        price=c.get('/api/settings/ebay').json()['price_settings']
        response=c.post('/api/pricing/preview',json={'cost':'100','price_settings':price,'override_gross':'188.94'})
        assert response.status_code==200
        assert response.json()['final_gross']=='188.90' and response.json()['below_minimum'] is True

def test_price_preview_keeps_euros_and_uses_decimal_margin(tmp_path):
    from factories import price_settings
    with TestClient(create_app(tmp_path/'data')) as c:
        response=c.post('/api/pricing/preview',json={'cost':'100','price_settings':price_settings().model_dump(mode='json'),'override_gross':'188.999'})
        assert response.status_code==200
        assert response.json()['final_gross']=='188.90' and response.json()['minimum_gross']=='189.90'

def test_exported_base_identity_survives_variant_removal(tmp_path):
    with TestClient(create_app(tmp_path/'data')) as c:
        id,products=prepare(c);settings(c);p=products['00123']
        c.patch('/api/products/'+p['id'],json={'updates':{'extra':{'ebay_category_id':'177925'}}})
        first=validate(c,id,'base-check');base=next(d for d in first['items'] if d['product_id']==p['id'])
        job=c.post('/api/exports',json={'import_id':id,'destination':'ebay','request_key':'base-export'}).json()
        export_id=wait(c,job['id'])['result']['export_id']
        original=c.get('/api/exports/'+export_id+'/files/prodotti.csv').content
        c.put('/api/products/'+p['id']+'/compatibilities',json={'rows':[{'id':'model','product_id':p['id'],'make':'Honda','model':'CBR','vehicle_type':'moto'}]})
        validate(c,id,'model-check')
        c.put('/api/products/'+p['id']+'/compatibilities',json={'rows':[]})
        restored=validate(c,id,'restored-check')
        draft=next(d for d in restored['items'] if d['product_id']==p['id'])
        assert draft['id']==base['id'] and draft['exported'] is True
        assert restored['counts']['ready']==0
        assert c.get('/api/exports/'+export_id+'/files/prodotti.csv').content==original

def test_skipped_import_rows_have_downloadable_report_and_export_counts(tmp_path):
    with TestClient(create_app(tmp_path/'data')) as c:
        raw=b'code;name;cost\n001;Pastiglie;100\n;Prodotto senza codice;10\n'
        u=c.post('/api/uploads',files={'files':('scartati.csv',raw,'text/csv')}).json()['uploads'][0]
        profile={'id':'skipped','supplier_id':'skipped','name':'Skips','bindings':[{'role':'catalog','column_map':{'code':'code','name':'name','cost':'cost'}}]}
        assert c.put('/api/supplier-profiles/skipped',json=profile).status_code==200
        job=c.post('/api/imports',json={'profile_id':'skipped','uploads':[{'upload_id':u['id'],'role':'catalog'}],'request_key':'skipped-import'}).json()
        id=wait(c,job['id'])['result']['import_id']
        assert c.get('/api/imports/'+id).json()['diagnostics'][0]['code']=='missing_code'
        report=c.get('/api/imports/'+id+'/report')
        assert report.status_code==200
        rows=list(csv.DictReader(io.StringIO(report.content.decode('utf-8-sig')),delimiter=';'))
        assert rows[0]['Errore']=='missing_code' and rows[0]['File']=='scartati.csv' and rows[0]['Riga']=='3'
        settings(c);p=c.get('/api/products',params={'import_id':id}).json()['items'][0]
        c.patch('/api/products/'+p['id'],json={'updates':{'extra':{'ebay_category_id':'177925'}}})
        validate(c,id,'skipped-check')
        export=c.post('/api/exports',json={'import_id':id,'destination':'ebay','request_key':'skipped-export'}).json()
        export_id=wait(c,export['id'])['result']['export_id']
        summary=c.get('/api/exports/'+export_id).json()
        assert summary['import_error_count']==1
        report=c.get('/api/exports/'+export_id+'/files/rapporto.csv').content.decode('utf-8-sig')
        assert 'missing_code' in report and 'scartati.csv' in report

def test_reimport_blocks_old_export_and_manual_corrections_can_reset(tmp_path):
    with TestClient(create_app(tmp_path/'data')) as c:
        id,products=prepare(c);settings(c);p=products['00123']
        c.patch('/api/products/'+p['id'],json={'updates':{'name':'Nome manuale','extra':{'ebay_category_id':'177925','notes':'Nota manuale'}}})
        page=validate(c,id,'old-check');draft=next(d for d in page['items'] if d['product_id']==p['id'])
        raw=b'code;name;cost;family;department;Qta1\n00123;Nuovo nome;999;Pastiglie Freno;Impianto Frenante;8\n'
        u=c.post('/api/uploads',files={'files':('nuovo.csv',raw,'text/csv')}).json()['uploads'][0]
        job=c.post('/api/imports',json={'profile_id':'demo','uploads':[{'upload_id':u['id'],'role':'catalog'}],'request_key':'reimport'}).json()
        new_id=wait(c,job['id'])['result']['import_id']
        current=c.get('/api/products/'+p['id']).json()
        assert current['cost']=='999' and current['name']=='Nome manuale' and current['extra']['notes']=='Nota manuale'
        old=c.get('/api/imports/'+id+'/drafts',params={'destination':'ebay'}).json()
        assert next(d for d in old['items'] if d['id']==draft['id'])['stale'] is True
        job=c.post('/api/exports',json={'import_id':id,'destination':'ebay','selection_mode':'selected','draft_ids':[draft['id']],'request_key':'stale-export'}).json()
        for _ in range(1000):
            failed=c.get('/api/jobs/'+job['id']).json()
            if failed['state']=='failed':break
            time.sleep(.01)
        assert failed['state']=='failed' and 'riga valida' in failed['error']
        reset=c.post('/api/products/'+p['id']+'/reset-corrections')
        assert reset.status_code==200 and reset.json()['name']=='Nuovo nome'
        assert reset.json()['cost']=='999' and reset.json()['extra']=={'Qta1':'8'}
        assert c.get('/api/imports/'+new_id).json()['product_count']==1
