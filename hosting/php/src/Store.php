<?php
declare(strict_types=1);

namespace MotorWorld\Hosting;

use PDO;
use PDOStatement;
use Throwable;

/** SQLite v2 archive with additive, private job staging. */
final class Store
{
    private PDO $db;
    private string $directory;
    private int $savepoint = 0;

    public function __construct(string $dataDir)
    {
        if (!is_dir($dataDir) && !@mkdir($dataDir, 0700, true) && !is_dir($dataDir)) {
            throw new AppError('storage_unavailable', 'Archivio non disponibile.', 503);
        }
        $this->directory = rtrim((string)realpath($dataDir), DIRECTORY_SEPARATOR);
        if ($this->directory === '') throw new AppError('storage_unavailable', 'Archivio non disponibile.', 503);
        // WAL conversion and schema creation can race before SQLite has an existing
        // journal. Serialize this short bootstrap separately from processing locks.
        $initialization=@fopen($this->directory.'/.archive-initialize.lock','c');
        if ($initialization === false || !flock($initialization,LOCK_EX)) throw new AppError('storage_unavailable','Archivio non disponibile.',503);
        try {
            $this->db = new PDO('sqlite:' . $this->directory . '/archive.sqlite', null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            @chmod($this->directory.'/archive.sqlite',0600);
            $this->db->exec('PRAGMA busy_timeout=30000');
            $this->db->exec('PRAGMA journal_mode=WAL');
            $names = $this->db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
            $required = ['schema_version','records','products','import_products','compatibilities','overrides','jobs','drafts','job_checkpoints','staging_products','staging_drafts','staging_records'];
            if (array_diff($required, $names)) $this->initialize();
            if ((int)$this->db->query('SELECT version FROM schema_version LIMIT 1')->fetchColumn() < 2) {
                $this->db->exec('UPDATE schema_version SET version=2 WHERE version<2');
            }
        } finally { flock($initialization,LOCK_UN); fclose($initialization); }
    }

    private function initialize(): void
    {
        $this->db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS schema_version(version INTEGER NOT NULL);
INSERT INTO schema_version SELECT 2 WHERE NOT EXISTS(SELECT 1 FROM schema_version);
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
CREATE INDEX IF NOT EXISTS draft_scope ON drafts(import_id,destination);
CREATE INDEX IF NOT EXISTS draft_ready ON drafts(import_id,destination,valid,stale,exported);
CREATE TABLE IF NOT EXISTS job_checkpoints(job_id TEXT PRIMARY KEY,payload TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS staging_products(job_id TEXT NOT NULL,id TEXT NOT NULL,position INTEGER NOT NULL,payload TEXT NOT NULL,source_payload TEXT,PRIMARY KEY(job_id,id));
CREATE INDEX IF NOT EXISTS staged_product_order ON staging_products(job_id,position,id);
CREATE TABLE IF NOT EXISTS staging_drafts(job_id TEXT NOT NULL,id TEXT NOT NULL,position INTEGER NOT NULL,payload TEXT NOT NULL,PRIMARY KEY(job_id,id));
CREATE INDEX IF NOT EXISTS staged_draft_order ON staging_drafts(job_id,position,id);
CREATE INDEX IF NOT EXISTS staged_draft_reference ON staging_drafts(job_id,json_extract(payload,'$.destination'),json_extract(payload,'$.reference'));
CREATE TABLE IF NOT EXISTS staging_records(job_id TEXT NOT NULL,kind TEXT NOT NULL,id TEXT NOT NULL,position INTEGER NOT NULL,payload TEXT NOT NULL,PRIMARY KEY(job_id,kind,id));
CREATE INDEX IF NOT EXISTS staged_record_order ON staging_records(job_id,kind,position,id);
SQL);
    }

    public function pdo(): PDO { return $this->db; }
    public function dataDir(): string { return $this->directory; }

    /** Nested callers use savepoints; only the outer operation commits publication. */
    public function transaction(callable $operation): mixed
    {
        $nested = $this->db->inTransaction();
        $name = 'mw_' . (++$this->savepoint);
        if ($nested) $this->db->exec('SAVEPOINT ' . $name); else $this->db->beginTransaction();
        try {
            // Reserve the SQLite writer before reading a checkpoint. PDO does not track
            // SQL BEGIN IMMEDIATE; a no-row UPDATE gives the same reservation while
            // retaining PDO's transaction and nested savepoint bookkeeping.
            if (!$nested) $this->db->exec('UPDATE schema_version SET version=version WHERE 0');
            $result = $operation();
            if ($nested) $this->db->exec('RELEASE SAVEPOINT ' . $name); else $this->db->commit();
            return $result;
        } catch (Throwable $error) {
            if ($nested) { $this->db->exec('ROLLBACK TO SAVEPOINT ' . $name); $this->db->exec('RELEASE SAVEPOINT ' . $name); }
            elseif ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }

    private function run(string $sql, array $params = []): PDOStatement
    {
        $statement = $this->db->prepare($sql);
        foreach (array_values($params) as $index => $value) {
            $type = is_int($value) || is_bool($value) ? PDO::PARAM_INT : ($value === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $statement->bindValue($index + 1, $value, $type);
        }
        $statement->execute();
        return $statement;
    }

    private static function decode(string $payload): array { return json_decode($payload, true, 512, JSON_THROW_ON_ERROR); }
    private static function encode(array $payload): string { return Support::json($payload); }
    private function optional(string $sql, array $params): ?array
    {
        $payload = $this->run($sql, $params)->fetchColumn();
        return $payload === false ? null : self::decode($payload);
    }
    private function arrays(string $sql, array $params = []): array
    {
        return array_map(static fn($row) => self::decode($row['payload']), $this->run($sql, $params)->fetchAll());
    }

    public function put_record(string $kind, string $id, array $payload): void
    {
        $this->run('INSERT INTO records VALUES(?,?,?) ON CONFLICT(kind,id) DO UPDATE SET payload=excluded.payload', [$kind,$id,self::encode($payload)]);
    }
    public function get_record(string $kind, string $id): array
    {
        return $this->optional('SELECT payload FROM records WHERE kind=? AND id=?', [$kind,$id]) ?? throw new NotFound($id);
    }
    public function list_records(string $kind): array { return $this->arrays('SELECT payload FROM records WHERE kind=? ORDER BY rowid DESC', [$kind]); }
    public function save_profile(array $profile): string { $this->put_record('supplier_profile',$profile['id'],$profile); return $profile['id']; }
    public function get_profile(string $id): array { return $this->get_record('supplier_profile',$id); }

    public function save_import(string $id, array $result): void
    {
        $stage = 'save-import-' . Support::uuid();
        $this->transaction(function() use($stage,$id,$result): void {
            foreach ($result['products'] as $position => $product) $this->stage_product($stage,$this->prepare_import_product($product),$position,$product);
            $this->publish_import($stage,$id,['diagnostics'=>$result['diagnostics'] ?? [],'source_counts'=>$result['source_counts'] ?? []]);
            $this->clear_staging($stage);
        });
    }
    public function get_import(string $id): array { return $this->get_record('import',$id); }
    public function get_import_summary(string $id): array { return $this->get_record('import_summary',$id); }

    /** Merge operator corrections during a bounded importer batch, before publication. */
    public function prepare_import_product(array $product): array
    {
        $edits=$this->optional("SELECT payload FROM records WHERE kind='product_edits' AND id=?",[$product['id']]);
        if ($edits === null) return $product;
        $updates=$edits['updates']; $changed=[];
        foreach ($updates as $field=>$value) {
            if ($field === 'extra') continue;
            if (($product[$field] ?? null) !== $value) $changed[]=$field;
            $product[$field]=$value;
        }
        $product['extra']=array_replace($product['extra'] ?? [],$updates['extra'] ?? []);
        $product['diagnostics']=array_values(array_filter($product['diagnostics'] ?? [],static fn($d)=>!in_array($d['field'] ?? null,$edits['resolved_fields'],true)));
        if ($changed) $product['diagnostics'][]=Support::diagnostic('warning','manual_correction_retained','Correzioni manuali mantenute dopo la nuova importazione: '.implode(', ',$changed).'.',[
            'hint'=>'Controlla i nuovi dati originali. Usa «Ripristina dati fornitore» per rimuovere le correzioni del prodotto.','product_id'=>$product['id']]);
        return $product;
    }

    private function writeProduct(array $product): void
    {
        $available = ($product['available'] ?? null) === null ? null : (int)$product['available'];
        $this->run('INSERT INTO products VALUES(?,?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET name=excluded.name,available=excluded.available,payload=excluded.payload',
            [$product['id'],$product['supplier_id'],$product['code'],$product['name'] ?? '',$available,self::encode($product)]);
    }
    public function get_product(string $id): array { return $this->optional('SELECT payload FROM products WHERE id=?',[$id]) ?? throw new NotFound($id); }

    public function save_product(array $product, ?array $resolved_fields = null): void
    {
        $this->transaction(function() use($product,$resolved_fields): void {
            $old = $this->optional('SELECT payload FROM products WHERE id=?',[$product['id']]);
            if ($old !== null) {
                $edits = $this->optional("SELECT payload FROM records WHERE kind='product_edits' AND id=?",[$product['id']]) ?? ['updates'=>[],'resolved_fields'=>[]];
                $resolved = $resolved_fields ?? [];
                foreach ($old['diagnostics'] ?? [] as $diagnostic) {
                    if (!empty($diagnostic['field']) && !in_array($diagnostic,$product['diagnostics'] ?? [],true)) $resolved[]=$diagnostic['field'];
                }
                foreach (['name','brand','family','department','cost','ean','image_urls','available'] as $field) {
                    if (($old[$field] ?? null) !== ($product[$field] ?? null) || in_array($field,$resolved,true)) {
                        $edits['updates'][$field] = $product[$field] ?? null; $resolved[]=$field;
                    }
                }
                foreach ($product['extra'] ?? [] as $key=>$value) {
                    if (!array_key_exists($key,$old['extra'] ?? []) || $old['extra'][$key] !== $value) $edits['updates']['extra'][$key]=$value;
                }
                $edits['resolved_fields'] = array_values(array_unique(array_merge($edits['resolved_fields'],$resolved))); sort($edits['resolved_fields']);
                $this->put_record('product_edits',$product['id'],$edits);
            }
            $this->writeProduct($product);
        });
    }

    public function reset_product_corrections(string $id): array
    {
        return $this->transaction(function() use($id): array {
            $product=$this->optional("SELECT payload FROM records WHERE kind='product_source' AND id=?",[$id]);
            if ($product === null) throw new AppError('source_unavailable','Reimporta il prodotto per ripristinare i dati del fornitore.');
            $this->run("DELETE FROM records WHERE kind='product_edits' AND id=?",[$id]);
            $this->writeProduct($product); $this->mark_stale($id); return $product;
        });
    }

    public function list_products(?string $import_id, array $filters, int $offset, int $limit): array
    {
        $where=[]; $params=[];
        if ($import_id) { $where[]='p.id IN (SELECT product_id FROM import_products WHERE import_id=?)'; $params[]=$import_id; }
        if (!empty($filters['query'])) { $where[]='(p.code LIKE ? OR p.name LIKE ? OR EXISTS(SELECT 1 FROM drafts d WHERE d.product_id=p.id AND d.title LIKE ?))'; array_push($params,...array_fill(0,3,'%'.$filters['query'].'%')); }
        if (isset($filters['available'])) { $where[]='p.available=?'; $params[]=(int)$filters['available']; }
        if (!empty($filters['category_id']) || !empty($filters['state'])) {
            $draftWhere=['d.product_id=p.id'];
            if ($import_id) { $draftWhere[]='d.import_id=?'; $params[]=$import_id; }
            if (!empty($filters['destination'])) { $draftWhere[]='d.destination=?'; $params[]=$filters['destination']; }
            if (!empty($filters['category_id'])) { $draftWhere[]="EXISTS(SELECT 1 FROM json_each(d.payload,'$.category_ids') c WHERE c.value=?)"; $params[]=$filters['category_id']; }
            if (!empty($filters['state'])) $draftWhere[]=self::stateClause($filters['state'],'d.');
            $where[]='EXISTS(SELECT 1 FROM drafts d WHERE '.implode(' AND ',$draftWhere).')';
        }
        $clause=$where ? ' WHERE '.implode(' AND ',$where) : '';
        return ['items'=>$this->arrays('SELECT p.payload FROM products p'.$clause.' ORDER BY p.code LIMIT ? OFFSET ?',array_merge($params,[max(0,$limit),max(0,$offset)])),
            'total'=>(int)$this->run('SELECT COUNT(*) FROM products p'.$clause,$params)->fetchColumn()];
    }
    public function count_products(?string $importId): int
    {
        return (int)$this->run('SELECT COUNT(*) FROM products'.($importId===null ? '' : ' WHERE id IN (SELECT product_id FROM import_products WHERE import_id=?)'),$importId===null ? [] : [$importId])->fetchColumn();
    }
    public function iter_products(?string $importId,int $offset=0,int $limit=100): \Generator
    {
        $where=$importId===null ? '' : ' WHERE p.id IN (SELECT product_id FROM import_products WHERE import_id=?)';
        $params=$importId===null ? [] : [$importId];
        $statement=$this->run('SELECT p.payload FROM products p'.$where.' ORDER BY p.code LIMIT ? OFFSET ?',array_merge($params,[max(0,$limit),max(0,$offset)]));
        while (($row=$statement->fetch())!==false) yield self::decode($row['payload']);
    }

    public function save_compatibilities(string $product_id, array $rows): void
    {
        $this->transaction(function() use($product_id,$rows): void {
            $this->run('DELETE FROM compatibilities WHERE product_id=?',[$product_id]);
            foreach ($rows as $position=>$row) $this->run('INSERT INTO compatibilities VALUES(?,?,?,?)',[$product_id,$row['id'],$position,self::encode($row)]);
        });
    }
    public function get_compatibilities(string $product_id): array { return $this->arrays('SELECT payload FROM compatibilities WHERE product_id=? ORDER BY position',[$product_id]); }
    public function save_overrides(array $rows): void
    {
        $this->transaction(function() use($rows): void { foreach ($rows as $row) $this->run('INSERT OR REPLACE INTO overrides VALUES(?,?)',[$row['listing_id'],self::encode($row)]); });
    }
    public function get_overrides(string $product_id): array
    {
        $result=[];
        foreach ($this->run('SELECT DISTINCT o.listing_id,o.payload FROM overrides o JOIN drafts d ON o.listing_id=d.id WHERE d.product_id=?',[$product_id]) as $row) $result[$row['listing_id']]=self::decode($row['payload']);
        return $result;
    }

    public function save_job(array $job): void
    {
        $this->run('INSERT INTO jobs VALUES(?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET state=excluded.state,payload=excluded.payload',[$job['id'],$job['kind'],$job['request_key'],$job['state'],self::encode($job)]);
    }
    public function create_job(array $job): array
    {
        $this->run('INSERT OR IGNORE INTO jobs VALUES(?,?,?,?,?)',[$job['id'],$job['kind'],$job['request_key'],$job['state'],self::encode($job)]);
        return $this->optional('SELECT payload FROM jobs WHERE request_key=?',[$job['request_key']]) ?? throw new NotFound($job['id']);
    }
    public function get_job(string $id): array { return $this->optional('SELECT payload FROM jobs WHERE id=?',[$id]) ?? throw new NotFound($id); }
    public function list_jobs(?array $states=null): array
    {
        if ($states === []) return [];
        return $this->arrays('SELECT payload FROM jobs'.($states === null ? '' : ' WHERE state IN ('.implode(',',array_fill(0,count($states),'?')).')').' ORDER BY rowid',$states ?? []);
    }
    public function get_checkpoint(string $id): array { return $this->optional('SELECT payload FROM job_checkpoints WHERE job_id=?',[$id]) ?? []; }
    public function save_checkpoint(string $id, array $checkpoint): void { $this->run('INSERT INTO job_checkpoints VALUES(?,?) ON CONFLICT(job_id) DO UPDATE SET payload=excluded.payload',[$id,self::encode($checkpoint)]); }

    public function save_category_rule(array $rule): void
    {
        $key=implode("\0",[$rule['supplier_id'],Catalog::normalizeKey($rule['family']),$rule['context'],$rule['destination']]); $this->put_record('category_rule',$key,$rule);
    }
    public function list_category_rules(string $supplier_id): array { return $this->arrays("SELECT payload FROM records WHERE kind='category_rule' AND json_extract(payload,'$.supplier_id')=? ORDER BY rowid DESC",[$supplier_id]); }
    public function save_platform_profile(array $profile): void { $this->put_record('platform_profile',$profile['destination'],$profile); }
    public function get_platform_profile(string $destination): array { return $this->optional("SELECT payload FROM records WHERE kind='platform_profile' AND id=?",[$destination]) ?? Defaults::platform($destination); }

    public function save_drafts(string $import_id, array $drafts): void
    {
        $ids=array_column($drafts,'id');
        if (count(array_unique($ids)) !== count($ids)) throw new AppError('listing_identity_collision','Riferimenti interni duplicati: correggi le compatibilità.');
        $stage='save-drafts-'.Support::uuid();
        $this->transaction(function() use($stage,$import_id,$drafts): void {
            foreach ($drafts as $position=>$draft) $this->stage_draft($stage,$draft,$position);
            $this->publish_drafts($stage,$import_id,array_values(array_unique(array_column($drafts,'destination')))); $this->clear_staging($stage);
        });
    }
    public function get_draft(string $id, ?string $import_id=null): array
    {
        return $this->optional('SELECT payload FROM drafts WHERE id=?'.($import_id ? ' AND import_id=?' : '').' ORDER BY rowid DESC LIMIT 1',$import_id ? [$id,$import_id] : [$id]) ?? throw new NotFound($id);
    }
    private static function stateClause(string $state, string $prefix=''): string
    {
        return match($state) {
            'ready'=>$prefix.'valid=1 AND '.$prefix.'stale=0 AND '.$prefix.'exported=0',
            'valid'=>$prefix.'valid=1 AND '.$prefix.'stale=0',
            'errors'=>'('.$prefix.'valid=0 OR '.$prefix.'stale=1)',
            'exported'=>$prefix.'exported=1', 'stale'=>$prefix.'stale=1',
            'warnings'=>"EXISTS(SELECT 1 FROM json_each({$prefix}payload,'$.diagnostics') j WHERE json_extract(j.value,'$.level')='warning')",
            default=>'1=1',
        };
    }
    public function list_drafts(string $import_id, string $destination, ?string $state, int $offset, int $limit, string $query='', ?string $category_id=null, ?bool $available=null): array
    {
        [$clause,$params]=self::draftFilter($import_id,$destination,$state,$query,$category_id,$available);
        $counts=['total'=>(int)$this->run('SELECT COUNT(*) FROM drafts WHERE import_id=? AND destination=?',[$import_id,$destination])->fetchColumn()];
        foreach (['ready','errors','warnings','exported','stale'] as $name) $counts[$name]=(int)$this->run('SELECT COUNT(*) FROM drafts WHERE import_id=? AND destination=? AND '.self::stateClause($name),[$import_id,$destination])->fetchColumn();
        return ['items'=>$this->arrays('SELECT payload FROM drafts'.$clause.' ORDER BY rowid LIMIT ? OFFSET ?',array_merge($params,[max(0,$limit),max(0,$offset)])),
            'total'=>(int)$this->run('SELECT COUNT(*) FROM drafts'.$clause,$params)->fetchColumn(),'counts'=>$counts];
    }
    private static function draftFilter(string $importId,string $destination,?string $state,string $query,?string $categoryId,?bool $available): array
    {
        $where=['import_id=?','destination=?']; $params=[$importId,$destination];
        if ($state) $where[]=self::stateClause($state);
        if ($query !== '') { $where[]='(title LIKE ? OR product_id IN (SELECT id FROM products WHERE code LIKE ?))'; array_push($params,'%'.$query.'%','%'.$query.'%'); }
        if ($categoryId) { $where[]="EXISTS(SELECT 1 FROM json_each(payload,'$.category_ids') c WHERE c.value=?)"; $params[]=$categoryId; }
        if ($available !== null) { $where[]='product_id IN (SELECT id FROM products WHERE available=?)'; $params[]=(int)$available; }
        return [' WHERE '.implode(' AND ',$where),$params];
    }
    public function count_filtered_drafts(string $importId,string $destination,?string $state,string $query='',?string $categoryId=null,?bool $available=null): int
    {
        [$clause,$params]=self::draftFilter($importId,$destination,$state,$query,$categoryId,$available);
        return (int)$this->run('SELECT COUNT(*) FROM drafts'.$clause,$params)->fetchColumn();
    }
    public function iter_filtered_drafts(string $importId,string $destination,?string $state,int $offset,int $limit,string $query='',?string $categoryId=null,?bool $available=null): \Generator
    {
        [$clause,$params]=self::draftFilter($importId,$destination,$state,$query,$categoryId,$available);
        $statement=$this->run('SELECT payload FROM drafts'.$clause.' ORDER BY rowid LIMIT ? OFFSET ?',array_merge($params,[max(0,$limit),max(0,$offset)]));
        while (($row=$statement->fetch()) !== false) yield self::decode($row['payload']);
    }
    public function iter_drafts(string $import_id, string $destination): \Generator
    {
        $statement=$this->run('SELECT payload FROM drafts WHERE import_id=? AND destination=? ORDER BY rowid',[$import_id,$destination]);
        while (($row=$statement->fetch()) !== false) yield self::decode($row['payload']);
    }
    public function mark_stale(?string $product_id=null, ?string $destination=null, ?string $import_id=null): void
    {
        $where=[]; $params=[];
        foreach (['product_id'=>$product_id,'destination'=>$destination,'import_id'=>$import_id] as $field=>$value) if ($value !== null) { $where[]=$field.'=?'; $params[]=$value; }
        $this->run("UPDATE drafts SET stale=1,payload=json_set(payload,'$.stale',json('true'))".($where ? ' WHERE '.implode(' AND ',$where) : ''),$params);
    }
    public function record_export(array $export): void
    {
        $this->transaction(function() use($export): void {
            $this->run('INSERT OR IGNORE INTO records VALUES(?,?,?)',['export',$export['id'],self::encode($export)]);
            $this->run("UPDATE drafts SET exported=1,payload=json_set(payload,'$.exported',json('true')) WHERE id IN (SELECT j.value FROM records r,json_each(r.payload,'$.included_ids') j WHERE r.kind='export' AND r.id=?)",[$export['id']]);
        });
    }
    public function get_export(string $id): array
    {
        $export=$this->get_record('export',$id);
        // Persisted paths may come from an archive moved from another machine.
        $export['directory']=$this->directory.'/exports/'.basename($id);
        return $export;
    }
    public function list_exports(?string $import_id=null): array
    {
        $rows=$this->arrays("SELECT payload FROM records WHERE kind='export'".($import_id === null ? '' : " AND json_extract(payload,'$.import_id')=?").' ORDER BY rowid DESC',$import_id === null ? [] : [$import_id]);
        foreach ($rows as &$row) $row['directory']=$this->directory.'/exports/'.basename($row['id']);
        return $rows;
    }

    public function stage_product(string $jobId, array $product, int $position, ?array $sourceProduct=null): void
    {
        $this->run('INSERT INTO staging_products VALUES(?,?,?,?,?) ON CONFLICT(job_id,id) DO UPDATE SET position=excluded.position,payload=excluded.payload,source_payload=excluded.source_payload',[$jobId,$product['id'],$position,self::encode($product),$sourceProduct === null ? null : self::encode($sourceProduct)]);
        if ($sourceProduct !== null) {
            $revision=$this->run("SELECT payload FROM records WHERE kind='product_edits' AND id=?",[$product['id']])->fetchColumn();
            $this->stage_record($jobId,'import_preparation',$product['id'],['revision'=>$revision === false ? null : $revision],$position);
        } else $this->run("DELETE FROM staging_records WHERE job_id=? AND kind='import_preparation' AND id=?",[$jobId,$product['id']]);
    }
    public function stage_draft(string $jobId, array $draft, int $position): void
    {
        $existing=$this->run('SELECT id FROM staging_drafts WHERE job_id=? AND id=? AND position<>?',[$jobId,$draft['id'],$position])->fetchColumn();
        if ($existing !== false) throw new AppError('listing_identity_collision','Riferimenti interni duplicati: correggi le compatibilità.');
        $this->run('INSERT INTO staging_drafts VALUES(?,?,?,?) ON CONFLICT(job_id,id) DO UPDATE SET position=excluded.position,payload=excluded.payload',[$jobId,$draft['id'],$position,self::encode($draft)]);
    }
    public function stage_record(string $jobId, string $kind, string $id, array $payload, int $position): void
    {
        $this->run('INSERT INTO staging_records VALUES(?,?,?,?,?) ON CONFLICT(job_id,kind,id) DO UPDATE SET position=excluded.position,payload=excluded.payload',[$jobId,$kind,$id,$position,self::encode($payload)]);
    }
    public function staged_count(string $jobId, string $kind='products'): int
    {
        if (in_array($kind,['products','drafts'],true)) return (int)$this->run('SELECT COUNT(*) FROM staging_'.$kind.' WHERE job_id=?',[$jobId])->fetchColumn();
        return (int)$this->run('SELECT COUNT(*) FROM staging_records WHERE job_id=? AND kind=?',[$jobId,$kind])->fetchColumn();
    }
    public function staged_products(string $jobId, int $offset, int $limit): array { return $this->arrays('SELECT payload FROM staging_products WHERE job_id=? ORDER BY position,id LIMIT ? OFFSET ?',[$jobId,$limit,$offset]); }
    public function staged_drafts(string $jobId, int $offset, int $limit): array { return $this->arrays('SELECT payload FROM staging_drafts WHERE job_id=? ORDER BY position,id LIMIT ? OFFSET ?',[$jobId,$limit,$offset]); }
    public function clear_staging(string $jobId): void
    {
        foreach (['staging_products','staging_drafts','staging_records'] as $table) $this->run('DELETE FROM '.$table.' WHERE job_id=?',[$jobId]);
    }
    public function import_preparation_is_current(string $jobId): bool
    {
        return !(bool)$this->run("SELECT EXISTS(SELECT 1 FROM staging_products s LEFT JOIN staging_records r ON r.job_id=s.job_id AND r.kind='import_preparation' AND r.id=s.id LEFT JOIN records e ON e.kind='product_edits' AND e.id=s.id WHERE s.job_id=? AND json_extract(r.payload,'$.revision') IS NOT e.payload)",[$jobId])->fetchColumn();
    }

    public function publish_import(string $jobId, string $importId, array $metadata): void
    {
        $this->transaction(function() use($jobId,$importId,$metadata): void {
            if (!$this->import_preparation_is_current($jobId)) throw new AppError('import_corrections_changed','Le correzioni sono cambiate. Riprendi la lavorazione per ricalcolarle.');
            $this->run("INSERT INTO records SELECT 'product_source',id,COALESCE(source_payload,payload) FROM staging_products WHERE job_id=? ON CONFLICT(kind,id) DO UPDATE SET payload=excluded.payload",[$jobId]);
            $this->run("INSERT INTO products SELECT id,json_extract(payload,'$.supplier_id'),json_extract(payload,'$.code'),COALESCE(json_extract(payload,'$.name'),''),json_extract(payload,'$.available'),payload FROM staging_products WHERE job_id=? ON CONFLICT(id) DO UPDATE SET name=excluded.name,available=excluded.available,payload=excluded.payload",[$jobId]);
            $this->run("UPDATE drafts SET stale=1,payload=json_set(payload,'$.stale',json('true')) WHERE product_id IN (SELECT id FROM staging_products WHERE job_id=?)",[$jobId]);
            $this->run('INSERT OR IGNORE INTO import_products SELECT ?,id FROM staging_products WHERE job_id=?',[$importId,$jobId]);
            $summary=['id'=>$importId,'created_at'=>$metadata['created_at'] ?? Support::now(),'diagnostics'=>$metadata['diagnostics'] ?? [],'source_counts'=>$metadata['source_counts'] ?? [],'product_count'=>$this->staged_count($jobId)];
            if (($metadata['diagnostics'] ?? null) === null) {
                $this->run("INSERT OR REPLACE INTO records SELECT 'import_summary',?,json_set(?, '$.diagnostics', json((SELECT json_group_array(json(payload)) FROM (SELECT payload FROM staging_records WHERE job_id=? AND kind='import_diagnostic' ORDER BY position,id))))",[$importId,self::encode($summary),$jobId]);
            } else $this->put_record('import_summary',$importId,$summary);
            $this->run("INSERT OR REPLACE INTO records SELECT 'import',?,json_set(payload, '$.original_products',json((SELECT json_group_array(json(original)) FROM (SELECT COALESCE(source_payload,payload) original FROM staging_products WHERE job_id=? ORDER BY position,id)))) FROM records WHERE kind='import_summary' AND id=?",[$importId,$jobId,$importId]);
        });
    }

    public function publish_drafts(string $jobId, string $importId, ?array $destinations=null): void
    {
        $this->transaction(function() use($jobId,$importId,$destinations): void {
            $destinations ??= $this->run("SELECT DISTINCT json_extract(payload,'$.destination') FROM staging_drafts WHERE job_id=?",[$jobId])->fetchAll(PDO::FETCH_COLUMN);
            // Expand immutable export memberships once, rather than parsing each
            // historic snapshot again for every draft in the new validation.
            $this->run("INSERT OR IGNORE INTO staging_records SELECT ?, 'preserve_exported',id,0,json_object('id',id) FROM drafts WHERE exported=1",[$jobId]);
            $this->run("INSERT OR IGNORE INTO staging_records SELECT ?, 'preserve_exported',j.value,0,json_object('id',j.value) FROM records e,json_each(e.payload,'$.included_ids') j WHERE e.kind='export'",[$jobId]);
            $this->run("DELETE FROM staging_records WHERE job_id=? AND kind='duplicate_reference'",[$jobId]);
            $this->run("INSERT INTO staging_records SELECT ?, 'duplicate_reference',json_extract(payload,'$.destination')||char(0)||json_extract(payload,'$.reference'),0,'{}' FROM staging_drafts WHERE job_id=? GROUP BY json_extract(payload,'$.destination'),json_extract(payload,'$.reference') HAVING COUNT(*)>1",[$jobId,$jobId]);
            foreach ($destinations as $destination) $this->run('DELETE FROM drafts WHERE import_id=? AND destination=?',[$importId,$destination]);
            if (!$destinations) return;
            $diagnostic=Support::diagnostic('error','duplicate_reference','Riferimento già utilizzato per un altro prodotto/inserzione.',[
                'hint'=>'Imposta un prefisso o un riferimento univoco.','field'=>'reference']);
            $sql=<<<'SQL'
WITH candidates AS (
 SELECT id,position,payload,json_extract(payload,'$.product_id') product_id,
 json_extract(payload,'$.destination') destination,json_extract(payload,'$.reference') reference,
 json_extract(payload,'$.title') title,COALESCE(json_extract(payload,'$.valid'),0) valid,
 COALESCE(json_extract(payload,'$.stale'),0) stale
 FROM staging_drafts WHERE job_id=?
), flags AS (
 SELECT c.*,
 EXISTS(SELECT 1 FROM staging_records r WHERE r.job_id=? AND r.kind='preserve_exported' AND r.id=c.id) exported,
 (EXISTS(SELECT 1 FROM staging_records r WHERE r.job_id=? AND r.kind='duplicate_reference' AND r.id=c.destination||char(0)||c.reference)
 OR EXISTS(SELECT 1 FROM drafts d WHERE d.destination=c.destination AND d.reference=c.reference AND d.product_id<>c.product_id)) collision
 FROM candidates c
)
INSERT INTO drafts
SELECT ?,id,product_id,destination,reference,title,CASE WHEN collision THEN 0 ELSE valid END,exported,stale,
CASE WHEN collision THEN
 json_insert(json_set(payload,'$.valid',json('false'),'$.exported',json(CASE WHEN exported THEN 'true' ELSE 'false' END)),
 '$.diagnostics[#]',json_set(json(?),'$.product_id',product_id,'$.listing_id',id,'$.value',reference))
 ELSE json_set(payload,'$.exported',json(CASE WHEN exported THEN 'true' ELSE 'false' END)) END
FROM flags
SQL;
            $sql.=' WHERE destination IN ('.implode(',',array_fill(0,count($destinations),'?')).') ORDER BY position,id';
            $this->run($sql,array_merge([$jobId,$jobId,$jobId,$importId,self::encode($diagnostic)],$destinations));
        });
    }

    public function publish_export(string $jobId, string $exportId, array $metadata): void
    {
        $this->transaction(function() use($jobId,$exportId,$metadata): void {
            $metadata['id']=$exportId; $metadata['created_at'] ??= Support::now();
            $metadata['directory']=$this->directory.'/exports/'.basename($exportId);
            $metadata['snapshot'] ??= [];
            $metadata['snapshot']['rows']=[];
            $sql=<<<'SQL'
INSERT OR IGNORE INTO records
SELECT 'export',?,json_set(?,
'$.included_ids',json((SELECT json_group_array(json_extract(payload,'$.id')) FROM (SELECT payload FROM staging_records WHERE job_id=? AND kind='export_included' ORDER BY position,id))),
'$.excluded_ids',json((SELECT json_group_array(json_extract(payload,'$.id')) FROM (SELECT payload FROM staging_records WHERE job_id=? AND kind='export_excluded' ORDER BY position,id))),
'$.snapshot.rows',json((SELECT json_group_array(json(payload)) FROM (SELECT payload FROM staging_records WHERE job_id=? AND kind='export_summary' ORDER BY position,id))))
SQL;
            $this->run($sql,[$exportId,self::encode($metadata),$jobId,$jobId,$jobId]);
            $this->run("UPDATE drafts SET exported=1,payload=json_set(payload,'$.exported',json('true')) WHERE id IN (SELECT j.value FROM records r,json_each(r.payload,'$.included_ids') j WHERE r.kind='export' AND r.id=?)",[$exportId]);
        });
    }
}
