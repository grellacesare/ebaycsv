from pathlib import Path
from contextlib import contextmanager
from datetime import datetime, timezone
import json
import sqlite3
from pydantic import Field
from .domain import Model, Product, Compatibility, ListingOverrides
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
            for p in result.products:
                self._write_product(db,p)
                db.execute('INSERT OR IGNORE INTO import_products VALUES(?,?)',(id,p.id))

    def get_import(self,id: str) -> dict:
        return self.get_record('import',id)

    def _write_product(self,db,product):
        db.execute('INSERT INTO products VALUES(?,?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET name=excluded.name,available=excluded.available,payload=excluded.payload',
            (product.id,product.supplier_id,product.code,product.name,product.available,product.model_dump_json()))

    def get_product(self,id: str) -> Product:
        with self.connection() as db:
            row=db.execute('SELECT payload FROM products WHERE id=?',(id,)).fetchone()
        if not row:
            raise KeyError(id)
        return Product.model_validate_json(row['payload'])

    def save_product(self,product: Product) -> None:
        with self.connection() as db:
            self._write_product(db,product)

    def list_products(self,import_id: str | None,filters: ProductFilters,offset: int,limit: int) -> ProductPage:
        where, params = [], []
        if import_id:
            where.append('p.id IN (SELECT product_id FROM import_products WHERE import_id=?)'); params.append(import_id)
        if filters.query:
            where.append('(p.code LIKE ? OR p.name LIKE ?)');params.extend(['%'+filters.query+'%']*2)
        if filters.available is not None:
            where.append('p.available=?'); params.append(int(filters.available))
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
        # IDs encode a hash rather than a product key; callers select only their known draft IDs.
        with self.connection() as db:
            return {r['listing_id']:ListingOverrides.model_validate_json(r['payload']) for r in db.execute('SELECT * FROM overrides')}

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
