<?php
declare(strict_types=1);

if (is_file(dirname(__DIR__).'/bootstrap.php')) require_once dirname(__DIR__).'/bootstrap.php';
else spl_autoload_register(static function(string $name): void {
    $prefix = 'MotorWorld\\Hosting\\';
    if (str_starts_with($name, $prefix)) {
        $path = dirname(__DIR__) . '/src/' . substr($name, strlen($prefix)) . '.php';
        if (is_file($path)) require_once $path;
    }
});

use MotorWorld\Hosting\Store;
use MotorWorld\Hosting\JobService;
use MotorWorld\Hosting\AppError;

$checks=0;
function check(bool $value, string $message): void { global $checks; ++$checks; if (!$value) throw new RuntimeException($message); }
function product(string $id='p1', string $code='001'): array {
    return ['id'=>$id,'supplier_id'=>'supplier','code'=>$code,'name'=>'Originale','brand'=>'A','family'=>'F','department'=>'D','cost'=>'10.20','ean'=>null,'image_urls'=>[],'available'=>true,'packaging'=>[],'extra'=>['source'=>'yes'],'sources'=>[],'diagnostics'=>[['level'=>'error','code'=>'cost_required','message'=>'Costo','field'=>'cost']]];
}
function draft(string $id='d1', string $product='p1', string $reference='R1'): array {
    return ['id'=>$id,'product_id'=>$product,'compatibility_id'=>null,'destination'=>'ebay','reference'=>$reference,'title'=>'Titolo','category_ids'=>['cat'],'price'=>null,'quantity'=>1,'description_html'=>'','fields'=>[],'diagnostics'=>[],'valid'=>true,'exported'=>false,'stale'=>false];
}
function expectCode(callable $fn, string $code, ?int $status=null): void {
    try { $fn(); } catch (AppError $error) { check($error->errorCode === $code, 'wrong error code for '.$code); if ($status!==null) check($error->status===$status,'HTTP status for '.$code); return; }
    throw new RuntimeException('Expected '.$code);
}
function child(string $mode, string $dir, string $id): array {
    $command=[PHP_BINARY,__FILE__,$mode,$dir,$id];
    if ($mode==='performance') $command=array_merge(['timeout','12'],$command);
    $process = proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    check(is_resource($process),'child process starts'); fclose($pipes[0]); return [$process,$pipes];
}
function finishChild(array $child, int $expected=0): void {
    [$process,$pipes]=$child; $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    $status=proc_close($process);
    check($status===$expected && $out==='' && $err==='', 'child status '.$status.' '.$out.' '.$err);
}
function waitFile(string $path): void {
    for ($i=0;$i<100 && !is_file($path);$i++) usleep(20000);
    check(is_file($path),'child reached handler');
}
function appendHandler(Store $store, bool $crash=false): callable {
    return static function(array $job,array $checkpoint) use($store,$crash): array {
        $offset=$checkpoint['offset'] ?? 0; $path=$store->dataDir().'/spool.txt'; $file=fopen($path,'c+b');
        ftruncate($file,$offset); fseek($file,$offset); fwrite($file,"row\n"); fflush($file); fclose($file);
        if ($crash) exit(98);
        $next=$offset+4;
        if ($next>=8) $store->put_record('publication',$job['id'],['bytes'=>$next]);
        return ['done'=>$next>=8,'checkpoint'=>['offset'=>$next],'progress'=>['stage'=>'Append','completed'=>$next,'total'=>8],'result'=>['bytes'=>$next]];
    };
}

if (($argv[1] ?? '')==='worker') {
    $store=new Store($argv[2]); $jobs=new JobService($store);
    $jobs->register('slow',static function(array $job,array $checkpoint) use($store): array {
        file_put_contents($store->dataDir().'/entered-'.$job['id'],'yes'); usleep(800000);
        $store->put_record('publication',$job['id'],['count'=>1]);
        return ['done'=>true,'checkpoint'=>['count'=>1],'progress'=>['stage'=>'Fine','completed'=>1,'total'=>1],'result'=>['ok'=>true]];
    });
    $jobs->step($argv[3]); exit;
}
if (($argv[1] ?? '')==='crash') {
    $store=new Store($argv[2]); $jobs=new JobService($store); $jobs->register('append',appendHandler($store,true)); $jobs->step($argv[3]); exit;
}
if (($argv[1] ?? '')==='bounded') {
    ini_set('memory_limit','24M');
    $store=new Store($argv[2]);
    $store->transaction(static function() use($store): void {
        for ($i=0;$i<30000;$i++) $store->stage_record('big','import_diagnostic',(string)$i,['level'=>'error','code'=>'big','message'=>str_repeat('x',1000)],$i);
        $store->publish_import('big','bounded',['diagnostics'=>null,'source_counts'=>[]]);
    });
    check((int)$store->pdo()->query("SELECT json_array_length(payload,'$.diagnostics') FROM records WHERE kind='import_summary' AND id='bounded'")->fetchColumn()===30000,'native SQLite aggregation retains all diagnostics');
    exit;
}
if (($argv[1] ?? '')==='constructor') {
    $store=new Store($argv[2]); check((int)$store->pdo()->query('SELECT version FROM schema_version')->fetchColumn()===2,'concurrent schema creation'); exit;
}
if (($argv[1] ?? '')==='performance') {
    ini_set('memory_limit','64M'); $store=new Store($argv[2]);
    $store->transaction(static function() use($store): void {
        for ($i=0;$i<2000;$i++) {
            $row=draft('bulk-'.$i,'bulk-product-'.$i,'bulk-ref-'.$i); $row['description_html']=str_repeat('x',10000);
            $store->stage_draft('bulk-validation',$row,$i);
            $store->stage_record('bulk-export','export_included',$row['id'],['id'=>$row['id']],$i);
            $store->stage_record('bulk-export','export_summary',$row['id'],['id'=>$row['id'],'description'=>$row['description_html']],$i);
        }
        $store->publish_export('bulk-export','bulk-existing',['import_id'=>'bulk','destination'=>'ebay','snapshot'=>['profile'=>[]],'artifacts'=>[],'diagnostics'=>[]]);
        $store->publish_drafts('bulk-validation','bulk',['ebay']);
    });
    check($store->count_filtered_drafts('bulk','ebay','exported')===2000,'bulk validation preserves all previously exported identities');
    exit;
}
if (($argv[1] ?? '')==='submit') {
    $store=new Store($argv[2]); $jobs=new JobService($store); $jobs->register('test',static fn($j,$c)=>['done'=>true,'checkpoint'=>[],'progress'=>['stage'=>'Done','completed'=>1,'total'=>1]]);
    try { $job=$jobs->submit('test',[], $argv[3]); $out=['id'=>$job['id']]; }
    catch (AppError $error) { $out=['error'=>$error->errorCode,'status'=>$error->status]; }
    file_put_contents($store->dataDir().'/submit-'.$argv[3].'.json',json_encode($out,JSON_THROW_ON_ERROR)); exit;
}

$dir=sys_get_temp_dir().'/mw-storage-'.bin2hex(random_bytes(5)); mkdir($dir,0700,true);
for ($round=0;$round<5;$round++) {
    $constructors=[]; for ($i=0;$i<8;$i++) $constructors[]=child('constructor',$dir.'/first-use-'.$round,'');
    foreach ($constructors as $constructor) finishChild($constructor);
}
$store=new Store($dir);
check((int)$store->pdo()->query('SELECT version FROM schema_version')->fetchColumn()===2,'SQLite schema v2');
$store->save_profile(['id'=>'profile','supplier_id'=>'supplier','name'=>'Profilo']);
check($store->get_profile('profile')['name']==='Profilo','saved supplier profile');
check($store->get_platform_profile('ebay')['return_days']===60,'platform profile defaults');
$platform=$store->get_platform_profile('prestashop'); $platform['tax_rule_id']='7'; $store->save_platform_profile($platform);
check($store->get_platform_profile('prestashop')['tax_rule_id']==='7','saved platform profile');
$rule=['supplier_id'=>'supplier','family'=>'Fìltro Aria','context'=>'moto','destination'=>'ebay','category_id'=>'1'];
$store->save_category_rule($rule); $rule['family']='filtro aria'; $rule['category_id']='2'; $store->save_category_rule($rule);
check(count($store->list_category_rules('supplier'))===1 && $store->list_category_rules('supplier')[0]['category_id']==='2','normalized category rule identity');
$store->save_import('i1',['products'=>[product()],'diagnostics'=>[],'source_counts'=>['one.csv'=>1]]);
$edited=$store->get_product('p1'); $edited['name']='Manuale'; $edited['cost']='22.00'; $edited['extra']['custom']='operator'; $edited['diagnostics']=[];
$store->save_product($edited,['cost']);
$store->save_compatibilities('p1',[['id'=>'fit1','product_id'=>'p1','make'=>'Honda','model'=>'CB']]);
$store->save_drafts('i1',[draft()]); $store->save_overrides([['listing_id'=>'d1','title'=>'Manual title']]);
$store->record_export(['id'=>'e1','import_id'=>'i1','destination'=>'ebay','included_ids'=>['d1'],'excluded_ids'=>[],'directory'=>'/old/data/exports/e1','artifacts'=>[],'snapshot'=>[]]);
unset($store); $store=new Store($dir);
check($store->get_product('p1')['name']==='Manuale','manual correction survives reopen');
check($store->get_compatibilities('p1')[0]['model']==='CB','compatibilities survive reopen');
check($store->get_overrides('p1')['d1']['title']==='Manual title','overrides survive reopen');
$original=product(); $original['cost']='12.00'; $original['name']='Nuovo fornitore';
$store->save_import('i2',['products'=>[$original],'diagnostics'=>[],'source_counts'=>['two.csv'=>1]]);
$current=$store->get_product('p1');
check($current['name']==='Manuale' && $current['cost']==='22.00','reimport retains manual edits');
check($current['extra']['source']==='yes' && $current['extra']['custom']==='operator','reimport merges extras');
check($store->prepare_import_product($original)['cost']==='22.00','bounded import preparation retains corrections');
check(count(array_filter($current['diagnostics'],fn($d)=>$d['code']==='manual_correction_retained'))===1,'reimport warning');
check($store->get_draft('d1')['stale']===true,'reimport marks drafts stale');
check($store->get_draft('d1')['exported']===true,'exported flag survives stale');
$store->save_drafts('i1',[draft()]); check($store->get_draft('d1')['exported']===true,'validation preserves export history');
$store->record_export(['id'=>'e1','import_id'=>'wrong','included_ids'=>[]]);
check($store->get_export('e1')['import_id']==='i1','export history immutable');
check($store->get_export('e1')['directory']===$dir.'/exports/e1','export directory resolves current data dir');
$reset=$store->reset_product_corrections('p1'); check($reset['cost']==='12.00' && $reset['name']==='Nuovo fornitore','reset restores latest supplier source');
$store->save_import('i3',['products'=>[product('p2','002')],'diagnostics'=>[],'source_counts'=>[]]);
$store->save_drafts('i3',[draft('d2','p2','R1')]);
check(!$store->get_draft('d2')['valid'],'cross-import reference collision');
check($store->list_products(null,['query'=>'002'],0,10)['total']===1,'product search');
check($store->count_products('i3')===1 && iterator_to_array($store->iter_products('i3',0,1))[0]['code']==='002','product count and streaming preserve default membership/order');
check($store->list_products('i3',['category_id'=>'cat','state'=>'errors','destination'=>'ebay'],0,10)['total']===1,'product draft state filters');
check($store->list_drafts('i3','ebay','errors',0,10)['counts']['errors']===1,'draft state counts');
check($store->get_draft('d2')['diagnostics'][0]['code']==='duplicate_reference' && $store->get_draft('d2')['diagnostics'][0]['listing_id']==='d2','SQL collision diagnostic preserves listing identity');
check($store->count_filtered_drafts('i3','ebay','errors')===1,'filtered draft count without dashboard scans');
check(iterator_to_array($store->iter_filtered_drafts('i3','ebay','errors',0,10))[0]['id']==='d2','filtered drafts stream in insertion order');
check($store->count_filtered_drafts('i3','ebay',null,'002','cat',true)===1,'stream count shares query/category/availability filters');
check(iterator_to_array($store->iter_filtered_drafts('i3','ebay',null,1,10))===[],'stream offset pagination');
$store->save_drafts('duplicates',[draft('dup1','p1','DUP'),draft('dup2','p1','DUP')]);
check(!$store->get_draft('dup1')['valid'] && !$store->get_draft('dup2')['valid'],'grouped duplicate references invalidate every same-product variant');
expectCode(fn()=>$store->save_drafts('i3',[draft('same'),draft('same')]),'listing_identity_collision');
$store->stage_product('stage',product('p3','003'),0); check($store->staged_count('stage','products')===1,'staged product count');
check($store->list_products(null,[],0,20)['total']===2,'staging hidden until publication');
$store->stage_record('stage','import_diagnostic','diag',['level'=>'warning','code'=>'test','message'=>'Stage'],0);
$store->publish_import('stage','i4',['diagnostics'=>null,'source_counts'=>['three.csv'=>1]]);
check($store->get_import_summary('i4')['product_count']===1,'staged import publication');
check($store->get_import('i4')['original_products'][0]['id']==='p3','original products snapshot');
check($store->get_import_summary('i4')['diagnostics'][0]['code']==='test','SQL staged diagnostics');
$raceRaw=product('race','004'); $store->save_import('race-first',['products'=>[$raceRaw],'diagnostics'=>[],'source_counts'=>[]]);
$raceRaw['name']='Incoming'; $store->stage_product('race-stage',$store->prepare_import_product($raceRaw),0,$raceRaw);
$latest=$store->get_product('race'); $latest['name']='Latest manual'; $store->save_product($latest);
check(!$store->import_preparation_is_current('race-stage'),'new correction invalidates previously prepared import');
expectCode(fn()=>$store->publish_import('race-stage','race-second',['diagnostics'=>[],'source_counts'=>[]]),'import_corrections_changed');
check($store->get_product('race')['name']==='Latest manual','rejected stale publication preserves current correction');
$store->stage_product('race-stage',$store->prepare_import_product($raceRaw),0,$raceRaw);
check($store->import_preparation_is_current('race-stage'),'bounded restaging confirms latest correction revision');
$store->publish_import('race-stage','race-second',['diagnostics'=>[],'source_counts'=>[]]);
check($store->get_product('race')['name']==='Latest manual' && $store->get_import('race-second')['original_products'][0]['name']==='Incoming','restaging keeps latest manual edit and raw source snapshot');
$store->stage_product('race-reset',$store->prepare_import_product($raceRaw),0,$raceRaw); $store->reset_product_corrections('race');
check(!$store->import_preparation_is_current('race-reset'),'source reset invalidates previously prepared correction');
expectCode(fn()=>$store->publish_import('race-reset','race-third',['diagnostics'=>[],'source_counts'=>[]]),'import_corrections_changed');
$store->stage_product('race-reset',$store->prepare_import_product($raceRaw),0,$raceRaw); $store->publish_import('race-reset','race-third',['diagnostics'=>[],'source_counts'=>[]]);
check($store->get_product('race')['name']==='Incoming','restaging after reset does not resurrect removed correction');
$store->stage_draft('validation',draft('d3','p3','R3'),0); $store->publish_drafts('validation','i4',['ebay']);
check($store->get_draft('d3')['valid'],'staged draft publication');
$store->publish_drafts('empty-validation','i4',['ebay']); check($store->list_drafts('i4','ebay',null,0,10)['total']===0,'empty validation removes obsolete drafts');
$store->save_drafts('i4',[draft('d3','p3','R3')]);
$store->stage_record('export-stage','export_included','d3',['id'=>'d3'],0);
$store->stage_record('export-stage','export_excluded','bad',['id'=>'bad'],0);
$store->stage_record('export-stage','export_summary','d3',['listing_id'=>'d3','title'=>'Snapshot'],0);
$store->publish_export('export-stage','e2',['import_id'=>'i4','destination'=>'ebay','artifacts'=>[],'diagnostics'=>[],'snapshot'=>['profile'=>['destination'=>'ebay']]]);
check($store->get_export('e2')['included_ids']===['d3'] && $store->get_export('e2')['excluded_ids']===['bad'],'staged export selection');
check($store->get_export('e2')['snapshot']['rows'][0]['title']==='Snapshot','immutable staged export snapshot');
check($store->get_draft('d3')['exported']===true,'staged export marks included draft');
$store->publish_export('export-stage','e2',['import_id'=>'changed','snapshot'=>[]]);
check($store->get_export('e2')['import_id']==='i4','staged export retry preserves original snapshot');
finishChild(child('bounded',$dir,''));
finishChild(child('performance',$dir.'/bulk',''));
$jobs=new JobService($store); $jobs->register('test',static fn($j,$c)=>['done'=>true,'checkpoint'=>[],'progress'=>['stage'=>'done','completed'=>1,'total'=>1]]);
$job=$jobs->submit('test',['a'=>1,'b'=>2],'key');
check($jobs->submit('test',['b'=>2,'a'=>1],'key')['id']===$job['id'],'duplicate intent idempotent ignoring key order');
expectCode(fn()=>$jobs->submit('test',[],'distinct-while-queued'),'job_in_progress',409);
check(!array_key_exists('checkpoint',$jobs->get($job['id'])),'job model has no checkpoint');
check($jobs->step($job['id'])['state']==='completed','bounded job completion');
$profileJob=$jobs->submit('test',['profile'=>\MotorWorld\Hosting\Defaults::platform('ebay')],'profile-empty-object');
check($jobs->submit('test',['profile'=>\MotorWorld\Hosting\Defaults::platform('ebay')],'profile-empty-object')['id']===$profileJob['id'],'recursive empty JSON objects remain idempotent after SQLite decode');
expectCode(fn()=>$jobs->submit('test',['a'=>2],'key'),'request_key_conflict');
check($jobs->submit('test',['a'=>1,'b'=>2],'key')['id']===$job['id'],'completed duplicate returned before unrelated active-job guard');
$jobs->step($profileJob['id']);
$jobs->register('slow',static fn($j,$c)=>throw new RuntimeException('concurrent worker should own lock'));
$slow=$jobs->submit('slow',[],'slow-key'); $child=child('worker',$dir,$slow['id']); waitFile($dir.'/entered-'.$slow['id']);
$start=microtime(true); $busy=$jobs->step($slow['id']);
check(microtime(true)-$start<0.5 && $busy['state']==='running','second process step is nonblocking');
$freshJobs=new JobService(new Store($dir)); check($freshJobs->get($slow['id'])['state']==='running','new service and GET do not reset worker');
finishChild($child); check($jobs->get($slow['id'])['attempts']===1,'concurrent step executes once');
check($store->get_record('publication',$slow['id'])['count']===1,'single publication');
$otherA=$jobs->submit('slow',[],'other-a'); $legacy=$otherA; $legacy['id']=\MotorWorld\Hosting\Support::uuid(); $legacy['request_key']='other-b'; $otherB=$store->create_job($legacy);
expectCode(fn()=>$jobs->step($otherB['id']),'job_in_progress',409);
$childA=child('worker',$dir,$otherA['id']); waitFile($dir.'/entered-'.$otherA['id']);
$childB=child('worker',$dir,$otherB['id']); finishChild($childA); finishChild($childB);
check($jobs->get($otherA['id'])['state']==='completed' && $jobs->get($otherB['id'])['state']==='completed','different jobs serialize SQLite writes without snapshot deadlocks');
$jobs->register('append',appendHandler($store)); $append=$jobs->submit('append',[],'append-key'); $jobs->step($append['id']);
finishChild(child('crash',$dir,$append['id']),98);
check(filesize($dir.'/spool.txt')===8,'unconfirmed append exists after process death');
$running=$jobs->get($append['id']); check($running['state']==='running' && $running['progress']['completed']===4,'GET preserves last confirmed progress');
$resumed=$jobs->step($append['id']); check($resumed['state']==='completed','killed step restarts from persisted checkpoint');
check(file_get_contents($dir.'/spool.txt')==="row\nrow\n",'retry truncates unconfirmed bytes without duplicate rows');
check($store->get_record('publication',$append['id'])['bytes']===8,'publication confirmed once');
$jobs->register('fails',static function($j,$c) use($store): array { $store->put_record('unconfirmed',$j['id'],['bad'=>true]); throw new RuntimeException('failure'); });
$failed=$jobs->submit('fails',[],'failed-key'); check($jobs->step($failed['id'])['state']==='failed','handler error persisted');
check($store->list_records('unconfirmed')===[],'failed handler transaction rolled back');
$jobs->register('fails',static fn($j,$c)=>['done'=>true,'checkpoint'=>[],'progress'=>['stage'=>'Recovered','completed'=>1,'total'=>1]]);
$blocker=$jobs->submit('test',[],'resume-blocker'); expectCode(fn()=>$jobs->resume($failed['id']),'job_in_progress',409); $jobs->step($blocker['id']);
check($jobs->resume($failed['id'])['state']==='queued','explicit resume'); check($jobs->step($failed['id'])['state']==='completed','resumed handler completes');
$publishedBytes="\xEF\xBB\xBFcode;title\r\n001;Originale\r\n";
$outside=$dir.'/uploads/preserved'; mkdir($outside,0700,true); file_put_contents($outside.'/original.csv','supplier original');
$jobs->register('export',static function(array $job,array $checkpoint) use($store,$publishedBytes,$outside): array {
    $work=$store->dataDir().'/work/'.$job['id']; mkdir($work.'/nested',0700,true); file_put_contents($work.'/records.spool','confirmed spool'); file_put_contents($work.'/nested/chunk.tmp','temporary'); symlink($outside,$work.'/supplier-link');
    $published=$store->dataDir().'/exports/'.$job['id']; mkdir($published,0700,true); file_put_contents($published.'/prodotti.csv',$publishedBytes);
    $store->stage_record($job['id'],'export_summary','summary',['title'=>'Originale'],0);
    $store->publish_export($job['id'],$job['id'],['import_id'=>'i4','destination'=>'ebay','snapshot'=>['profile'=>[]],'artifacts'=>[['name'=>'prodotti.csv','size'=>strlen($publishedBytes),'rows'=>1,'kind'=>'csv']],'diagnostics'=>[]]);
    return ['done'=>true,'checkpoint'=>['body_offset'=>15],'progress'=>['stage'=>'Pubblicato','completed'=>1,'total'=>1],'result'=>['export_id'=>$job['id']]];
});
$clean=$jobs->submit('export',[],'cleanup-export'); check($jobs->step($clean['id'])['state']==='completed','export commits before cleanup');
check(!file_exists($dir.'/work/'.$clean['id']),'completed work and nested temporary files removed');
check($store->staged_count($clean['id'],'export_summary')===0,'completed SQL staging removed');
check(file_get_contents($store->get_export($clean['id'])['directory'].'/prodotti.csv')===$publishedBytes,'published CSV remains downloadable after cleanup');
check(file_get_contents($outside.'/original.csv')==='supplier original','cleanup never follows supplier symlink');
check($store->get_checkpoint($clean['id'])['body_offset']===15 && is_file($dir.'/locks/job-'.hash('sha256',$clean['id']).'.lock'),'checkpoint and lock inode retained');
$jobs->register('import',static function(array $job,array $checkpoint) use($store): array {
    $work=$store->dataDir().'/work/'.$job['id'];
    if (!$checkpoint) {
        mkdir($work,0700,true); file_put_contents($work.'/records.spool','confirmed recovery bytes'); $store->stage_record($job['id'],'recovery','one',['ok'=>true],0);
        return ['done'=>false,'checkpoint'=>['confirmed'=>true],'progress'=>['stage'=>'Parziale','completed'=>1,'total'=>2]];
    }
    throw new RuntimeException('processing failure');
});
$recoverable=$jobs->submit('import',[],'cleanup-failed'); $jobs->step($recoverable['id']); check($jobs->step($recoverable['id'])['state']==='failed','processing error retained');
check(file_get_contents($dir.'/work/'.$recoverable['id'].'/records.spool')==='confirmed recovery bytes' && $store->staged_count($recoverable['id'],'recovery')===1,'failed work and staging remain resumable');
$cleanupFailure=$jobs->submit('export',[],'cleanup-failure');
$store->pdo()->exec('CREATE TEMP TRIGGER block_cleanup BEFORE DELETE ON staging_records WHEN OLD.job_id='.$store->pdo()->quote($cleanupFailure['id'])." BEGIN SELECT RAISE(FAIL,'cleanup blocked'); END");
check($jobs->step($cleanupFailure['id'])['state']==='completed' && $jobs->get($cleanupFailure['id'])['state']==='completed','postcommit cleanup failure cannot fail completed export');
check(file_get_contents($store->get_export($cleanupFailure['id'])['directory'].'/prodotti.csv')===$publishedBytes,'cleanup failure preserves downloadable artifact');
$store->pdo()->exec('DROP TRIGGER block_cleanup');
$jobs->register('import',static fn($j,$cp)=>['done'=>true,'checkpoint'=>$cp,'progress'=>['stage'=>'Recovered','completed'=>2,'total'=>2]]);
$jobs->resume($recoverable['id']); check($store->get_checkpoint($recoverable['id'])['confirmed']===true,'resume without a hook preserves confirmed checkpoint'); $jobs->step($recoverable['id']);
$store->put_record('restart_input','current',['value'=>'Old snapshot']);
$restartHandler=static function(array $job,array $cp) use($store): array {
    if (!$cp) {
        $value=$store->get_record('restart_input','current')['value']; $store->stage_record($job['id'],'restart','row',['value'=>$value],0);
        return ['done'=>false,'checkpoint'=>['snapshot'=>$value],'progress'=>['stage'=>'Prepared','completed'=>1,'total'=>2]];
    }
    if (is_file($store->dataDir().'/restart-failure')) throw new RuntimeException('interrupted validation');
    $store->put_record('restart_result',$job['id'],['value'=>$cp['snapshot']]);
    return ['done'=>true,'checkpoint'=>$cp,'progress'=>['stage'=>'Published','completed'=>2,'total'=>2],'result'=>['value'=>$cp['snapshot']]];
};
$jobs->register('restartable',$restartHandler); $restart=$jobs->submit('restartable',[],'restart-hook'); $jobs->step($restart['id']);
file_put_contents($dir.'/restart-failure','yes'); check($jobs->step($restart['id'])['state']==='failed','prepared validation failure persisted'); unlink($dir.'/restart-failure');
$store->put_record('restart_input','current',['value'=>'Latest snapshot']); $hookInvoked=false;
$jobs->register('restartable',$restartHandler,static function(array $job) use($store): void {
    $store->clear_staging($job['id']); $store->save_checkpoint($job['id'],['partial_restart'=>true]); throw new AppError('resume_failure','Ripresa non disponibile.');
});
expectCode(fn()=>$jobs->resume($restart['id']),'resume_failure');
check($jobs->get($restart['id'])['state']==='failed' && $store->get_checkpoint($restart['id'])['snapshot']==='Old snapshot' && $store->staged_count($restart['id'],'restart')===1,'failing resume hook rolls back checkpoint and staging together');
$jobs->register('restartable',$restartHandler,static function(array $job) use($store,&$hookInvoked): void {
    check($store->pdo()->inTransaction() && $job['state']==='failed','resume hook runs in writer transaction before queued'); $hookInvoked=true;
    $store->clear_staging($job['id']); $store->save_checkpoint($job['id'],[]);
});
$jobs->resume($restart['id']); check($hookInvoked && $store->get_checkpoint($restart['id'])===[],'explicit restart hook discards failed prepared snapshot');
$jobs->step($restart['id']); $jobs->step($restart['id']);
check($store->get_record('restart_result',$restart['id'])['value']==='Latest snapshot','resumed validation publishes fresh input after restart hook');
$concurrentA=child('submit',$dir.'/submissions','a'); $concurrentB=child('submit',$dir.'/submissions','b'); finishChild($concurrentA); finishChild($concurrentB);
$submitResults=[json_decode(file_get_contents($dir.'/submissions/submit-a.json'),true),json_decode(file_get_contents($dir.'/submissions/submit-b.json'),true)];
check(count(array_filter($submitResults,fn($r)=>isset($r['id'])))===1 && count(array_filter($submitResults,fn($r)=>($r['error'] ?? null)==='job_in_progress' && $r['status']===409))===1,'concurrent distinct submissions create exactly one active job');
$app=new \MotorWorld\Hosting\Application($dir.'/serial-catalog',\MotorWorld\Hosting\Support::resources()); $api=new \MotorWorld\Hosting\Router($app); $originals=[];
for ($i=0;$i<101;$i++) {
    $code=str_pad((string)$i,4,'0',STR_PAD_LEFT); $originals[]=\MotorWorld\Hosting\Defaults::product(['id'=>\MotorWorld\Hosting\Defaults::productId('serial',$code),'supplier_id'=>'serial','code'=>$code,'name'=>'Old item','family'=>'Pastiglie Freno','cost'=>'10']);
}
$app->store->save_import('original',['products'=>$originals,'diagnostics'=>[],'source_counts'=>[]]);
foreach ($originals as $p) $app->store->save_compatibilities($p['id'],[\MotorWorld\Hosting\Defaults::compatibility(['id'=>'fit-'.$p['id'],'product_id'=>$p['id'],'make'=>'Honda','model'=>'CBR600','vehicle_type'=>'moto'])]);
$profile=\MotorWorld\Hosting\Defaults::platform('ebay'); $profile['free_shipping_confirmed']=true; $app->store->save_platform_profile($profile);
$app->store->put_record('price_settings','ebay',['shipping_cost'=>'5','product_markup'=>'.2','shipping_markup'=>'0','commission_rate'=>'.1','quantity'=>1,'commission_fixed'=>'0','vat_rate'=>'.22']);
mkdir($app->store->dataDir().'/uploads'); $upload=\MotorWorld\Hosting\Support::uuid(); $csv="Code;Name;Family;Cost\r\n";
foreach ($originals as $p) $csv.=$p['code'].";New item;Pastiglie Freno;90\r\n";
file_put_contents($app->store->dataDir().'/uploads/'.$upload.'.csv',$csv); $app->store->put_record('upload',$upload,['name'=>'new.csv']);
$app->store->save_profile(['id'=>'serial-profile','supplier_id'=>'serial','bindings'=>[['role'=>'catalog','encoding'=>'utf-8','delimiter'=>';','decimal_separator'=>'.','column_map'=>['code'=>'Code','name'=>'Name','family'=>'Family','cost'=>'Cost']]]]);
$validationBody=['destination'=>'ebay','request_key'=>'serial-validation'];
$validation=$api->dispatch('POST','/imports/original/validate',[],$validationBody); $validation=$app->jobs->step($validation['id']);
check($validation['state']==='running' && $validation['progress']['completed']<101,'real validation remains pending after a bounded catalog batch');
$importBody=['profile_id'=>'serial-profile','uploads'=>[['upload_id'=>$upload,'role'=>'catalog']],'request_key'=>'serial-reimport'];
expectCode(fn()=>$api->dispatch('POST','/imports',[],$importBody),'job_in_progress',409);
check($api->dispatch('POST','/imports/original/validate',[],$validationBody)['id']===$validation['id'],'duplicate active validation remains idempotent');
for ($i=0;$i<10 && $validation['state']!=='completed';$i++) $validation=$app->jobs->step($validation['id']);
check($validation['state']==='completed' && $app->store->count_filtered_drafts('original','ebay','valid')===101,'whole validation completes before next catalog-changing job');
$oldDraft=$app->store->list_drafts('original','ebay',null,0,1)['items'][0];
$reimport=$api->dispatch('POST','/imports',[],$importBody);
for ($i=0;$i<20 && $reimport['state']!=='completed';$i++) $reimport=$app->jobs->step($reimport['id']);
check($reimport['state']==='completed' && $app->store->get_product($originals[0]['id'])['cost']==='90','real reimport starts after full validation releases job owner');
check($app->store->count_filtered_drafts('original','ebay','stale')===101 && $app->store->get_draft($oldDraft['id'])['price']===$oldDraft['price'],'reimport marks prior uniform validation stale without publishing mixed prices');
echo 'storage_jobs: '.$checks." behavioral checks passed\n";
