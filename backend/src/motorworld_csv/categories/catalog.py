from pathlib import Path
import csv
import json
from .models import CategoryCatalog, MarketplaceCategory, StoreCategory, CategoryRule
from ..domain import Diagnostic
from ..errors import DomainError

def load_catalog(resources_dir: Path) -> CategoryCatalog:
    def read(name):return json.loads((resources_dir/name).read_text())
    return CategoryCatalog(ebay=[MarketplaceCategory(**r) for r in read('ebay_categories.json')],
        prestashop=[StoreCategory(**r) for r in read('prestashop_categories.json')],seed_rules=read('category_rules.json'),
        version=read('resource_manifest.json')['builder_sha256'])

def import_prestashop_categories(path: Path) -> list[StoreCategory]:
    result=[]
    with path.open(encoding='utf-8-sig',newline='') as f:
        reader=csv.DictReader(f,delimiter=';')
        if not {'Id','Nome','Mostrata'} <= set(reader.fieldnames or []):
            raise DomainError('invalid_category_file','Il repertorio deve avere Id, Nome e Mostrata.')
        for row in reader:
            id=row['Id'].strip()
            if not id.isdigit() or int(id)<=0 or not row['Nome'].strip() or row['Mostrata'] not in ('0','1'):
                raise DomainError('invalid_category_file',f'Categoria non valida alla riga {reader.line_num}.')
            result.append(StoreCategory(id=id,name=row['Nome'],description=row.get('Descrizione',''),position=int(row.get('Posizione') or 0),shown=row['Mostrata']=='1'))
    if not result or len({r.id for r in result})!=len(result):
        raise DomainError('invalid_category_file','Categorie vuote o ID duplicati.')
    return result

def validate_catalog_replacement(current: CategoryCatalog,replacement: CategoryCatalog,rules: list[CategoryRule]) -> list[Diagnostic]:
    valid={'ebay':{r.id for r in replacement.ebay},'prestashop':{r.id for r in replacement.prestashop}}
    return [Diagnostic(level='error',code='stale_category_rule',message=f'La categoria {r.category_id} dell’associazione {r.family} non è più presente.',hint='Scegli una categoria del nuovo repertorio.') for r in rules if r.category_id not in valid[r.destination]]
