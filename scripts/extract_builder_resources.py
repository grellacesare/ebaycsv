"""Extract supplied datasets as data; never execute the builder's JS/PHP."""
import argparse
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
    (output/'resource_manifest.json').write_text(json.dumps(manifest,indent=2)+'\n')
    return text

if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('--builder-html',type=Path,required=True);p.add_argument('--prestashop-categories',type=Path,required=True);p.add_argument('--output',type=Path,default=Path('backend/src/motorworld_csv/resources'))
    a=p.parse_args();extract(a.builder_html,a.prestashop_categories,a.output)
