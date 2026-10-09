from pathlib import Path
from contextlib import contextmanager
from datetime import datetime, timezone
import json
import sqlite3
from collections import Counter
from pydantic import Field
from .domain import Model, Product, Compatibility, ListingOverrides, ListingDraft, Diagnostic
from .errors import DomainError
from .importing.models import ImportProfile, ImportResult

class ProductFilters(Model):
    query: str = ''
    available: bool | None = None
    category_id: str | None = None
    state: str | None = None
    destination: str | None = None

class ProductPage(Model):
    items: list[Product]
    total: int

class DraftPage(Model):
    items: list[ListingDraft]
    total: int
    counts: dict[str,int]

class Repository:
    def __init__(self,db_path: Path):
        self.db_path = Path(db_path)
        self.db_path.parent.mkdir(parents=True,exist_ok=True)
        with self.connection() as db:
            db.execute('PRAGMA journal_mode=WAL')
            db.executescript('''
                CREATE TABLE IF NOT EXISTS schema_version(version INTEGER NOT NULL);
                INSERT INTO schema_version SELECT 1 WHERE NOT EXISTS(SELECT 1 FROM schema_version);
                CREATE TABLE IF NOT EXISTS records(kind TEXT NOT NULL,id TEXT NOT NULL,payload TEXT NOT NULL,PRIMARY KEY(kind,id));
                CREATE TABLE IF NOT EXISTS products(id TEXT PRIMARY KEY,supplier_id TEXT NOT NULL,code TEXT NOT NULL,name TEXT NOT NULL,available INTEGER,payload TEXT NOT NULL,UNIQUE(supplier_id,code));
                CREATE TABLE IF NOT EXISTS import_products(import_id TEXT NOT NULL,product_id TEXT NOT NULL,PRIMARY KEY(import_id,product_id));
                CREATE TABLE IF NOT EXISTS compatibilities(product_id TEXT NOT NULL,id TEXT PRIMARY KEY,position INTEGER NOT NULL,payload TEXT NOT NULL);
                CREATE TABLE IF NOT EXISTS overrides(listing_id TEXT PRIMARY KEY,payload TEXT NOT NULL);
                CREATE TABLE IF NOT EXISTS jobs(id TEXT PRIMARY KEY,kind TEXT NOT NULL,request_key TEXT NOT NULL UNIQUE,state TEXT NOT NULL,payload TEXT NOT NULL);
                CREATE INDEX IF NOT EXISTS product_search ON products(code,name);
                CREATE INDEX IF NOT EXISTS membership_product ON import_products(product_id);
                CREATE TABLE IF NOT EXISTS drafts(import_id TEXT NOT NULL,id TEXT NOT NULL,product_id TEXT NOT NULL,destination TEXT NOT NULL,reference TEXT NOT NULL,title TEXT NOT NULL,valid INTEGER NOT NULL,exported INTEGER NOT NULL,stale INTEGER NOT NULL,payload TEXT NOT NULL,PRIMARY KEY(import_id,id));
                CREATE INDEX IF NOT EXISTS draft_product ON drafts(product_id);
                CREATE INDEX IF NOT EXISTS draft_reference ON drafts(destination,reference);
                UPDATE schema_version SET version=2 WHERE version<2;
            ''')

    @contextmanager
    def connection(self):
        db = sqlite3.connect(self.db_path,timeout=30)
        db.row_factory = sqlite3.Row
        try:
            with db:
                yield db
        finally:
            db.close()

    def put_record(self,kind: str,id: str,payload: dict):
        with self.connection() as db:
            db.execute('INSERT INTO records VALUES(?,?,?) ON CONFLICT(kind,id) DO UPDATE SET payload=excluded.payload',
                (kind,id,json.dumps(payload,ensure_ascii=False)))

    def get_record(self,kind: str,id: str) -> dict:
        with self.connection() as db:
            row=db.execute('SELECT payload FROM records WHERE kind=? AND id=?',(kind,id)).fetchone()
        if not row:
            raise KeyError(id)
        return json.loads(row['payload'])

    def list_records(self,kind: str) -> list[dict]:
        with self.connection() as db:
            return [json.loads(r['payload']) for r in db.execute('SELECT payload FROM records WHERE kind=? ORDER BY rowid DESC',(kind,))]

    def save_profile(self,profile: ImportProfile) -> str:
        self.put_record('supplier_profile',profile.id,profile.model_dump(mode='json'))
        return profile.id

    def get_profile(self,id: str) -> ImportProfile:
        return ImportProfile.model_validate(self.get_record('supplier_profile',id))

    def save_import(self,id: str,result: ImportResult) -> None:
        payload = {'id':id,'created_at':datetime.now(timezone.utc).isoformat(),
            'original_products':[p.model_dump(mode='json') for p in result.products],
            'diagnostics':[d.model_dump(mode='json') for d in result.diagnostics],
            'source_counts':result.source_counts,'product_count':len(result.products)}
        with self.connection() as db:
            db.execute('INSERT OR REPLACE INTO records VALUES(?,?,?)',('import',id,json.dumps(payload,ensure_ascii=False)))
            summary={k:v for k,v in payload.items() if k!='original_products'}
            db.execute('INSERT OR REPLACE INTO records VALUES(?,?,?)',('import_summary',id,json.dumps(summary,ensure_ascii=False)))
            for p in result.products:
                db.execute('INSERT OR REPLACE INTO records VALUES(?,?,?)',('product_source',p.id,p.model_dump_json()))
                saved=db.execute("SELECT payload FROM records WHERE kind='product_edits' AND id=?",(p.id,)).fetchone()
                current=p
                if saved:
                    edits=json.loads(saved['payload']);updates=edits['updates']
                    current=Product.model_validate(p.model_dump() | {k:v for k,v in updates.items() if k!='extra'} |
                        {'extra':p.extra | updates.get('extra',{})})
                    current.diagnostics=[d for d in current.diagnostics if d.field not in edits.get('resolved_fields',[])]
                    changed=[k for k,v in updates.items() if k!='extra' and getattr(p,k)!=getattr(current,k)]
                    if changed:
                        current.diagnostics.append(Diagnostic(level='warning',code='manual_correction_retained',
                            message='Correzioni manuali mantenute dopo la nuova importazione: '+', '.join(changed)+'.',
                            hint='Controlla i nuovi dati originali. Usa «Ripristina dati fornitore» per rimuovere le correzioni del prodotto.',product_id=p.id))
                self._write_product(db,current)
                db.execute("UPDATE drafts SET stale=1,payload=json_set(payload,'$.stale',json('true')) WHERE product_id=?",(p.id,))
                db.execute('INSERT OR IGNORE INTO import_products VALUES(?,?)',(id,p.id))

    def get_import(self,id: str) -> dict:
        return self.get_record('import',id)

    def get_import_summary(self,id: str) -> dict:
        return self.get_record('import_summary',id)

    def _write_product(self,db,product):
        db.execute('INSERT INTO products VALUES(?,?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET name=excluded.name,available=excluded.available,payload=excluded.payload',
            (product.id,product.supplier_id,product.code,product.name,product.available,product.model_dump_json()))

    def get_product(self,id: str) -> Product:
        with self.connection() as db:
            row=db.execute('SELECT payload FROM products WHERE id=?',(id,)).fetchone()
        if not row:
            raise KeyError(id)
        return Product.model_validate_json(row['payload'])

    def save_product(self,product: Product,resolved_fields: list[str] | None=None) -> None:
        with self.connection() as db:
            previous=db.execute('SELECT payload FROM products WHERE id=?',(product.id,)).fetchone()
            if previous:
                old=Product.model_validate_json(previous['payload'])
                saved=db.execute("SELECT payload FROM records WHERE kind='product_edits' AND id=?",(product.id,)).fetchone()
                edits=json.loads(saved['payload']) if saved else {'updates':{},'resolved_fields':[]}
                resolved=set(resolved_fields or [])
                resolved.update(d.field for d in old.diagnostics if d.field and d not in product.diagnostics)
                fields=('name','brand','family','department','cost','ean','image_urls','available')
                data=product.model_dump(mode='json')
                for field in fields:
                    if getattr(old,field)!=getattr(product,field) or field in resolved:
                        edits['updates'][field]=data[field]
                        resolved.add(field)
                extra={k:v for k,v in product.extra.items() if k not in old.extra or old.extra[k]!=v}
                if extra:edits['updates']['extra']=edits['updates'].get('extra',{}) | extra
                edits['resolved_fields']=sorted(set(edits['resolved_fields']) | resolved)
                db.execute('INSERT OR REPLACE INTO records VALUES(?,?,?)',('product_edits',product.id,json.dumps(edits,ensure_ascii=False)))
            self._write_product(db,product)

    def reset_product_corrections(self,id: str) -> Product:
        with self.connection() as db:
            row=db.execute("SELECT payload FROM records WHERE kind='product_source' AND id=?",(id,)).fetchone()
            if not row:raise DomainError('source_unavailable','Reimporta il prodotto per ripristinare i dati del fornitore.')
            product=Product.model_validate_json(row['payload'])
            db.execute("DELETE FROM records WHERE kind='product_edits' AND id=?",(id,))
            self._write_product(db,product)
            db.execute("UPDATE drafts SET stale=1,payload=json_set(payload,'$.stale',json('true')) WHERE product_id=?",(id,))
            return product

    def list_products(self,import_id: str | None,filters: ProductFilters,offset: int,limit: int) -> ProductPage:
        where, params = [], []
        if import_id:
            where.append('p.id IN (SELECT product_id FROM import_products WHERE import_id=?)'); params.append(import_id)
        if filters.query:
            where.append('(p.code LIKE ? OR p.name LIKE ? OR EXISTS(SELECT 1 FROM drafts d WHERE d.product_id=p.id AND d.title LIKE ?))');params.extend(['%'+filters.query+'%']*3)
        if filters.available is not None:
            where.append('p.available=?'); params.append(int(filters.available))
        if filters.category_id or filters.state:
            dwhere=['d.product_id=p.id']
            if import_id:dwhere.append('d.import_id=?');params.append(import_id)
            if filters.destination:dwhere.append('d.destination=?');params.append(filters.destination)
            if filters.category_id:
                dwhere.append("EXISTS(SELECT 1 FROM json_each(d.payload,'$.category_ids') c WHERE c.value=?)");params.append(filters.category_id)
            if filters.state:dwhere.append(self._state_clause(filters.state,'d.'))
            where.append('EXISTS(SELECT 1 FROM drafts d WHERE '+' AND '.join(dwhere)+')')
        clause = ' WHERE '+' AND '.join(where) if where else ''
        with self.connection() as db:
            total=db.execute('SELECT COUNT(*) FROM products p'+clause,params).fetchone()[0]
            rows=db.execute('SELECT payload FROM products p'+clause+' ORDER BY p.code LIMIT ? OFFSET ?',params+[limit,offset])
            return ProductPage(items=[Product.model_validate_json(r['payload']) for r in rows],total=total)

    def save_compatibilities(self,product_id: str,rows: list[Compatibility]) -> None:
        with self.connection() as db:
            db.execute('DELETE FROM compatibilities WHERE product_id=?',(product_id,))
            for position,row in enumerate(rows):
                db.execute('INSERT INTO compatibilities VALUES(?,?,?,?)',(product_id,row.id,position,row.model_dump_json()))

    def get_compatibilities(self,product_id: str) -> list[Compatibility]:
        with self.connection() as db:
            return [Compatibility.model_validate_json(r['payload']) for r in db.execute('SELECT payload FROM compatibilities WHERE product_id=? ORDER BY position',(product_id,))]

    def save_overrides(self,rows: list[ListingOverrides]) -> None:
        with self.connection() as db:
            for row in rows:
                db.execute('INSERT OR REPLACE INTO overrides VALUES(?,?)',(row.listing_id,row.model_dump_json()))

    def get_overrides(self,product_id: str) -> dict[str,ListingOverrides]:
        with self.connection() as db:
            return {r['listing_id']:ListingOverrides.model_validate_json(r['payload']) for r in db.execute('SELECT DISTINCT o.listing_id,o.payload FROM overrides o JOIN drafts d ON o.listing_id=d.id WHERE d.product_id=?',(product_id,))}

    def save_job(self,job):
        with self.connection() as db:
            db.execute('INSERT INTO jobs VALUES(?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET state=excluded.state,payload=excluded.payload',
                (job.id,job.kind,job.request_key,job.state,job.model_dump_json()))

    def create_job(self,job):
        from .jobs import Job
        with self.connection() as db:
            db.execute('INSERT OR IGNORE INTO jobs VALUES(?,?,?,?,?)',(job.id,job.kind,job.request_key,job.state,job.model_dump_json()))
            row=db.execute('SELECT payload FROM jobs WHERE request_key=?',(job.request_key,)).fetchone()
        return Job.model_validate_json(row['payload'])

    def get_job(self,id: str):
        from .jobs import Job
        with self.connection() as db:
            row=db.execute('SELECT payload FROM jobs WHERE id=?',(id,)).fetchone()
        if not row: raise KeyError(id)
        return Job.model_validate_json(row['payload'])

    def list_jobs(self,states=None):
        from .jobs import Job
        with self.connection() as db:
            jobs=[Job.model_validate_json(r['payload']) for r in db.execute('SELECT payload FROM jobs ORDER BY rowid')]
        return [j for j in jobs if states is None or j.state in states]

    def save_category_rule(self,rule):
        from .categories.matching import normalize_key
        key='\0'.join([rule.supplier_id,normalize_key(rule.family),rule.context,rule.destination])
        self.put_record('category_rule',key,rule.model_dump(mode='json'))

    def list_category_rules(self,supplier_id: str):
        from .categories.models import CategoryRule
        return [CategoryRule.model_validate(r) for r in self.list_records('category_rule') if r['supplier_id']==supplier_id]

    def save_platform_profile(self,profile):
        self.put_record('platform_profile',profile.destination,profile.model_dump(mode='json'))

    def get_platform_profile(self,destination: str):
        from .platforms.profiles import EbayProfile, PrestashopProfile
        cls=EbayProfile if destination=='ebay' else PrestashopProfile
        try:return cls.model_validate(self.get_record('platform_profile',destination))
        except KeyError:return cls()

    def save_drafts(self,import_id: str,drafts: list[ListingDraft]) -> None:
        if len({d.id for d in drafts})!=len(drafts):
            raise DomainError('listing_identity_collision','Riferimenti interni duplicati: correggi le compatibilità.')
        destinations={d.destination for d in drafts}
        with self.connection() as db:
            exported={r['id'] for r in db.execute('SELECT DISTINCT id FROM drafts WHERE exported=1')}
            exported.update(r['id'] for r in db.execute("SELECT DISTINCT j.value AS id FROM records e JOIN json_each(e.payload,'$.included_ids') j WHERE e.kind='export'"))
            for destination in destinations:
                db.execute('DELETE FROM drafts WHERE import_id=? AND destination=?',(import_id,destination))
            duplicates=Counter((d.destination,d.reference) for d in drafts)
            existing={(r['destination'],r['reference']):r['product_id'] for r in db.execute('SELECT destination,reference,product_id FROM drafts')}
            for draft in drafts:
                d=draft.model_copy(deep=True);d.exported=d.id in exported
                if duplicates[(d.destination,d.reference)]>1 or (existing.get((d.destination,d.reference)) not in (None,d.product_id)):
                    d.valid=False
                    d.diagnostics.append(Diagnostic(level='error',code='duplicate_reference',message='Riferimento già utilizzato per un altro prodotto/inserzione.',hint='Imposta un prefisso o un riferimento univoco.',field='reference',value=d.reference,product_id=d.product_id,listing_id=d.id))
                db.execute('INSERT INTO drafts VALUES(?,?,?,?,?,?,?,?,?,?)',(import_id,d.id,d.product_id,d.destination,d.reference,d.title,d.valid,d.exported,d.stale,d.model_dump_json()))

    def get_draft(self,id: str,import_id: str | None=None) -> ListingDraft:
        with self.connection() as db:
            sql='SELECT payload FROM drafts WHERE id=?';params=[id]
            if import_id:sql+=' AND import_id=?';params.append(import_id)
            row=db.execute(sql+' ORDER BY rowid DESC LIMIT 1',params).fetchone()
        if not row:raise KeyError(id)
        return ListingDraft.model_validate_json(row['payload'])

    @staticmethod
    def _state_clause(state: str,prefix=''):
        p=prefix
        return {'ready':f'{p}valid=1 AND {p}stale=0 AND {p}exported=0','valid':f'{p}valid=1 AND {p}stale=0',
            'errors':f'({p}valid=0 OR {p}stale=1)','exported':f'{p}exported=1','stale':f'{p}stale=1',
            'warnings':f"EXISTS(SELECT 1 FROM json_each({p}payload,'$.diagnostics') j WHERE json_extract(j.value,'$.level')='warning')"}.get(state,'1=1')

    def list_drafts(self,import_id: str,destination: str,state: str | None,offset: int,limit: int,
        query: str='',category_id: str | None=None,available: bool | None=None) -> DraftPage:
        where=['import_id=?','destination=?'];params=[import_id,destination]
        if state:where.append(self._state_clause(state))
        if query:
            where.append('(title LIKE ? OR product_id IN (SELECT id FROM products WHERE code LIKE ?))');params.extend(['%'+query+'%']*2)
        if category_id:
            where.append("EXISTS(SELECT 1 FROM json_each(payload,'$.category_ids') c WHERE c.value=?)");params.append(category_id)
        if available is not None:
            where.append('product_id IN (SELECT id FROM products WHERE available=?)');params.append(int(available))
        with self.connection() as db:
            clause=' WHERE '+' AND '.join(where)
            total=db.execute('SELECT COUNT(*) FROM drafts'+clause,params).fetchone()[0]
            items=[ListingDraft.model_validate_json(r['payload']) for r in db.execute('SELECT payload FROM drafts'+clause+' ORDER BY rowid LIMIT ? OFFSET ?',params+[limit,offset])]
            counts={'total':db.execute('SELECT COUNT(*) FROM drafts WHERE import_id=? AND destination=?',(import_id,destination)).fetchone()[0]}
            for name in ('ready','errors','warnings','exported','stale'):
                counts[name]=db.execute('SELECT COUNT(*) FROM drafts WHERE import_id=? AND destination=? AND '+self._state_clause(name),(import_id,destination)).fetchone()[0]
        return DraftPage(items=items,total=total,counts=counts)

    def iter_drafts(self,import_id: str,destination: str):
        with self.connection() as db:
            for row in db.execute('SELECT payload FROM drafts WHERE import_id=? AND destination=? ORDER BY rowid',(import_id,destination)):
                yield ListingDraft.model_validate_json(row['payload'])

    def mark_stale(self,product_id: str | None=None,destination: str | None=None,import_id: str | None=None):
        where=[];params=[]
        for field,value in [('product_id',product_id),('destination',destination),('import_id',import_id)]:
            if value is not None:where.append(field+'=?');params.append(value)
        with self.connection() as db:
            db.execute("UPDATE drafts SET stale=1,payload=json_set(payload,'$.stale',json('true'))"+(' WHERE '+' AND '.join(where) if where else ''),params)

    def record_export(self,export):
        with self.connection() as db:
            db.execute('INSERT OR IGNORE INTO records VALUES(?,?,?)',('export',export.id,export.model_dump_json()))
            for start in range(0,len(export.included_ids),500):
                ids=export.included_ids[start:start+500]
                db.execute("UPDATE drafts SET exported=1,payload=json_set(payload,'$.exported',json('true')) WHERE id IN ("+','.join('?' for _ in ids)+')',ids)

    def get_export(self,id: str):
        from .exporting.contracts import ExportRecord
        return ExportRecord.model_validate(self.get_record('export',id))

    def list_exports(self,import_id: str | None=None):
        from .exporting.contracts import ExportRecord
        return [ExportRecord.model_validate(r) for r in self.list_records('export') if import_id is None or r['import_id']==import_id]
