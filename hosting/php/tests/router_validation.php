<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
use MotorWorld\Hosting\{AppError, Application, CategoryImporter, Defaults, Download, Router, Support};

if (!class_exists(Router::class)) { fwrite(STDERR, "FAIL: validated router implementation is missing.\n"); exit(1); }
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function rejects(callable $fn, string $code='invalid_input'): void {
    try { $fn(); } catch (AppError $error) { check($error->errorCode===$code, 'Expected '.$code.', actual '.$error->errorCode); return; }
    throw new RuntimeException('Expected rejection: '.$code);
}
$data=sys_get_temp_dir().'/mw-router-'.bin2hex(random_bytes(5));mkdir($data,0700);
$app=new Application($data,Support::resources());$api=new Router($app);
$p=Defaults::product(['id'=>'product-one','supplier_id'=>'supplier','code'=>'0001','name'=>'Filtro','cost'=>'10']);
$other=Defaults::product(['id'=>'product-two','supplier_id'=>'supplier','code'=>'0002','name'=>'Altro','cost'=>'20']);
$app->store->save_import('import-one',['products'=>[$p,$other],'source_counts'=>[],'diagnostics'=>[]]);
foreach([
    ['GET','/products',['limit'=>'201'],[]],['GET','/products',['offset'=>'-1'],[]],
    ['GET','/products',['available'=>'maybe'],[]],['GET','/products',['injected'=>'x'],[]],
    ['POST','/pricing/preview',[],['cost'=>'NaN','price_settings'=>[]]],
    ['PATCH','/products/product-one',[],['updates'=>['supplier_id'=>'new']]],
    ['PATCH','/products/product-one',[],['updates'=>['id'=>'new']]],
    ['PATCH','/products/product-one',[],['updates'=>['cost'=>'-1']]],
    ['PATCH','/products/product-one',[],['updates'=>['extra'=>['a'=>[]]]]],
    ['PATCH','/products/product-one',[],['updates'=>['image_urls'=>'text']]],
    ['PATCH','/products/product-one',[],['updates'=>['available'=>'yes']]],
    ['PUT','/products/product-one/compatibilities',[],['rows'=>[['model'=>'a','year_from'=>-1]]]],
    ['PUT','/products/product-one/compatibilities',[],['rows'=>[['id'=>'../../x','model'=>'a']]]],
    ['POST','/exports',[],['import_id'=>'import-one','destination'=>'bad','request_key'=>'key']],
    ['POST','/exports',[],['import_id'=>'import-one','destination'=>'ebay','request_key'=>'']],
]as[$method,$path,$query,$body])rejects(fn()=>$api->dispatch($method,$path,$query,$body));
check($api->dispatch('GET','/products?import_id=import-one&limit=1')['total']===2,'Embedded query accepted.');
$fits=$api->dispatch('PUT','/products/product-one/compatibilities',[],['rows'=>[['id'=>'fit-one','model'=>'CBR','year_from'=>2000,'year_to'=>2001,'vehicle_type'=>'moto']]]);
check($fits[0]['product_id']==='product-one','Compatibility is bound to route product.');
rejects(fn()=>$api->dispatch('PUT','/products/product-two/compatibilities',[],['rows'=>[['id'=>'fit-one','model'=>'CBR']]]));
rejects(fn()=>$api->dispatch('PUT','/products/product-one/compatibilities',[],['rows'=>[['model'=>'CBR'],['model'=>' CBR ']]]),'duplicate_compatibility');
rejects(fn()=>$api->dispatch('PUT','/products/product-one/compatibilities',[],['rows'=>[['model'=>'CBR','year_from'=>2020,'year_to'=>2000]]]),'invalid_years');
$fixed=$api->dispatch('PATCH','/products/product-one',[],['updates'=>['cost'=>'12','extra'=>['mpn'=>'001']],'resolve_fields'=>['cost']]);
check($fixed['cost']==='12'&&$fixed['extra']['mpn']==='001','Allowed manual corrections persist.');
$file=tempnam(sys_get_temp_dir(),'mw-upload-');file_put_contents($file,"Codice;Nome;Costo\r\n0001;Filtro;10\r\n");
$upload=['files'=>['name'=>['../fornitore.CSV'],'tmp_name'=>[$file],'error'=>[UPLOAD_ERR_OK],'size'=>[filesize($file)],'type'=>['text/plain']]];
// A local path is never promoted to a genuine HTTP upload by the production API.
rejects(fn()=>$api->dispatch('POST','/uploads',[],[],$upload),'invalid_upload');
$id=Support::uuid();copy($file,$data.'/uploads/'.$id.'.csv');
$app->store->put_record('upload',$id,['id'=>$id,'name'=>'fornitore.CSV','size'=>filesize($file)]);
check($api->dispatch('GET','/uploads/'.$id.'/inspect')['record_count']===1,'Stored upload inspected.');
$original=$api->dispatch('GET','/uploads/'.$id.'/original');check($original instanceof Download&&file_get_contents($original->path)==="Codice;Nome;Costo\r\n0001;Filtro;10\r\n",'Authenticated original resolves privately.');
$bad=$upload;$bad['files']['name']=['shell.php'];rejects(fn()=>$api->dispatch('POST','/uploads',[],[],$bad),'invalid_upload');
$bad=$upload;$bad['files']['error']=[UPLOAD_ERR_INI_SIZE];rejects(fn()=>$api->dispatch('POST','/uploads',[],[],$bad),'upload_too_large');
rejects(fn()=>$api->dispatch('GET','/uploads/../../x/original'),'not_found');
$diagnostics=[];for($i=0;$i<1200;$i++)$diagnostics[]=Support::diagnostic($i%2?'warning':'error','test','Riga '.$i,['source'=>['file'=>'fornitore.csv','row'=>$i+2,'columns'=>[]],'field'=>'code','value'=>(string)$i]);
$app->store->put_record('import_summary','large',['id'=>'large','created_at'=>Support::now(),'product_count'=>0,'source_counts'=>[],'diagnostics'=>$diagnostics]);unset($diagnostics);
$summary=$api->dispatch('GET','/imports/large');check(count($summary['diagnostics'])===200&&$summary['diagnostic_totals']===['error'=>600,'warning'=>600,'info'=>0],'Summary is bounded with accurate totals.');
$report=$api->dispatch('GET','/imports/large/report');check($report instanceof Download&&substr(file_get_contents($report->path),0,3)==="\xef\xbb\xbf",'Streamed report has BOM.');
$handle=fopen($report->path,'rb');$rows=0;while(fgets($handle)!==false)$rows++;fclose($handle);check($rows===1201,'Full diagnostics remain in report.');
$category=tempnam(sys_get_temp_dir(),'mw-category-');
file_put_contents($category,'[{"id":1,"path":"Moto: ricambi > Ricambio"},{"id":"2","path":"Scooter: accessori"}]');
$catalog=CategoryImporter::ebay($category);check($catalog[0]['id']==='1'&&$catalog[0]['context']==='moto'&&$catalog[1]['context']==='scooter','eBay categories normalize and infer context.');
file_put_contents($category,'[{"id":"1","path":"Moto","extra":"x"}]');rejects(fn()=>CategoryImporter::ebay($category),'invalid_category_file');
file_put_contents($category,"Id;Nome;Mostrata;Posizione\r\n1;Visibile;1;-2\r\n2;Nascosta;0;\r\n");
$catalog=CategoryImporter::prestashop($category);check($catalog[0]['position']===-2&&$catalog[1]['shown']===false,'PrestaShop categories retain visibility and positions.');
file_put_contents($category,"Id;Nome;Mostrata;Descrizione;Posizione\r\n١;Categoria;1\r\n");
$catalog=CategoryImporter::prestashop($category);check($catalog[0]['id']==='١'&&$catalog[0]['description']===''&&$catalog[0]['position']===0,'Unicode decimal IDs and missing optional cells match Python.');
file_put_contents($category,"Id;Nome;Mostrata\r\n1;Categoria;1;unexpected\r\n");rejects(fn()=>CategoryImporter::prestashop($category),'invalid_category_file');
file_put_contents($category,"Id;Nome\r\n1;Categoria\r\n");rejects(fn()=>CategoryImporter::prestashop($category),'invalid_category_file');
$handle=fopen($category,'wb');ftruncate($handle,10*1024*1024+1);fclose($handle);rejects(fn()=>CategoryImporter::ebay($category),'invalid_category_file');unlink($category);
$app->store->record_export(['id'=>'export-one','import_id'=>'import-one','destination'=>'ebay','included_ids'=>[],'excluded_ids'=>[],'snapshot'=>[],'artifacts'=>[['name'=>'unsafe.csv','size'=>1,'rows'=>1,'kind'=>'csv']],'diagnostics'=>[],'directory'=>'/outside','created_at'=>Support::now()]);
mkdir($data.'/exports',0700);mkdir($data.'/exports/export-one',0700);symlink('/etc/passwd',$data.'/exports/export-one/unsafe.csv');
rejects(fn()=>$api->dispatch('GET','/exports/export-one/files/unsafe.csv'),'not_found');
$price=['shipping_cost'=>'5','product_markup'=>'.2','shipping_markup'=>'0','commission_rate'=>'.1','quantity'=>1];
$platform=Defaults::profile('ebay');$platform['free_shipping_confirmed']=true;
$api->dispatch('PUT','/settings/ebay',[],['price_settings'=>$price,'platform_profile'=>$platform]);
// A second real SQLite writer tries to change settings while the API reads its
// snapshot. A temporary view observes that read without production test hooks.
$db=$app->store->pdo();$db->exec('ALTER TABLE records RENAME TO records_actual');
$contender=new PDO('sqlite:'.$data.'/archive.sqlite');$contender->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$contender->exec('PRAGMA busy_timeout=0');
$attempted=false;
$db->sqliteCreateFunction('snapshot_guard',function(string $json)use($contender,&$attempted):string{
    if(!$attempted){$attempted=true;try{$contender->exec("UPDATE records_actual SET payload=json_set(payload,'$.shipping_cost','99') WHERE kind='price_settings' AND id='ebay'");}
        catch(PDOException $error){check(str_contains($error->getMessage(),'locked'),'Concurrent writer fails only because snapshot transaction reserves the writer.');}}
    return $json;
},1);
$db->exec("CREATE VIEW records AS SELECT kind,id,CASE WHEN kind='price_settings' THEN snapshot_guard(payload) ELSE payload END payload FROM records_actual");
$snapshot=$api->dispatch('POST','/imports/import-one/validate',[],['destination'=>'ebay','request_key'=>'snapshot-race']);
$current=$app->store->get_record('price_settings','ebay');
$db->exec('DROP VIEW records');$db->exec('ALTER TABLE records_actual RENAME TO records');
check($attempted&&$snapshot['payload']['price_settings']['shipping_cost']===$current['shipping_cost'],'Snapshot and queued job reservation prevent concurrent settings drift.');
$snapshot['state']='completed';$app->store->save_job($snapshot);
$job=$app->jobs->submit('validate',['import_id'=>'import-one','destination'=>'ebay'],'queued-lock');
rejects(fn()=>$api->dispatch('PATCH','/products/product-one',[],['updates'=>['cost'=>'30']]),'job_busy');
rejects(fn()=>$api->dispatch('PUT','/supplier-profiles/new',[],['id'=>'new','supplier_id'=>'x','name'=>'x','bindings'=>[['role'=>'catalog','column_map'=>['code'=>'Codice','cost'=>'Costo']]]]),'job_busy');
check($api->dispatch('GET','/jobs/'.$job['id'])['state']==='queued','GET does not advance jobs.');
$job['state']='running';$app->store->save_job($job);
for($i=0;$i<21;$i++)$app->store->create_job(array_replace($job,['id'=>Support::uuid(),'state'=>'queued','request_key'=>'later-'.$i]));
check(in_array($job['id'],array_column($api->dispatch('GET','/jobs'),'id'),true),'Older active jobs remain discoverable from another device.');
$payload=['import_id'=>'import-one','destination'=>'ebay','catalog'=>$app->catalog(),'profile'=>Defaults::profile('ebay'),'price_settings'=>['shipping_cost'=>'5','product_markup'=>'.2','shipping_markup'=>'0','commission_rate'=>'.1','quantity'=>1]];
for($i=0;$i<350;$i++)$app->store->create_job(array_replace($job,['id'=>Support::uuid(),'state'=>$i<300?'completed':'queued','payload'=>$payload,'request_key'=>'large-history-'.$i]));
$listed=$api->dispatch('GET','/jobs');check(count($listed)===92,'All active jobs and only twenty historical jobs are returned.');
check(!isset($listed[0]['payload']['catalog'])&&$listed[0]['payload']['import_id']==='import-one','Job list payloads contain compact intent metadata.');
echo "PASS: router input ranges, immutable identities, product-bound fits, upload safety, bounded summaries/jobs, streamed reports, safe downloads, mutation locks and atomic snapshots.\n";
