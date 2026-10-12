#!/usr/bin/env python3
"""Exercise the actual FTP package over local HTTPS/Apache, using private fixture data."""
from pathlib import Path
import argparse
import contextlib
import http.cookiejar
import json
import os
import re
import shutil
import socket
import ssl
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
import zipfile

ROOT = Path(__file__).resolve().parents[3]

class Session:
    def __init__(self, base):
        self.base = base
        self.jar = http.cookiejar.CookieJar()
        # This fixture creates its own localhost certificate; production TLS is unchanged.
        context = ssl._create_unverified_context()
        self.opener = urllib.request.build_opener(urllib.request.ProxyHandler({}),
                                                urllib.request.HTTPCookieProcessor(self.jar),
                                                urllib.request.HTTPSHandler(context=context))
        self.csrf = ''

    def request(self, path, method='GET', data=None, headers=None):
        request = urllib.request.Request(self.base + path, data=data, method=method, headers=headers or {})
        try:
            with self.opener.open(request, timeout=40) as response:
                return response.status, response.read(), dict(response.headers)
        except urllib.error.HTTPError as response:
            return response.code, response.read(), dict(response.headers)

    def form(self, path, fields):
        return self.request(path, 'POST', urllib.parse.urlencode(fields).encode(),
                            {'Content-Type': 'application/x-www-form-urlencoded'})

    def api(self, path, method='GET', body=None, token=True):
        headers = {}
        if method != 'GET' and token:
            headers['X-CSRF-Token'] = self.csrf
        data = None
        if body is not None:
            data = json.dumps(body).encode()
            headers['Content-Type'] = 'application/json'
        status, raw, response_headers = self.request('/csvstudio/api.php?' + urllib.parse.urlencode({'path': path}), method, data, headers)
        try:
            result = json.loads(raw)
        except ValueError:
            raise AssertionError(f'API returned non-JSON with status {status}, {len(raw)} bytes')
        return status, result, response_headers

def token(html):
    return re.search(rb'name="csrf" value="([a-f0-9]{64})"', html).group(1).decode()

def run(args):
    package = args.package.resolve()
    if not package.is_file():
        raise AssertionError('The FTP package has not been created')
    with tempfile.TemporaryDirectory(prefix='mw-php-http-', dir=ROOT/'var/deployment') as directory:
        fixture = Path(directory)
        fixture.chmod(0o755)
        with zipfile.ZipFile(package) as archive:
            archive.extractall(fixture)
        (fixture/'public_html').mkdir()
        shutil.move(fixture/'csvstudio', fixture/'public_html/csvstudio')
        with socket.socket() as available:
            available.bind(('127.0.0.1', 0))
            port = available.getsockname()[1]
        subprocess.run(['openssl','req','-x509','-nodes','-newkey','rsa:2048','-days','1',
                        '-subj','/CN=localhost','-keyout',str(fixture/'key.pem'),'-out',str(fixture/'cert.pem')],
                       check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        configuration = fixture/'apache.conf'
        configuration.write_text('''ServerName localhost
<VirtualHost *:443>
    DocumentRoot /var/www/mw-php/public_html
    SSLEngine on
    SSLCertificateFile /var/www/mw-php/cert.pem
    SSLCertificateKeyFile /var/www/mw-php/key.pem
    <Directory /var/www/mw-php/public_html>
        AllowOverride All
        Require all granted
        DirectoryIndex index.php
    </Directory>
</VirtualHost>
''')
        name = 'mw-php-http-' + str(os.getpid())
        try:
            subprocess.run(['docker','run','-d','--name',name,'-p',f'127.0.0.1:{port}:443',
                            '-v',str(fixture)+':/var/www/mw-php',
                            '-v',str(configuration)+':/etc/apache2/sites-enabled/000-default.conf:ro',
                            'php:8.3-apache','sh','-c',
                            'chown -R www-data:www-data /var/www/mw-php && a2enmod ssl >/dev/null && apache2-foreground'],
                           check=True, stdout=subprocess.DEVNULL)
            client = Session(f'https://127.0.0.1:{port}')
            for attempt in range(100):
                try:
                    status, page, headers = client.request('/csvstudio/setup.php')
                    if status == 200:
                        break
                    raise AssertionError(f'Setup HTTP {status}: {page[:160]!r}')
                except (urllib.error.URLError, ConnectionError):
                    time.sleep(.1)
            else:
                logs=subprocess.check_output(['docker','logs','--tail','15',name],stderr=subprocess.STDOUT,text=True)
                print(logs)
                raise AssertionError('HTTPS fixture did not start')
            def private_exists(path):
                return subprocess.run(['docker','exec',name,'test','-e','/var/www/mw-php/csvstudio-data/'+path],
                                      stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL).returncode == 0
            key = subprocess.check_output(['docker','exec',name,'cat',
                                           '/var/www/mw-php/csvstudio-data/installation-key.txt'],text=True).strip()
            assert key.encode() not in page
            csrf = token(page)
            status, _, _ = client.form('/csvstudio/setup.php', {'csrf':csrf,'installation_key':'x'*64,
                                                              'username':'owner','password':'A-Private-Password-123!'})
            assert status == 422
            status, _, _ = client.form('/csvstudio/setup.php', {'csrf':csrf,'installation_key':key,
                                                              'username':'owner','password':'A-Private-Password-123!'})
            assert status == 200 and not private_exists('installation-key.txt')
            anonymous = Session(client.base)
            assert anonymous.api('/health')[0] == 401
            assert anonymous.request('/csvstudio-data/config.json')[0] in (403,404)
            assert anonymous.request('/csvstudio/app/resources/resource_manifest.json')[0] == 403
            assert anonymous.request('/csvstudio/logout.php')[0] == 405
            status, page, _ = client.request('/csvstudio/')
            csrf = token(page)
            status, page, response_headers = client.form('/csvstudio/', {'csrf':csrf,'username':'owner',
                                                                      'password':'A-Private-Password-123!'})
            assert status == 200
            configuration = json.loads(re.search(rb'window\.MW_HOSTING=(\{.*?\});',page).group(1))
            assert configuration['apiEndpoint'] == '/csvstudio/api.php'
            assert configuration['basePath'] == '/csvstudio/'
            client.csrf = configuration['csrf']
            assert any(cookie.secure and cookie.path=='/csvstudio/' for cookie in client.jar)
            assert 'no-store' in response_headers['Cache-Control']
            assert client.api('/health')[1]['status']=='ok'
            assert client.api('/pricing/preview','POST',{},False)[0]==403
            assert client.api('/pricing/preview','POST',{'cost':'NaN','price_settings':{}})[0]==422
            logo_status, logo, _ = client.request('/csvstudio/api.php?path=%2Fbrand%2Flogo')
            assert logo_status==200 and logo.startswith(b'\x89PNG')
            for asset in re.findall(rb'(?:src|href)="\./(assets/[^"<>]+)"',page):
                assert client.request('/csvstudio/'+asset.decode())[0]==200
            assert b'type="module"' in page
            boundary = 'mw-http-' + str(os.getpid())
            csv = b'Codice;Nome;Famiglia;Costo\r\n00123;Pastiglie freno;Pastiglie Freno;10.00\r\n00456;Filtro aria;Filtri Aria;\r\n'
            multipart = (f'--{boundary}\r\nContent-Disposition: form-data; name="files[]"; filename="fornitore.csv"\r\n'
                         'Content-Type: text/csv\r\n\r\n').encode()+csv+f'\r\n--{boundary}--\r\n'.encode()
            status, result, _ = client.request('/csvstudio/api.php?path=%2Fuploads','POST',multipart,
                                               {'Content-Type':f'multipart/form-data; boundary={boundary}',
                                                'X-CSRF-Token':client.csrf})
            assert status==200, 'Real multipart upload should succeed'
            upload=json.loads(result)['uploads'][0]
            assert upload['inspection']['record_count']==2
            profile={'id':'profile-http','supplier_id':'supplier-http','name':'Fornitore HTTP','bindings':[
                {'role':'catalog','encoding':'utf-8','delimiter':';','decimal_separator':'.',
                 'column_map':{'code':'Codice','name':'Nome','family':'Famiglia','cost':'Costo'}}]}
            assert client.api('/supplier-profiles/profile-http','PUT',profile)[0]==200
            request={'profile_id':'profile-http','uploads':[{'upload_id':upload['id'],'role':'catalog'}],
                     'request_key':'http-import'}
            status, job, _=client.api('/imports','POST',request)
            assert status in (200,202)
            assert client.api('/imports','POST',request)[1]['id']==job['id']
            def complete(job):
                for _ in range(200):
                    if job['state']=='completed': return job
                    assert job['state'] in ('queued','running'), 'Job failed: '+str(job.get('error'))
                    status, job, _=client.api('/jobs/'+job['id']+'/step','POST',{})
                    assert status==200
                raise AssertionError('Job did not finish in its bounded steps')
            job=complete(job); import_id=job['result']['import_id']
            products=client.api('/products?'+urllib.parse.urlencode({'import_id':import_id}))[1]['items']
            first=next(p for p in products if p['code']=='00123')
            fits=[{'id':'fit-http','make':'Honda','model':'CBR 600','displacement':'600',
                   'year_from':2007,'year_to':2012,'vehicle_type':'moto'}]
            assert client.api('/products/'+first['id']+'/compatibilities','PUT',{'rows':fits})[0]==200
            platform=client.api('/settings/ebay')[1]['platform_profile'];platform['free_shipping_confirmed']=True
            pricing={'shipping_cost':'5','product_markup':'.2','shipping_markup':'0','commission_rate':'.1','quantity':1}
            assert client.api('/settings/ebay','PUT',{'platform_profile':platform,'price_settings':pricing})[0]==200
            complete(client.api('/imports/'+import_id+'/validate','POST',{'destination':'ebay','request_key':'http-validate'})[1])
            drafts=client.api('/imports/'+import_id+'/drafts?destination=ebay')[1]
            assert drafts['counts']['ready']==1 and drafts['counts']['errors']==1
            exported=complete(client.api('/exports','POST',{'import_id':import_id,'destination':'ebay',
                                                           'selection_mode':'new_valid','request_key':'http-export'})[1])
            history=client.api('/exports?import_id='+import_id)[1]
            assert history[0]['included_count']==1
            csv_artifact=next(a for a in history[0]['artifacts'] if a['kind']=='csv')
            download='/csvstudio/api.php?'+urllib.parse.urlencode({'path':'/exports/'+exported['result']['export_id']+'/files/'+csv_artifact['name']})
            status, contents, headers=client.request(download)
            assert status==200 and contents.startswith(b'\xef\xbb\xbf')
            assert 'attachment' in headers['Content-Disposition']
            assert anonymous.request(download)[0]==401
            assert anonymous.request('/csvstudio/api.php?'+urllib.parse.urlencode({'path':'/uploads/'+upload['id']+'/original'}))[0]==401
            before=contents
            other=next(p for p in products if p['code']=='00456')
            assert client.api('/products/'+other['id'],'PATCH',{'updates':{'cost':'12'},'resolve_fields':['cost']})[0]==200
            assert client.request(download)[1]==before
            if args.browser:
                runner=ROOT/'hosting/php/tests/browser_test.mjs'
                browser_env=os.environ.copy()
                browser_env.update(MW_TEST_BASE=client.base, MW_TEST_USERNAME='owner',
                                   MW_TEST_PASSWORD='A-Private-Password-123!')
                node=ROOT/'var/tools/node-v24.21.0-linux-x64/bin/node'
                subprocess.run([str(node) if node.is_file() else 'node',str(runner)],
                               cwd=ROOT,env=browser_env,check=True)
            assert client.form('/csvstudio/logout.php',{'csrf':client.csrf})[0]==200
            assert client.api('/health')[0]==401
            assert client.request('/csvstudio/setup.php')[0]==200
            assert not private_exists('installation-key.txt')
            print('PASS: actual FTP ZIP over HTTPS; private install/login/CSRF, nested assets, real multipart, workflow, protected immutable downloads and logout.')
        finally:
            subprocess.run(['docker','rm','-f',name],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
            # Fixture www-data owns its files after the server test; remove it in the container.
            subprocess.run(['docker','run','--rm','-v',str(fixture)+':/cleanup','php:8.3-apache',
                            'sh','-c','chown -R '+str(os.getuid())+':'+str(os.getgid())+' /cleanup'],
                           stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,check=True)

if __name__=='__main__':
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--package',type=Path,default=ROOT/'var/deployment/motorworld-csvstudio-ftp.zip')
    parser.add_argument('--browser',action='store_true',help='Also exercise the actual PHP interface in Chromium')
    run(parser.parse_args())
