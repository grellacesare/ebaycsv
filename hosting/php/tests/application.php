<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
use MotorWorld\Hosting\Application;
use MotorWorld\Hosting\Router;
use MotorWorld\Hosting\AppError;
use MotorWorld\Hosting\Support;
use MotorWorld\Hosting\Defaults;

if (!class_exists(Application::class) || !class_exists(Router::class)) {
    fwrite(STDERR, "FAIL: PHP application and validated API routes are missing.\n"); exit(1);
}
function verify(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function invalid(callable $operation, string $code): void {
    try { $operation(); } catch (AppError $e) { verify($e->errorCode === $code, 'Unexpected error: '.$e->errorCode); return; }
    throw new RuntimeException('Expected '.$code);
}
$data = sys_get_temp_dir().'/mw-app-'.bin2hex(random_bytes(5));
mkdir($data, 0700);
$app = new Application($data, Support::resources()); $api = new Router($app);
verify($api->dispatch('GET','/health')['status'] === 'ok', 'Health contract.');
verify($api->dispatch('GET','/settings/ebay')['price_settings'] === null, 'No invented pricing.');
invalid(fn() => $api->dispatch('PUT','/settings/ebay',[],['platform_profile'=>['destination'=>'ebay'],'price_settings'=>[]]), 'invalid_input');
invalid(fn() => $api->dispatch('POST','/pricing/preview',[],['cost'=>'NaN','price_settings'=>[]]), 'invalid_input');
invalid(fn() => $api->dispatch('POST','/pricing/preview',[],['cost'=>'100','price_settings'=>['shipping_cost'=>'5','product_markup'=>'.2','shipping_markup'=>'0','commission_rate'=>'1.1','quantity'=>1]]), 'invalid_input');

$profile=['id'=>'supplier-profile','supplier_id'=>'supplier-a','name'=>'Fornitore A','bindings'=>[
 ['role'=>'catalog','encoding'=>'utf-8','delimiter'=>';','decimal_separator'=>'.','thousands_separator'=>null,
  'column_map'=>['code'=>'Codice','name'=>'Nome','family'=>'Famiglia','cost'=>'Costo']]]];
$api->dispatch('PUT','/supplier-profiles/supplier-profile',[],$profile);
mkdir($data.'/uploads',0700); $upload=Support::uuid();
file_put_contents($data.'/uploads/'.$upload.'.csv',"Codice;Nome;Famiglia;Costo\r\n00123;Pastiglie freno;Pastiglie Freno;10.00\r\n00456;Filtro aria;Filtri Aria;\r\n");
$app->store->put_record('upload',$upload,['id'=>$upload,'name'=>'fornitore.csv','size'=>filesize($data.'/uploads/'.$upload.'.csv')]);
$request=['profile_id'=>'supplier-profile','uploads'=>[['upload_id'=>$upload,'role'=>'catalog']],'request_key'=>'import-example'];
$job=$api->dispatch('POST','/imports',[],$request);
verify($job['id'] === $api->dispatch('POST','/imports',[],$request)['id'], 'Duplicate request remains idempotent.');
for($n=0;$job['state']!=='completed'&&$n<50;$n++) $job=$api->dispatch('POST','/jobs/'.$job['id'].'/step');
verify($job['state']==='completed','Import reaches completion.');
$import=$job['result']['import_id'];
$page=$api->dispatch('GET','/products',['import_id'=>$import]); verify($page['total']===2,'Products persist.');
$product=$page['items'][0]; verify($product['code']==='00123','Leading zero preserved.');
$fits=[['id'=>Support::uuid(),'make'=>'Honda','model'=>'CBR 600','displacement'=>'600','year_from'=>2007,'year_to'=>2012,'vehicle_type'=>'moto']];
$api->dispatch('PUT','/products/'.$product['id'].'/compatibilities',[],['rows'=>$fits]);
$pricing=['shipping_cost'=>'5','product_markup'=>'.2','shipping_markup'=>'0','commission_rate'=>'.1','quantity'=>1];
$platform=Defaults::platform('ebay'); $platform['free_shipping_confirmed']=true;
$api->dispatch('PUT','/settings/ebay',[],['platform_profile'=>$platform,'price_settings'=>$pricing]);
$job=$api->dispatch('POST','/imports/'.$import.'/validate',[],['destination'=>'ebay','request_key'=>'validate-example']);
for($n=0;$job['state']!=='completed'&&$n<50;$n++) $job=$api->dispatch('POST','/jobs/'.$job['id'].'/step');
verify($job['state']==='completed','Validation reaches completion.');
$drafts=$api->dispatch('GET','/imports/'.$import.'/drafts',['destination'=>'ebay']);
verify($drafts['counts']['ready']===1 && $drafts['counts']['errors']===1,'Valid rows can be exported while errors remain.');
$job=$api->dispatch('POST','/exports',[],['import_id'=>$import,'destination'=>'ebay','request_key'=>'export-example','selection_mode'=>'new_valid']);
for($n=0;$job['state']!=='completed'&&$n<100;$n++) $job=$api->dispatch('POST','/jobs/'.$job['id'].'/step');
verify($job['state']==='completed','Export reaches completion.');
$history=$api->dispatch('GET','/exports',['import_id'=>$import]);
verify($history[0]['included_count']===1,'Partial valid export recorded.');
invalid(fn() => $api->dispatch('GET','/exports/'.$history[0]['id'].'/files/../config.json'), 'not_found');
$other=$page['items'][1];
$fixed=$api->dispatch('PATCH','/products/'.$other['id'],[],['updates'=>['cost'=>'12'],'resolve_fields'=>['cost']]);
verify($fixed['cost']==='12','Cost corrections persist.');
invalid(fn() => $api->dispatch('PATCH','/products/'.$other['id'],[],['updates'=>['cost'=>'-2']]), 'invalid_input');
invalid(fn() => $api->dispatch('PATCH','/products/'.$other['id'],[],['updates'=>['supplier_id'=>'change-identity']]), 'invalid_input');
echo "PASS: validated API, import/compatibility/pricing/checkpointed validation/partial export and corrections.\n";
