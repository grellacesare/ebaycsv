"""Extract supplied datasets as data; never execute the builder's JS/PHP."""
import argparse
import base64
import csv
import hashlib
import json
import re
from pathlib import Path

def extract(builder: Path,shop_categories: Path,output: Path):
    raw=builder.read_bytes();text=raw.decode('utf-8')
    start=re.search(r'MW_EBAY_CATEGORIES\s*=\s*(\[)',text).start(1)
    categories=json.JSONDecoder().raw_decode(text[start:])[0]
    assert len(categories)==714 and len({str(c['id']) for c in categories})==714
    output.mkdir(parents=True,exist_ok=True)
    for c in categories:
        c['id']=str(c['id'])
        lower=c['path'].casefold()
        c['context']='scooter' if 'scooter' in lower else ('moto' if lower.startswith('moto:') else 'unknown')
    with shop_categories.open(encoding='utf-8-sig',newline='') as f:
        shop=[dict(id=r['Id'],name=r['Nome'],description=r['Descrizione'],position=int(r['Posizione']),shown=r['Mostrata']=='1') for r in csv.DictReader(f,delimiter=';')]
    rules=[]
    for family,moto,scooter in [('Carburatori','177773','82205'),('Filtri aria','177771','182241'),('Pastiglie freno','177925',None),('Batterie','49782',None),('Candele','177958',None)]:
        for context,id in [('moto',moto),('scooter',scooter)]:
            if id:rules.append(dict(family=family,context=context,destination='ebay',category_id=id))
    resources={'ebay_categories.json':categories,'prestashop_categories.json':shop,'category_rules.json':rules}
    for name,value in resources.items():
        (output/name).write_text(json.dumps(value,ensure_ascii=False,indent=2)+'\n')
    manifest={'builder_sha256':hashlib.sha256(raw).hexdigest(),'shop_categories_sha256':hashlib.sha256(shop_categories.read_bytes()).hexdigest(),
        'source':'Builder.rar / BUILDER.html; category_2026-10-09_120457.csv','taxonomy_status':'User-supplied dataset; live eBay taxonomy not verified',
        'ebay_count':len(categories),'prestashop_count':len(shop)}
    extract_template(text,output,manifest)
    (output/'resource_manifest.json').write_text(json.dumps(manifest,indent=2)+'\n')
    return text

def extract_template(text,output,manifest):
    from bs4 import BeautifulSoup, NavigableString
    soup=BeautifulSoup(text,'html.parser')
    css=soup.find(id='template-style').get_text().strip()
    root=soup.find(id='preview-root')
    logo=root.select_one('img.mw-brand-logo')
    logo_bytes=base64.b64decode(logo['src'].split(',',1)[1],validate=True)
    (output/'motorworld-logo.png').write_bytes(logo_bytes)
    logo['src']='{{ profile.logo_url }}'
    logo.insert_before(NavigableString('{% if profile.logo_url %}'));logo.insert_after(NavigableString('{% endif %}'))
    root.find(id='pv-manuf-logo-wrap').decompose()
    root.find(id='pv-logos-strip').decompose()
    for id,value in [('pv-title','{{ title }}'),('pv-sku','{{ reference }}'),('pv-ean-inline','{{ product.ean }}')]:
        tag=root.find(id=id);tag.clear();tag.append(NavigableString(value))
    ean=root.find(id='pv-ean-wrap');ean.insert_before(NavigableString('{% if product.ean %}'));ean.insert_after(NavigableString('{% endif %}'))
    for td in root.select('td[id^="pv-"]'):
        key=td['id'][3:];td.clear();td.append(NavigableString("{{ details['"+key+"'] }}"))
        tr=td.parent;tr.insert_before(NavigableString("{% if details['"+key+"'] %}"));tr.insert_after(NavigableString('{% endif %}'))
    replacements={
      'pv-images-wrapper':'{% if image_urls %}<div class="mw-block" id="pv-images-wrapper"><h2>Immagini prodotto</h2><div class="mw-images">{% for url in image_urls %}<div class="mw-imgbox"><img src="{{ url }}" alt="{{ product.name }}" /></div>{% endfor %}</div></div>{% endif %}',
      'pv-desc-block':'<div class="mw-block" id="pv-desc-block"><h2>Descrizione</h2><p>{{ description | nl2br }}</p>{% if features %}<ul class="mw-list">{% for item in features %}<li>{{ item }}</li>{% endfor %}</ul>{% endif %}</div>',
      'pv-box-block':'{% if box %}<div class="mw-block"><h2>Contenuto Confezione</h2><ul class="mw-list">{% for item in box %}<li>{{ item }}</li>{% endfor %}</ul></div>{% endif %}',
      'pv-compat-block':'{% if fitment_labels %}<div class="mw-block" id="pv-compat-block"><h2>Compatibilita / Fitment</h2><p>Verifica la compatibilità con il tuo veicolo prima dell’acquisto.</p><ul class="mw-list">{% for item in fitment_labels %}<li>{{ item }}</li>{% endfor %}</ul></div>{% endif %}',
      'pv-notes-block':'{% if notes %}<div class="mw-block"><h2>Note tecniche</h2><p>{{ notes | nl2br }}</p></div>{% endif %}'
    }
    for id,fragment in replacements.items():root.find(id=id).replace_with(NavigableString('__MW_'+id+'__'))
    infobars=root.select('.mw-infobar')
    infobars[0].clear();infobars[0].append(NavigableString('__MW_SHIPPING__'))
    template=str(root).replace('__MW_SHIPPING__','<b>Spedizione</b><br/>{{ profile.shipping_text }}')
    for id,fragment in replacements.items():template=template.replace('__MW_'+id+'__',fragment)
    template=template.replace('30 giorni','{{ profile.return_days }} giorni')
    template='\n'.join(line.rstrip() for line in template.replace('\r\n','\n').splitlines())
    css='\n'.join(line.rstrip() for line in css.replace('\r\n','\n').splitlines())
    (output/'ebay_description.html.j2').write_text('<style>{{ css | safe }}</style>\n'+template+'\n')
    (output/'ebay_template.css').write_text(css+'\n')
    start=re.search(r'var\s+CSV_HEADERS\s*=\s*(\[)',text).start(1)
    headers=json.JSONDecoder().raw_decode(text[start:])[0]+['Relationship','RelationshipDetails']
    assert len(headers)==55
    store=[{'id':o.get('value'),'name':o.get_text(strip=True)} for o in soup.find(id='csv-storeCategory').find_all('option') if o.get('value')]
    for name,value in [('ebay_headers.json',headers),('ebay_store_categories.json',store)]:
        (output/name).write_text(json.dumps(value,ensure_ascii=False,indent=2)+'\n')
    manifest.update(ebay_header_count=len(headers),ebay_store_category_count=len(store),logo_sha256=hashlib.sha256(logo_bytes).hexdigest(),template_style_sha256=hashlib.sha256(css.encode()).hexdigest())

if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('--builder-html',type=Path,required=True);p.add_argument('--prestashop-categories',type=Path,required=True);p.add_argument('--output',type=Path,default=Path('backend/src/motorworld_csv/resources'))
    a=p.parse_args();extract(a.builder_html,a.prestashop_categories,a.output)
