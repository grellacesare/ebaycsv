<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
use MotorWorld\Hosting\{Application, Router, Defaults, Support};

$app = new Application(sys_get_temp_dir().'/mw-resume-validation-'.bin2hex(random_bytes(6)), Support::resources());
$api = new Router($app);
$products=[];
for($i=0;$i<101;$i++)$products[]=Defaults::product(['id'=>'resume-'.$i,'supplier_id'=>'s',
    'code'=>sprintf('%04d',$i),'name'=>'Old item','family'=>'Pastiglie Freno','cost'=>'10']);
$app->store->save_import('original',['products'=>$products,'diagnostics'=>[],'source_counts'=>[]]);
$profile=Defaults::platform('ebay');$profile['free_shipping_confirmed']=true;
$app->store->save_platform_profile($profile);
$prices=['shipping_cost'=>'5','product_markup'=>'.2','shipping_markup'=>'0','commission_rate'=>'.1','quantity'=>1];
$app->store->put_record('price_settings','ebay',Defaults::priceSettings($prices));
$job=$api->dispatch('POST','/imports/original/validate',[],['destination'=>'ebay','request_key'=>'resume-current-inputs']);
$job=$app->jobs->step($job['id']);
if($job['state']!=='running'||$job['progress']['completed']<1)throw new RuntimeException('Fixture did not leave a partial validation.');
// A new PHP request can encounter an incomplete FTP update or another transient resource failure.
$broken=new Application($app->store->dataDir(),sys_get_temp_dir().'/missing-mw-builder-'.bin2hex(random_bytes(6)));
$failed=$broken->jobs->step($job['id']);
if($failed['state']!=='failed')throw new RuntimeException('Expected resource failure.');
$api->dispatch('PATCH','/products/resume-0',[],['updates'=>['name'=>'New item','cost'=>'90']]);
$prices['product_markup']='.5';$prices['commission_rate']='.15';
$api->dispatch('PUT','/settings/ebay',[],['platform_profile'=>$profile,'price_settings'=>$prices]);
$api->dispatch('POST','/jobs/'.$job['id'].'/resume');
for($step=0;$step<110;$step++){
    $job=$app->jobs->step($job['id']);
    if($job['state']==='completed')break;
    if($job['state']==='failed')throw new RuntimeException($job['error']);
}
$draft=$app->store->list_drafts('original','ebay',null,0,1)['items'][0];
if($job['state']!=='completed'||!str_starts_with($draft['title'],'New item')
    ||$draft['price']['final_gross']!=='209.90'||$draft['stale']){
    throw new RuntimeException('Resumed validation published old products or old price settings: '.Support::json([
        'state'=>$job['state'],'title'=>$draft['title'],'price'=>$draft['price']['final_gross'],'stale'=>$draft['stale']]));
}
echo "PASS: failed validation restarts with current product corrections and current price settings.\n";
