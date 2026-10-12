<?php
declare(strict_types=1);
namespace MotorWorld\Hosting;

/** Auth and CSRF are checked by the public entry point before dispatch. */
final class Router
{
    private const PREVIEW_LIMIT = 200;
    private const PRODUCT_FIELDS = ['name','brand','family','department','cost','ean','image_urls','available','extra'];
    private const REPORT_COLUMNS = ['Riferimento','Codice prodotto','Livello','Errore','Messaggio','File','Riga','Campo','Valore','Correzione'];

    public function __construct(private readonly Application $app) {}

    public function dispatch(string $method, string $path, array $query=[], array $body=[], array $files=[]): array|Download
    {
        $parts = parse_url($path);
        if ($parts === false || isset($parts['scheme']) || isset($parts['host']) || isset($parts['fragment'])) throw new NotFound();
        $embedded=[]; parse_str($parts['query'] ?? '', $embedded);
        $query=array_replace($embedded,$query); $path=$parts['path'] ?? '';
        $segments=explode('/',trim($path,'/'));
        foreach($segments as &$segment) {
            $segment=rawurldecode($segment);
            if($segment==='.'||$segment==='..'||str_contains($segment,'/')||str_contains($segment,'\\')||preg_match('/[\x00-\x1f\x7f]/',$segment))throw new NotFound();
        }
        unset($segment); $path='/'.implode('/',$segments); $method=strtoupper($method);
        if ($method==='GET' && $path==='/health') { Input::fields($query,[]); return ['status'=>'ok','schema_version'=>2,'version'=>'0.1.0']; }
        if ($method==='GET' && $path==='/brand/logo') {
            Input::fields($query,[]);$logo=realpath($this->app->resources.'/motorworld-logo.png');
            if($logo===false||!is_file($logo))throw new NotFound();return new Download($logo,'motorworld-logo.png','image/png');
        }
        if ($path==='/supplier-profiles') {
            Input::fields($query,[]);if($method==='GET')return $this->app->store->list_records('supplier_profile');
        }
        if (preg_match('~^/supplier-profiles/([^/]+)$~D',$path,$m) && $method==='PUT') {
            Input::fields($query,[]);$id=Input::identifier($m[1],'id');$profile=Input::supplierProfile($body);
            if($id!==$profile['id'])throw new AppError('profile_id_mismatch','L’ID del profilo non corrisponde.');
            return $this->app->mutate(function()use($profile){$this->app->store->save_profile($profile);return $profile;});
        }
        if ($path==='/uploads') {
            Input::fields($query,[]);
            if($method==='GET')return array_slice($this->app->store->list_records('upload'),0,30);
            if($method==='POST'){Input::fields($body,[]);return $this->upload($files);}
        }
        if(preg_match('~^/uploads/([^/]+)/(inspect|original)$~D',$path,$m)&&$method==='GET') {
            $id=Input::identifier($m[1],'id');$meta=$this->app->store->get_record('upload',$id);
            $original=$this->privateFile($this->app->store->dataDir().'/uploads/'.$id.'.csv');
            if($m[2]==='original'){Input::fields($query,[]);return new Download($original,$this->downloadName($meta['name']),'text/csv; charset=utf-8');}
            Input::fields($query,['encoding','delimiter']);
            $encoding=isset($query['encoding'])?Input::choice($query['encoding'],'encoding',['utf-8','utf-8-sig','cp1252','iso-8859-1']):null;
            $delimiter=isset($query['delimiter'])?$this->delimiter($query['delimiter']):null;
            return $this->app->importer->inspect($original,$encoding,$delimiter);
        }
        if($path==='/imports') {
            Input::fields($query,[]);
            if($method==='GET')return $this->importSummaries();
            if($method==='POST')return $this->app->store->transaction(fn():array=>$this->startImport($body));
        }
        if(preg_match('~^/imports/([^/]+)(?:/(report|validate|drafts))?$~D',$path,$m)) {
            $id=Input::identifier($m[1],'import_id');$action=$m[2]??'';
            if($method==='GET'&&$action===''){Input::fields($query,[]);return $this->importSummary($id);}
            if($method==='GET'&&$action==='report'){Input::fields($query,[]);return $this->importReport($id);}
            if($method==='POST'&&$action==='validate') {
                Input::fields($query,[]);Input::fields($body,['destination','request_key'],['destination','request_key']);
                $destination=Input::destination($body['destination']);$request=$this->requestKey($body['request_key']);
                return $this->app->store->transaction(function()use($id,$destination,$request):array{
                    $this->importExists($id);
                    try{$price=$this->app->store->get_record('price_settings',$destination);}catch(NotFound){throw new AppError('price_settings_required','Imposta commissioni, ricarichi, spedizione e quantità nella schermata Prezzi.');}
                    return $this->app->jobs->submit('validate',['import_id'=>$id,'destination'=>$destination,'price_settings'=>$price,
                        'profile'=>$this->app->store->get_platform_profile($destination),'catalog'=>$this->app->catalog()],$request);
                });
            }
            if($method==='GET'&&$action==='drafts') {
                Input::fields($query,['destination','state','offset','limit','query','category_id','available'],['destination']);$this->importExists($id);
                $filters=$this->filters($query);return $this->app->store->list_drafts($id,Input::destination($query['destination']),$filters['state']??null,
                    Input::integer($query['offset']??0,'offset'),Input::integer($query['limit']??50,'limit',1,200),$filters['query']??'', $filters['category_id']??null,$filters['available']??null);
            }
        }
        if($path==='/jobs'&&$method==='GET') {
            Input::fields($query,[]);
            // Listing returns compact intent metadata. The stored snapshot and
            // individual GET/step responses retain the complete immutable payload.
            $query=$this->app->store->pdo()->query("SELECT json_set(payload,'$.payload',json_patch('{}',json_object('import_id',json_extract(payload,'$.payload.import_id'),'destination',json_extract(payload,'$.payload.destination'),'profile_id',COALESCE(json_extract(payload,'$.payload.profile_id'),json_extract(payload,'$.payload.profile.id'))))) FROM jobs WHERE state IN ('queued','running') OR rowid IN (SELECT rowid FROM jobs WHERE state NOT IN ('queued','running') ORDER BY rowid DESC LIMIT 20) ORDER BY rowid DESC");
            $result=[];while(($json=$query->fetchColumn())!==false){$job=json_decode($json,true,512,JSON_THROW_ON_ERROR);if($job['payload']===[])$job['payload']=new \stdClass();$result[]=$job;}return $result;
        }
        if(preg_match('~^/jobs/([^/]+)(?:/(step|resume))?$~D',$path,$m)) {
            Input::fields($query,[]);$id=Input::identifier($m[1],'id');$action=$m[2]??'';
            if($method==='GET'&&$action==='')return $this->app->jobs->get($id);
            if($method==='POST'&&in_array($action,['step','resume'],true)){Input::fields($body,[]);return $action==='step'?$this->app->jobs->step($id):$this->app->jobs->resume($id);}
        }
        if($path==='/products'&&$method==='GET') {
            Input::fields($query,['import_id','query','offset','limit','available','category_id','state','destination']);
            $import=isset($query['import_id'])?Input::identifier($query['import_id'],'import_id'):null;
            return $this->app->store->list_products($import,$this->filters($query),Input::integer($query['offset']??0,'offset'),Input::integer($query['limit']??50,'limit',1,200));
        }
        if(preg_match('~^/products/([^/]+)(?:/(compatibilities|reset-corrections))?$~D',$path,$m)) {
            Input::fields($query,[]);$id=Input::identifier($m[1],'product_id');$action=$m[2]??'';
            if($method==='GET'&&$action==='')return $this->app->store->get_product($id);
            if($method==='PATCH'&&$action==='')return $this->editProduct($id,$body);
            if($method==='POST'&&$action==='reset-corrections'){Input::fields($body,[]);return $this->app->mutate(function()use($id){$this->app->store->get_product($id);return $this->app->store->reset_product_corrections($id);});}
            if($method==='GET'&&$action==='compatibilities'){$this->app->store->get_product($id);return $this->app->store->get_compatibilities($id);}
            if($method==='PUT'&&$action==='compatibilities')return $this->saveFits($id,$body);
        }
        if(preg_match('~^/listing-overrides/([^/]+)$~D',$path,$m)) {
            Input::fields($query,[]);$id=Input::identifier($m[1],'listing_id');
            if($method==='GET'){$draft=$this->app->store->get_draft($id);return Defaults::overrides($this->app->store->get_overrides($draft['product_id'])[$id]??['listing_id'=>$id]);}
            if($method==='PUT')return $this->saveOverride($id,$body);
        }
        if(preg_match('~^/settings/([^/]+)$~D',$path,$m)) {
            Input::fields($query,[]);$destination=Input::destination($m[1]);
            if($method==='GET'){try{$price=$this->app->store->get_record('price_settings',$destination);}catch(NotFound){$price=null;}return ['platform_profile'=>$this->app->store->get_platform_profile($destination),'price_settings'=>$price];}
            if($method==='PUT') {
                Input::fields($body,['platform_profile','price_settings'],['platform_profile','price_settings']);$profile=Input::profile($body['platform_profile']);$price=Input::priceSettings($body['price_settings']);
                if($profile['destination']!==$destination)throw new AppError('wrong_destination','Il profilo deve corrispondere alla destinazione.');
                return $this->app->mutate(function()use($profile,$price,$destination){$this->app->store->save_platform_profile($profile);$this->app->store->put_record('price_settings',$destination,$price);$this->app->store->mark_stale(destination:$destination);return ['platform_profile'=>$profile,'price_settings'=>$price];});
            }
        }
        if($path==='/pricing/preview'&&$method==='POST') {
            Input::fields($query,[]);Input::fields($body,['cost','price_settings','override_gross'],['cost','price_settings']);
            $cost=Input::decimal($body['cost'],'cost');$settings=Input::priceSettings($body['price_settings']);$override=isset($body['override_gross'])?Input::decimal($body['override_gross'],'override_gross',positive:true):null;
            return Pricing::calculate($cost,$settings,$override);
        }
        if($path==='/categories'&&$method==='GET'){Input::fields($query,[]);return $this->app->catalog();}
        if($path==='/ebay-store-categories'&&$method==='GET'){Input::fields($query,[]);return json_decode(file_get_contents($this->app->resources.'/ebay_store_categories.json'),true,512,JSON_THROW_ON_ERROR);}
        if($path==='/category-rules') {
            if($method==='GET'){Input::fields($query,['supplier_id'],['supplier_id']);return $this->app->store->list_category_rules(Input::text($query['supplier_id'],'supplier_id',200));}
            if($method==='PUT'){Input::fields($query,[]);return $this->saveRule($body);}
        }
        if(preg_match('~^/categories/([^/]+)/replace$~D',$path,$m)&&$method==='POST') {
            Input::fields($query,[]);Input::fields($body,[]);return $this->replaceCategories(Input::destination($m[1]),$files);
        }
        if($path==='/exports') {
            if($method==='GET'){Input::fields($query,['import_id']);$id=isset($query['import_id'])?Input::identifier($query['import_id'],'import_id'):null;return $this->exportSummaries($id);}
            if($method==='POST'){Input::fields($query,[]);return $this->app->store->transaction(fn():array=>$this->startExport($body));}
        }
        if(preg_match('~^/exports/([^/]+)(?:/files/([^/]+))?$~D',$path,$m)&&$method==='GET') {
            Input::fields($query,[]);$id=Input::identifier($m[1],'export_id');
            if(!isset($m[2]))return $this->exportSummary($id);
            return $this->exportFile($id,$m[2]);
        }
        throw new NotFound();
    }

    private function filters(array $value): array
    {
        $result=[];
        if(isset($value['query']))$result['query']=Input::text($value['query'],'query',1000);
        if(isset($value['category_id']))$result['category_id']=Input::identifier($value['category_id'],'category_id');
        if(isset($value['state']))$result['state']=Input::choice($value['state'],'state',['ready','valid','errors','exported','stale']);
        if(isset($value['destination']))$result['destination']=Input::destination($value['destination']);
        if(isset($value['available'])) {
            $raw=$value['available'];if(is_string($raw)&&in_array(strtolower($raw),['true','false','1','0'],true))$raw=in_array(strtolower($raw),['true','1'],true);
            $result['available']=Input::boolean($raw,'available');
        }
        return $result;
    }

    private function requestKey(mixed $value): string
    {
        $value=Input::text($value,'request_key',200);if($value==='')Input::fail('request_key','Indica una chiave della richiesta.');return $value;
    }

    private function delimiter(mixed $value): string
    {
        $value=Input::text($value,'delimiter',1);if(strlen($value)!==1||in_array($value,["\r","\n",'"'],true))Input::fail('delimiter','Scegli un delimitatore singolo.');return $value;
    }

    private function startImport(array $body): array
    {
        Input::fields($body,['profile_id','uploads','request_key'],['profile_id','uploads','request_key']);$id=Input::identifier($body['profile_id'],'profile_id');$profile=$this->app->store->get_profile($id);
        $uploads=$this->list($body['uploads'],'uploads',20);if(!$uploads)Input::fail('uploads','Carica almeno un file.');$normalized=[];$roles=[];$ids=[];
        foreach($uploads as$raw) {
            $raw=Input::object($raw,'uploads');Input::fields($raw,['upload_id','role'],['upload_id','role']);$upload=Input::identifier($raw['upload_id'],'upload_id');$role=Input::choice($raw['role'],'role',['catalog','prices','packaging']);
            if(isset($roles[$role])||isset($ids[$upload]))Input::fail('uploads','Scegli un solo file per ogni ruolo.');$roles[$role]=true;$ids[$upload]=true;
            $this->app->store->get_record('upload',$upload);$this->privateFile($this->app->store->dataDir().'/uploads/'.$upload.'.csv');$normalized[]=['upload_id'=>$upload,'role'=>$role];
        }
        if(!isset($roles['catalog']))throw new AppError('catalog_required','Carica almeno un file di anagrafica.');
        foreach($normalized as$upload)if(!in_array($upload['role'],array_column($profile['bindings'],'role'),true))throw new AppError('profile_mismatch','Il profilo non definisce tutti i ruoli caricati.');
        return $this->app->jobs->submit('import',['profile'=>$profile,'uploads'=>$normalized],$this->requestKey($body['request_key']));
    }

    private function editProduct(string $id,array $body): array
    {
        Input::fields($body,['updates','resolve_fields'],['updates']);$updates=Input::object($body['updates'],'updates');Input::fields($updates,self::PRODUCT_FIELDS);
        foreach($updates as$field=>$value) {
            if($field==='cost')$updates[$field]=$value===null?null:Input::decimal($value,'updates.cost');
            elseif($field==='available')$updates[$field]=Input::boolean($value,'updates.available',true);
            elseif($field==='image_urls'){$urls=$this->list($value,'updates.image_urls',100);foreach($urls as$i=>$url)$urls[$i]=Input::text($url,'image_urls',10000);$updates[$field]=$urls;}
            elseif($field==='extra'){$map=$value===null?[]:Input::object($value,'updates.extra');if(count($map)>200)Input::fail('extra','Troppi campi aggiuntivi.');foreach($map as$key=>$entry){Input::text($key,'extra',200);Input::text($entry,'extra',100000);}$updates[$field]=$map;}
            else$updates[$field]=Input::text($value,'updates.'.$field,100000,$field==='ean');
        }
        $resolve=$this->list($body['resolve_fields']??[],'resolve_fields',count(self::PRODUCT_FIELDS));foreach($resolve as$field)Input::choice($field,'resolve_fields',self::PRODUCT_FIELDS);
        return $this->app->mutate(function()use($id,$updates,$resolve){$product=$this->app->store->get_product($id);if(isset($updates['extra']))$updates['extra']=array_replace($product['extra'],$updates['extra']);$fixed=array_values(array_intersect($resolve,array_keys($updates)));
            $updates['diagnostics']=array_values(array_filter($product['diagnostics'],static fn(array $diagnostic):bool=>!in_array($diagnostic['field']??null,$fixed,true)));$product=array_replace($product,$updates);
            $this->app->store->save_product($product,$fixed);$this->app->store->mark_stale(product_id:$id);return $product;});
    }

    private function saveFits(string $id,array $body): array
    {
        Input::fields($body,['rows'],['rows']);$rows=$this->list($body['rows'],'rows',1000);$normalized=[];
        foreach($rows as$raw) {
            $raw=Input::object($raw,'rows');Input::fields($raw,['id','product_id','make','model','displacement','year_from','year_to','vehicle_type']);
            if(isset($raw['product_id'])&&$raw['product_id']!==$id)Input::fail('product_id','La compatibilità deve appartenere al prodotto indicato.');
            $row=Defaults::compatibility($raw);$row['product_id']=$id;$row['id']=Input::identifier($row['id'],'id');
            foreach(['make','model','displacement']as$field)$row[$field]=Input::text($row[$field],$field,1000);
            foreach(['year_from','year_to']as$field)if($row[$field]!==null)$row[$field]=Input::integer($row[$field],$field,1,9999);
            $row['vehicle_type']=Input::choice($row['vehicle_type'],'vehicle_type',['moto','scooter','other','unspecified']);$normalized[]=$row;
        }
        $errors=DraftCompiler::validateCompatibilities($normalized);if($errors)throw new AppError($errors[0]['code'],$errors[0]['message'],422,$errors);
        return $this->app->mutate(function()use($id,$normalized){$this->app->store->get_product($id);$query=$this->app->store->pdo()->prepare('SELECT product_id FROM compatibilities WHERE id=?');
            foreach($normalized as$row){$query->execute([$row['id']]);$owner=$query->fetchColumn();if($owner!==false&&$owner!==$id)Input::fail('id','Questa compatibilità appartiene a un altro prodotto.');}
            $this->app->store->save_compatibilities($id,$normalized);$this->app->store->mark_stale(product_id:$id);return $normalized;});
    }

    private function saveOverride(string $id,array $body): array
    {
        Input::fields($body,['listing_id','title','reference','gross_price','category_id'],['listing_id']);$override=Defaults::overrides($body);$override['listing_id']=Input::identifier($override['listing_id'],'listing_id');
        if($override['listing_id']!==$id)throw new AppError('listing_id_mismatch','La bozza non corrisponde.');
        foreach(['title','reference','category_id']as$field)$override[$field]=Input::text($override[$field],$field,10000,true);
        if($override['gross_price']!==null)$override['gross_price']=Input::decimal($override['gross_price'],'gross_price',positive:true);
        return $this->app->mutate(function()use($id,$override){$draft=$this->app->store->get_draft($id);$this->app->store->save_overrides([$override]);$this->app->store->mark_stale(product_id:$draft['product_id'],destination:$draft['destination']);return $override;});
    }

    private function saveRule(array $body): array
    {
        Input::fields($body,['supplier_id','family','context','destination','category_id'],['supplier_id','family','destination','category_id']);
        $rule=['supplier_id'=>Input::text($body['supplier_id'],'supplier_id',200),'family'=>Input::text($body['family'],'family',1000),
            'context'=>Input::choice($body['context']??'unspecified','context',['moto','scooter','other','unspecified','shared','unknown']),
            'destination'=>Input::destination($body['destination']),'category_id'=>Input::identifier($body['category_id'],'category_id')];
        return $this->app->mutate(function()use($rule){if(!in_array($rule['category_id'],array_column($this->app->catalog()[$rule['destination']],'id'),true))throw new AppError('unknown_category','Scegli un ID presente nel repertorio.');
            $this->app->store->save_category_rule($rule);$this->app->store->mark_stale(destination:$rule['destination']);return $rule;});
    }

    private function startExport(array $body): array
    {
        Input::fields($body,['import_id','destination','request_key','selection_mode','draft_ids','query','category_id','available','state'],['import_id','destination','request_key']);
        $payload=['import_id'=>Input::identifier($body['import_id'],'import_id'),'destination'=>Input::destination($body['destination']),
            'selection_mode'=>Input::choice($body['selection_mode']??'new_valid','selection_mode',['new_valid','selected']),'draft_ids'=>[],
            'query'=>Input::text($body['query']??'','query',1000),'category_id'=>isset($body['category_id'])?Input::identifier($body['category_id'],'category_id'):null,
            'available'=>array_key_exists('available',$body)?Input::boolean($body['available'],'available',true):null,
            'state'=>isset($body['state'])?Input::choice($body['state'],'state',['ready','valid','errors','exported','stale']):null];
        $ids=$this->list($body['draft_ids']??[],'draft_ids',10000);foreach($ids as$id)$payload['draft_ids'][]=Input::identifier($id,'draft_ids');$payload['draft_ids']=array_values(array_unique($payload['draft_ids']));
        $this->importExists($payload['import_id']);
        foreach($payload['draft_ids']as$id){$draft=$this->app->store->get_draft($id,$payload['import_id']);if($draft['destination']!==$payload['destination'])Input::fail('draft_ids','Le bozze devono avere la stessa destinazione.');}
        return $this->app->jobs->submit('export',$payload,$this->requestKey($body['request_key']));
    }

    private function list(mixed $value,string $field,int $maximum): array
    {
        if(!is_array($value)||!array_is_list($value)||count($value)>$maximum)Input::fail($field,'Inserisci un elenco nell’intervallo consentito.');return $value;
    }

    private function privateFile(string $path): string
    {
        $root=realpath($this->app->store->dataDir());$actual=realpath($path);
        if($root===false||$actual===false||$actual!==$path||is_link($path)||!str_starts_with($actual,$root.DIRECTORY_SEPARATOR)||!is_file($actual)||!is_readable($actual))throw new NotFound();return $actual;
    }

    private function privateDirectory(string $name): string
    {
        $root=realpath($this->app->store->dataDir());$path=$this->app->store->dataDir().'/'.$name;
        if(!is_dir($path)&&!@mkdir($path,0700,true)&&!is_dir($path))throw new AppError('storage_error','Archivio non disponibile.',500);
        $actual=realpath($path);if($root===false||$actual===false||$actual!==$path||!str_starts_with($actual,$root.DIRECTORY_SEPARATOR))throw new AppError('storage_error','Archivio non disponibile.',500);return $actual;
    }

    private function downloadName(string $name): string
    {
        $name=basename(str_replace('\\','/',$name));$name=preg_replace('/[\x00-\x1f\x7f]/','_',$name);return $name===''?'fornitore.csv':$name;
    }

    private function uploadedFiles(array $files,string $field,bool $multiple): array
    {
        Input::fields($files,[$field]);$raw=$files[$field]??null;if(!is_array($raw))throw new AppError('invalid_upload','Seleziona il file da caricare.');
        if(is_array($raw['name']??null)) {
            if(!$multiple)throw new AppError('invalid_upload','Seleziona un solo file.');$items=[];
            foreach($raw['name']as$key=>$name){$item=['name'=>$name];foreach(['tmp_name','error','size']as$property)$item[$property]=$raw[$property][$key]??null;$items[]=$item;}
        } else $items=[$raw];
        if(!$items||count($items)>20)throw new AppError('invalid_upload','Carica da uno a venti file.');
        foreach($items as$item) {
            $error=$item['error']??UPLOAD_ERR_NO_FILE;
            if(in_array($error,[UPLOAD_ERR_INI_SIZE,UPLOAD_ERR_FORM_SIZE],true))throw new AppError('upload_too_large','Il file supera il limite del server: aumenta upload_max_filesize e post_max_size nelle impostazioni PHP.',413);
            if($error!==UPLOAD_ERR_OK)throw new AppError('invalid_upload','Caricamento incompleto: seleziona nuovamente il file.');
            if(!is_string($item['name']??null)||!mb_check_encoding($item['name'],'UTF-8')||mb_strlen($item['name'],'UTF-8')>255)throw new AppError('invalid_upload','Nome del file non valido.');
        }
        return $items;
    }

    private function upload(array $files): array
    {
        $items=$this->uploadedFiles($files,'files',true);
        foreach($items as$item)if(strtolower(pathinfo($this->downloadName($item['name']),PATHINFO_EXTENSION))!=='csv')throw new AppError('invalid_upload','Carica soltanto file CSV.');
        return $this->app->mutate(function()use($items){$directory=$this->privateDirectory('uploads');$infos=[];$created=[];
            try {
                foreach($items as$item){if(!is_string($item['tmp_name']??null)||!is_uploaded_file($item['tmp_name']))throw new AppError('invalid_upload','File caricato non disponibile.');
                    $id=Support::uuid();$path=$directory.'/'.$id.'.csv';if(!move_uploaded_file($item['tmp_name'],$path))throw new AppError('storage_error','Impossibile salvare il file originale.',500);chmod($path,0600);$created[]=$path;
                    $meta=['id'=>$id,'name'=>$this->downloadName($item['name']),'size'=>filesize($path)];$view=$this->app->importer->inspect($path);$this->app->store->put_record('upload',$id,$meta);$infos[]=$meta+['inspection'=>$view];
                }
                return ['uploads'=>$infos];
            } catch(\Throwable $error){foreach($created as$path)@unlink($path);throw $error;}
        });
    }

    private function replaceCategories(string $destination,array $files): array
    {
        $items=$this->uploadedFiles($files,'file',false);$item=$items[0];
        $extension=$destination==='ebay'?'json':'csv';if(strtolower(pathinfo($item['name'],PATHINFO_EXTENSION))!==$extension)throw new AppError('invalid_category_file','Scegli il file '.$extension.' del repertorio.');
        return $this->app->mutate(function()use($destination,$item,$extension){if(!is_string($item['tmp_name']??null)||!is_uploaded_file($item['tmp_name']))throw new AppError('invalid_upload','File caricato non disponibile.');
            $path=$this->privateDirectory('category-imports').'/'.Support::uuid().'.'.$extension;
            if(!move_uploaded_file($item['tmp_name'],$path))throw new AppError('storage_error','Impossibile salvare il repertorio.',500);chmod($path,0600);
            try{$catalog=$this->app->catalog();$catalog[$destination]=$destination==='ebay'?CategoryImporter::ebay($path):CategoryImporter::prestashop($path);$catalog['version']=hash_file('sha256',$path);
                $diagnostics=CategoryImporter::replacementDiagnostics($catalog,$this->app->store->list_records('category_rule'));$this->app->store->put_record('catalog','active',$catalog);$this->app->store->mark_stale(destination:$destination);
                return ['counts'=>['ebay'=>count($catalog['ebay']),'prestashop'=>count($catalog['prestashop'])],'diagnostics'=>$diagnostics];
            } catch(\Throwable $error){@unlink($path);throw $error;}
        });
    }

    private function importExists(string $id): void
    {
        $query=$this->app->store->pdo()->prepare("SELECT 1 FROM records WHERE kind='import_summary' AND id=?");$query->execute([$id]);if($query->fetchColumn()===false)throw new NotFound($id);
    }

    private function importSummary(string $id): array
    {
        $query=$this->app->store->pdo()->prepare("SELECT json_remove(payload,'$.diagnostics','$.original_products') FROM records WHERE kind='import_summary' AND id=?");$query->execute([$id]);$json=$query->fetchColumn();if($json===false)throw new NotFound($id);
        $summary=json_decode($json,true,512,JSON_THROW_ON_ERROR);$preview=$this->app->store->pdo()->prepare("SELECT j.value FROM records r,json_each(r.payload,'$.diagnostics') j WHERE r.kind='import_summary' AND r.id=? ORDER BY CAST(j.key AS INTEGER) LIMIT ".self::PREVIEW_LIMIT);$preview->execute([$id]);$summary['diagnostics']=[];
        while(($value=$preview->fetchColumn())!==false)$summary['diagnostics'][]=json_decode($value,true,512,JSON_THROW_ON_ERROR);
        $counts=$this->app->store->pdo()->prepare("SELECT COUNT(*) total,SUM(json_extract(j.value,'$.level')='error') errors,SUM(json_extract(j.value,'$.level')='warning') warnings,SUM(json_extract(j.value,'$.level')='info') infos FROM records r,json_each(r.payload,'$.diagnostics') j WHERE r.kind='import_summary' AND r.id=?");$counts->execute([$id]);$row=$counts->fetch(\PDO::FETCH_ASSOC);
        if((int)$row['total']>self::PREVIEW_LIMIT)$summary['diagnostic_totals']=['error'=>(int)$row['errors'],'warning'=>(int)$row['warnings'],'info'=>(int)$row['infos']];
        if(($summary['source_counts']??[])===[])$summary['source_counts']=new \stdClass();return $summary;
    }

    private function importSummaries(): array
    {
        $query=$this->app->store->pdo()->query("SELECT id FROM records WHERE kind='import_summary' ORDER BY rowid DESC");$result=[];while(($id=$query->fetchColumn())!==false)$result[]=$this->importSummary($id);return $result;
    }

    private function importReport(string $id): Download
    {
        $this->importExists($id);$directory=$this->privateDirectory('reports');$path=$directory.'/import-'.hash('sha256',$id).'.csv';
        if(!is_file($path)) {
            $temporary=tempnam($directory,'.report-');$handle=fopen($temporary,'wb');
            try{CsvWriter::writeAll($handle,CsvWriter::header(self::REPORT_COLUMNS));$query=$this->app->store->pdo()->prepare("SELECT j.value FROM records r,json_each(r.payload,'$.diagnostics') j WHERE r.kind='import_summary' AND r.id=? ORDER BY CAST(j.key AS INTEGER)");$query->execute([$id]);
                while(($json=$query->fetchColumn())!==false){$d=json_decode($json,true,512,JSON_THROW_ON_ERROR);CsvWriter::writeAll($handle,CsvWriter::encodeRecord(['',($d['field']??null)==='code'?($d['value']??''):'',$d['level'],$d['code'],$d['message'],$d['source']['file']??'',$d['source']['row']??'',$d['field']??'',$d['value']??'',$d['hint']??'']));}
            } catch(\Throwable $error){fclose($handle);@unlink($temporary);throw $error;}
            fclose($handle);if(!rename($temporary,$path)){@unlink($temporary);throw new AppError('storage_error','Impossibile salvare il rapporto.',500);}chmod($path,0600);
        }
        return new Download($this->privateFile($path),'rapporto-importazione.csv','text/csv; charset=utf-8');
    }

    private function exportQuery(): string
    {
        return "SELECT json_object('id',id,'import_id',json_extract(payload,'$.import_id'),'destination',json_extract(payload,'$.destination'),'included_count',json_array_length(payload,'$.included_ids'),'excluded_count',json_array_length(payload,'$.excluded_ids'),'import_error_count',COALESCE(json_extract(payload,'$.snapshot.import_error_count'),0),'import_warning_count',COALESCE(json_extract(payload,'$.snapshot.import_warning_count'),0),'artifacts',json_extract(payload,'$.artifacts'),'diagnostics',json_extract(payload,'$.diagnostics'),'created_at',json_extract(payload,'$.created_at')) FROM records WHERE kind='export'";
    }

    private function exportSummary(string $id): array
    {
        $query=$this->app->store->pdo()->prepare($this->exportQuery().' AND id=?');$query->execute([$id]);$json=$query->fetchColumn();if($json===false)throw new NotFound($id);return json_decode($json,true,512,JSON_THROW_ON_ERROR);
    }

    private function exportSummaries(?string $id): array
    {
        $query=$this->app->store->pdo()->prepare($this->exportQuery().($id===null?'':" AND json_extract(payload,'$.import_id')=?").' ORDER BY rowid DESC');$query->execute($id===null?[]:[$id]);$result=[];while(($json=$query->fetchColumn())!==false)$result[]=json_decode($json,true,512,JSON_THROW_ON_ERROR);return $result;
    }

    private function exportFile(string $id,string $name): Download
    {
        if($name!==basename($name)||$name===''||preg_match('/[\/\\\\\x00-\x1f\x7f]/',$name))throw new NotFound();$summary=$this->exportSummary($id);$artifact=null;
        foreach($summary['artifacts']as$item)if($item['name']===$name){$artifact=$item;break;}if($artifact===null)throw new NotFound();
        $path=$this->app->store->dataDir().'/exports/'.$id.'/'.$name;
        if(!is_file($path))throw new AppError('missing_export_file','Il file storico non è più presente nella cartella dati.');
        return new Download($this->privateFile($path),$name,$artifact['kind']==='zip'?'application/zip':'text/csv; charset=utf-8');
    }
}
