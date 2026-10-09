from pathlib import Path
from .schemas import ImportSummary
from ..storage import Repository, ProductFilters
from ..domain import Progress, PriceSettings
from ..jobs import JobService
from ..importing.models import ImportProfile, InputSource
from ..importing.service import import_catalog
from ..categories.catalog import load_catalog
from ..categories.models import CategoryCatalog
from ..platforms.profiles import EbayProfile, PrestashopProfile
from ..pipeline import compile_drafts
from ..exporting.service import export_selected
from ..errors import DomainError

RESOURCES=Path(__file__).parents[1]/'resources'

class AppContext:
    def __init__(self,data_dir: Path):
        self.data_dir=data_dir
        self.repo=Repository(data_dir/'archive.sqlite')
        self.jobs: JobService | None=None

    def catalog(self) -> CategoryCatalog:
        try:return CategoryCatalog.model_validate(self.repo.get_record('catalog','active'))
        except KeyError:return load_catalog(RESOURCES)

    def ensure_idle(self):
        if self.repo.list_jobs(['queued','running']):
            raise DomainError('job_busy','Attendi il completamento della lavorazione prima di modificare i dati o le impostazioni.')

    def start(self):
        self.jobs=JobService(self.repo,self.data_dir)
        self.jobs.register('import',self.import_handler)
        self.jobs.register('validate',self.validate_handler)
        self.jobs.register('export',self.export_handler)

    def import_handler(self,payload,progress):
        sources=[InputSource(id=r['upload_id'],path=self.data_dir/'uploads'/(r['upload_id']+'.csv'),role=r['role'],name=self.repo.get_record('upload',r['upload_id'])['name']) for r in payload['uploads']]
        result=import_catalog(sources,ImportProfile.model_validate(payload['profile']),progress)
        id=payload['_job_id']
        self.repo.save_import(id,result)
        return {'import_id':id,'product_count':len(result.products),'diagnostic_count':len(result.diagnostics)}

    def validate_handler(self,payload,progress):
        id,destination=payload['import_id'],payload['destination']
        price=PriceSettings.model_validate(payload['price_settings'])
        profile=(EbayProfile if destination=='ebay' else PrestashopProfile).model_validate(payload['profile'])
        catalog=CategoryCatalog.model_validate(payload['catalog'])
        total=self.repo.list_products(id,ProductFilters(),0,1).total
        drafts=[];completed=0;rules_by_supplier={}
        for offset in range(0,total,200):
            products=self.repo.list_products(id,ProductFilters(),offset,200).items
            for product in products:
                if product.supplier_id not in rules_by_supplier:
                    rules_by_supplier[product.supplier_id]=self.repo.list_category_rules(product.supplier_id)
                rules=rules_by_supplier[product.supplier_id]
                drafts.extend(compile_drafts(product,self.repo.get_compatibilities(product.id),price,profile,catalog,rules,self.repo.get_overrides(product.id)))
                completed+=1
            progress(Progress(stage='Categorie, prezzi e controllo',completed=completed,total=total,unit='prodotti'))
        self.repo.save_drafts(id,drafts)
        self.repo.put_record('validation',id+':'+destination,{'profile':profile.model_dump(mode='json'),'price_settings':price.model_dump(mode='json'),'catalog_version':catalog.version})
        page=self.repo.list_drafts(id,destination,None,0,1)
        return {'import_id':id,'destination':destination,'counts':page.counts}

    def export_handler(self,payload,progress):
        ids=payload['draft_ids']
        if payload['selection_mode']=='new_valid':
            ids=[];offset=0
            while True:
                page=self.repo.list_drafts(payload['import_id'],payload['destination'],payload.get('state'),offset,200,
                    query=payload.get('query',''),category_id=payload.get('category_id'),available=payload.get('available'))
                ids.extend(d.id for d in page.items if not d.exported)
                offset+=len(page.items)
                if offset>=page.total or not page.items:break
        record=export_selected(self.repo,payload['import_id'],payload['destination'],ids,self.data_dir/'exports'/payload['_job_id'],progress)
        return {'export_id':record.id,'included_count':len(record.included_ids),'excluded_count':len(record.excluded_ids)}

    def import_summary(self,id):
        record=self.repo.get_import_summary(id)
        return ImportSummary(**{k:record[k] for k in ImportSummary.model_fields})
