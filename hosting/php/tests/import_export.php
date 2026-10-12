<?php
declare(strict_types=1);

if (is_file(dirname(__DIR__) . '/bootstrap.php')) require_once dirname(__DIR__) . '/bootstrap.php';
else spl_autoload_register(function(string $class): void {
    $prefix='MotorWorld\\Hosting\\';
    if (str_starts_with($class,$prefix)) {
        $path=dirname(__DIR__).'/src/'.substr($class,strlen($prefix)).'.php';
        if (is_file($path)) require_once $path;
    }
});

use MotorWorld\Hosting\{AppError, CsvWriter, ExportService, ImportService, JobService, Store, ZipWriter};

function same(mixed $actual, mixed $expected, string $name): void {
    if ($actual !== $expected) {
        throw new RuntimeException($name . ': expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE) . ', got ' . json_encode($actual, JSON_UNESCAPED_UNICODE));
    }
}
function canonical(mixed $value): mixed {
    if (!is_array($value)) return $value;
    if (!array_is_list($value)) ksort($value);
    return array_map('canonical', $value);
}
function complete(object $service, Store $store, array $job, ?callable $interruption = null, bool $crash = false): array {
    $checkpoint = [];
    $crashed=[];
    for ($steps=0; $steps<20000; $steps++) {
        try {
            $result = $store->transaction(function() use ($service,$job,$checkpoint,$crash,&$crashed) {
                $result=$service->step($job,$checkpoint);
                $phase=$checkpoint['phase']??'';
                $key=($phase==='zip' && ($result['checkpoint']['phase']??'')==='publish')?'zip-finished':$phase;
                if ($crash && in_array($key,['read','rows','copy','zip-finished','publish'],true) && !isset($crashed[$key])) {
                    $crashed[$key]=true;
                    throw new RuntimeException('Simulated process loss before checkpoint commit');
                }
                return $result;
            });
        } catch (RuntimeException $error) {
            if ($error->getMessage()==='Simulated process loss before checkpoint commit') continue;
            throw $error;
        }
        $checkpoint = $result['checkpoint'];
        if ($interruption !== null) $interruption($checkpoint, $steps);
        if ($result['done']) return $result;
    }
    throw new RuntimeException('Job did not complete in bounded steps');
}

$fixture = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
if (!class_exists(CsvWriter::class)) throw new RuntimeException('CSV encoder implementation is missing');
$checks=0;
foreach ($fixture['records'] as $case) {
    same(base64_encode(CsvWriter::encodeRecord($case['values'])), $case['expected'], 'Python minimal quoting bytes'); $checks++;
}
foreach ($fixture['csv'] as $case) {
    $rows = (function() use ($case) {
        foreach ($case['rows'] as $row) yield array_map(fn($v) => is_array($v) ? str_repeat($v['repeat'], $v['count']) : $v, $row);
    })();
    try {
        $actual = CsvWriter::writeParts($rows, $case['headers'], $fixture['work'].'/'.$case['name'].'-php', $case['threshold']);
        $actual['sha256']=array_map(fn($p) => hash_file('sha256', $p), $actual['paths']); unset($actual['paths']);
    } catch (AppError $error) { $actual=['error'=>$error->errorCode]; }
    same(canonical($actual), canonical($case['expected']), 'CSV split '.$case['name']); $checks++;
}
$store = new Store($fixture['work'].'/data');
$import = new ImportService($store);
if (!method_exists(ImportService::class,'readHeaders')) throw new RuntimeException('Missing strict readHeaders API');
same(ImportService::readHeaders($fixture['work'].'/catalog.csv'),['code','name','ean','available','extra'],'Shared category CSV headers');
$firstRow=ImportService::readRows($fixture['work'].'/catalog.csv')->current();
same($firstRow['line'],2,'Shared reader physical start line'); same($firstRow['line_end']??null,3,'Shared reader physical end line'); $checks+=3;
same($firstRow['extra_columns']??null,false,'Shared reader marks no surplus columns'); $checks++;
foreach (ImportService::readRows($fixture['work'].'/catalog.csv') as $lastRow) {}
same($lastRow['malformed'],true,'Shared reader preserves missing-column diagnostic');
same($lastRow['extra_columns'],false,'Missing trailing cells are not surplus columns'); $checks+=2;
foreach ($fixture['inspections'] as $case) {
    $actual=$import->inspect($case['path'], $case['encoding'], $case['delimiter']);
    // Parser-specific text after invalid_csv differs; preserve all public diagnostic fields.
    foreach ($actual['diagnostics'] as &$d) if ($d['code']==='invalid_csv') $d['message']='invalid csv'; unset($d);
    foreach ($case['expected']['diagnostics'] as &$d) if ($d['code']==='invalid_csv') $d['message']='invalid csv'; unset($d);
    same(canonical($actual), canonical($case['expected']), 'Inspection '.basename($case['path']).' '.($case['encoding']??'default')); $checks++;
}
$malformedPath=$fixture['work'].'/many-malformed.csv';
$handle=fopen($malformedPath,'wb'); fwrite($handle,"code;name\n");
for ($i=0;$i<200_000;$i++) fwrite($handle,"001;A;extra\n"); fclose($handle);
$many=$import->inspect($malformedPath,'utf-8',';');
same(ImportService::readRows($malformedPath)->current()['extra_columns']??null,true,'Shared reader marks surplus columns'); $checks++;
same($many['record_count'],200_000,'Inspection still counts every malformed record');
same(count($many['diagnostics']),101,'Inspection warning detail stays bounded');
if (!str_contains($many['diagnostics'][100]['message'],'199900')) throw new RuntimeException('Inspection omitted warning count is missing');
$checks+=3;
foreach ($fixture['imports'] as $case) {
    $job=['id'=>$case['name'], 'payload'=>['profile'=>$case['profile'], 'sources'=>$case['sources']]];
    complete($import, $store, $job, null, true);
    $record=$store->get_import($job['id']);
    $actual=['products'=>$record['original_products'], 'diagnostics'=>$record['diagnostics'], 'source_counts'=>$record['source_counts']];
    same(canonical($actual), canonical($case['expected']), 'Import multiline, raw conflicts, orphans and packaging'); $checks++;
    complete($import,$store,$job);
    same($store->get_import($job['id']),$record,'Completed import retry is idempotent'); $checks++;
}
$resumeCase=$fixture['imports'][0]; $resumeEdited=false;
$resumeJob=['id'=>'import-after-manual-edit','payload'=>['profile'=>$resumeCase['profile'],'sources'=>$resumeCase['sources']]];
complete($import,$store,$resumeJob,function($cp,$step) use($store,$resumeCase,&$resumeEdited) {
    if (!$resumeEdited && ($cp['phase']??'')==='publish') {
        $p=$store->get_product($resumeCase['expected']['products'][0]['id']); $p['name']='Manuale ripresa'; $p['cost']='99.00';
        $store->save_product($p,['name','cost']); $resumeEdited=true;
    }
});
$manual=$store->get_product($resumeCase['expected']['products'][0]['id']);
same($manual['name'],'Manuale ripresa','Resume retains edit changed after finalized preparation');
same($manual['cost'],'99.00','Resume refreshes staged manual cost before publication');
same(canonical($store->get_import($resumeJob['id'])['original_products']),canonical($resumeCase['expected']['products']),'Restaging retains original supplier snapshot'); $checks+=3;

$original=$store->get_import('multiline_join_conflicts')['original_products'];
$good=$original[0]; $good['cost']='10'; $good['ean']='0001234567890'; $good['available']=false; $good['diagnostics']=[]; $store->save_product($good);
$second=$original[1]; $second['cost']='12'; $second['diagnostics']=[]; $store->save_product($second);
$profile=['destination'=>'prestashop', 'reference_prefix'=>'', 'tax_rule_id'=>'1'];
$store->put_record('validation', 'multiline_join_conflicts:prestashop', ['profile'=>$profile]);
$base=['compatibility_id'=>null, 'destination'=>'prestashop', 'category_ids'=>['12'], 'price'=>['final_gross'=>'19.90'],
    'quantity'=>1, 'description_html'=>'<p>È; "test"</p>', 'fields'=>['EAN13'=>'0001234567890', 'Brand'=>'Marca'],
    'exported'=>false, 'stale'=>false];
$draft=$base+['id'=>'good', 'product_id'=>$good['id'], 'reference'=>'00123', 'title'=>'Titolo valido', 'diagnostics'=>[], 'valid'=>true];
$bad=$base+['id'=>'bad', 'product_id'=>$second['id'], 'reference'=>'00002', 'title'=>'Titolo lungo', 'diagnostics'=>[['level'=>'error','code'=>'title_too_long','message'=>'Troppo lungo','hint'=>'Correggi titolo']], 'valid'=>false];
$store->save_drafts('multiline_join_conflicts', [$draft,$bad]);
$export=new ExportService($store, dirname(__DIR__,3).'/backend/src/motorworld_csv/resources');
if (!method_exists($export,'restart')) throw new RuntimeException('Export restart hook implementation is missing');
$job=['id'=>'first-export','payload'=>['import_id'=>'multiline_join_conflicts','destination'=>'prestashop','selection_mode'=>'selected','draft_ids'=>['good','bad','good']]];
$damaged=false;
$result=complete($export,$store,$job,function($cp,$step) use ($fixture,&$damaged) {
    if (!$damaged && isset($cp['body_offset']) && $cp['body_offset']>0) {
        file_put_contents($fixture['work'].'/data/work/first-export/records.spool','UNCONFIRMED',FILE_APPEND);
        file_put_contents($fixture['work'].'/data/work/first-export/artifacts/rapporto.csv','UNCONFIRMED',FILE_APPEND);
        $damaged=true;
    }
},true);
same($result['result']['included_count'],1,'Partial export includes one'); same($result['result']['excluded_count'],1,'Partial export excludes one');
$record=$store->get_export('first-export');
same($record['included_ids'],['good'],'Selection is deduplicated'); same($record['excluded_ids'],['bad'],'Invalid selected row excluded');
$path=$record['directory'].'/prodotti.csv'; $bytes=file_get_contents($path);
foreach ($fixture['export_expected'] as $name=>$sha) same(hash_file('sha256',$record['directory'].'/'.$name),$sha,'Python export service bytes '.$name);
if (str_contains($bytes,'UNCONFIRMED')) throw new RuntimeException('Retry failed to truncate unconfirmed CSV spool');
if (!str_contains(file_get_contents($record['directory'].'/rapporto.csv'),'title_too_long')) throw new RuntimeException('Partial export report lost diagnostics');
same(substr($bytes,0,3),"\xef\xbb\xbf",'Export includes UTF-8 BOM');
$checks+=5;
$bad['title']='Corretto'; $bad['valid']=true; $bad['diagnostics']=[];
$store->save_drafts('multiline_join_conflicts', [$draft,$bad]);
$job['id']='second-export'; $job['payload']['draft_ids']=['bad']; complete($export,$store,$job);
same(file_get_contents($path),$bytes,'Historical CSV remains immutable after correction');
$job['id']='first-export'; complete($export,$store,$job);
same(file_get_contents($path),$bytes,'Completed export retry is idempotent'); $checks+=2;
$zip=$record['directory'].'/esportazione.zip';
// Validate archive structure with independent Python zipfile in the differential runner.
$stressDescription=str_repeat('x',10_000)."é; \"test\"\n";
$insert=$store->pdo()->prepare('INSERT INTO drafts VALUES(?,?,?,?,?,?,?,?,?,?)');
$store->transaction(function() use($insert,$base,$good,$stressDescription) {
    for ($i=0;$i<2000;$i++) {
        $d=array_replace($base,['id'=>'stress-'.$i,'product_id'=>$good['id'],'reference'=>'stress-'.$i,'title'=>'Stress '.$i,
            'diagnostics'=>[],'valid'=>true,'description_html'=>$stressDescription]);
        $insert->execute(['multiline_join_conflicts',$d['id'],$d['product_id'],'prestashop',$d['reference'],$d['title'],1,0,0,json_encode($d,JSON_THROW_ON_ERROR)]);
    }
});
$stressJob=['id'=>'large-export','payload'=>['import_id'=>'multiline_join_conflicts','destination'=>'prestashop','selection_mode'=>'new_valid',
    'query'=>'Stress','category_id'=>'12','available'=>false]];
$stressDamaged=false;
$large=complete($export,$store,$stressJob,function($cp,$step) use ($fixture,&$stressDamaged) {
    if (!$stressDamaged && ($cp['phase']??'')==='rows' && $cp['body_offset']>0) {
        file_put_contents($fixture['work'].'/data/work/large-export/records.spool','UNCONFIRMED',FILE_APPEND);
        file_put_contents($fixture['work'].'/data/work/large-export/artifacts/rapporto.csv','UNCONFIRMED',FILE_APPEND);
        $stressDamaged=true;
    }
});
same($large['result']['included_count'],2000,'Large filtered selection');
$largeRecord=$store->get_export('large-export');
same(count(array_filter($largeRecord['artifacts'],fn($a)=>$a['kind']==='csv')),2,'Large worker export produces exactly two files');
same(array_sum(array_column(array_filter($largeRecord['artifacts'],fn($a)=>$a['kind']==='csv'),'rows')),2000,'Split worker preserves every record');
$csvArtifacts=array_values(array_filter($largeRecord['artifacts'],fn($a)=>$a['kind']==='csv'));
same(array_column($csvArtifacts,'size'),$fixture['stress_expected']['sizes'],'Large worker split byte sizes match Python');
same(array_column($csvArtifacts,'rows'),$fixture['stress_expected']['row_counts'],'Large worker balanced record boundary matches Python');
same(array_map(fn($a)=>hash_file('sha256',$largeRecord['directory'].'/'.$a['name']),$csvArtifacts),$fixture['stress_expected']['sha256'],'Large worker CSV bytes match Python after resumed append');
same($stressDamaged,true,'Retry test corrupts before another rows append');
file_put_contents($fixture['work'].'/zip-path.txt',json_encode([$zip,$largeRecord['directory'].'/esportazione.zip']));
$checks+=9;

// A failed job can be corrected and revalidated before the operator resumes it.
$resumeDraft=array_replace($draft,['id'=>'resume-draft','reference'=>'resume-ref','title'=>'Prima della correzione','description_html'=>'<p>Prima</p>']);
$store->save_drafts('multiline_join_conflicts',[$draft,$bad,$resumeDraft]);
$jobs=new JobService($store); $failSnapshot=true;
$jobs->register('export',function($job,$cp) use($export,&$failSnapshot) {
    if ($failSnapshot && ($cp['phase']??'')==='rows') throw new AppError('fixture_failure','Interruzione dopo la selezione.');
    return $export->step($job,$cp);
},[$export,'restart']);
$resume=$jobs->submit('export',['import_id'=>'multiline_join_conflicts','destination'=>'prestashop','selection_mode'=>'selected','draft_ids'=>['resume-draft']],'resume-current-data');
for ($i=0;$i<30;$i++) { $resume=$jobs->step($resume['id']); if ($resume['state']==='failed') break; }
same($resume['state'],'failed','Fixture fails after immutable selection is staged');
same($store->get_checkpoint($resume['id'])['phase'],'rows','Failed export keeps the selected checkpoint');
same($store->staged_count($resume['id'],'export_selection'),1,'Failed export has one staged snapshot');
$otherWork=$store->dataDir().'/work/other-job'; mkdir($otherWork,0700,true); file_put_contents($otherWork.'/keep.txt','OTHER');
$store->stage_record('other-job','guard','keep',['kept'=>true],0); $store->save_checkpoint('other-job',['kept'=>'checkpoint']);
$ownWork=$store->dataDir().'/work/'.$resume['id']; mkdir($ownWork.'/nested',0700,true); symlink($otherWork,$ownWork.'/nested/other-link');
$orphan=$store->dataDir().'/exports/'.$resume['id']; mkdir($orphan,0700,true); file_put_contents($orphan.'/prodotti.csv','OLD_ORPHAN');
symlink(dirname($path),$orphan.'/published-link');
$correctedProduct=$store->get_product($good['id']); $correctedProduct['name']='Anagrafica corretta'; $store->save_product($correctedProduct,['name']); $store->mark_stale($good['id']);
$resumeDraft=array_replace($resumeDraft,['title'=>'Titolo corretto dopo errore','description_html'=>'<p>Nuova descrizione</p>','price'=>['final_gross'=>'29.90'],'stale'=>false]);
$store->save_drafts('multiline_join_conflicts',[$draft,$bad,$resumeDraft]);
$newProfile=array_replace($profile,['tax_rule_id'=>'9']); $store->put_record('validation','multiline_join_conflicts:prestashop',['profile'=>$newProfile]);
$failSnapshot=false; $resumed=$jobs->resume($resume['id']);
same($resumed['state'],'queued','Failed export resumes with fresh preparation');
same($store->get_checkpoint($resume['id']),[],'Resume resets only the failed export checkpoint');
same($store->staged_count($resume['id'],'export_selection'),0,'Resume discards obsolete selected rows');
same(file_exists($ownWork),false,'Resume removes its obsolete spool directory');
same(file_exists($orphan),false,'Resume removes its unpublished orphan export directory');
same(file_get_contents($otherWork.'/keep.txt'),'OTHER','Restart never follows a work symlink to another job');
same($store->staged_count('other-job','guard'),1,'Restart preserves other job staging');
same($store->get_checkpoint('other-job'),['kept'=>'checkpoint'],'Restart preserves other job checkpoint');
same(file_get_contents($path),$bytes,'Restart never follows orphan symlink to published artifact');
for ($i=0;$i<100;$i++) { $resumed=$jobs->step($resume['id']); if (in_array($resumed['state'],['completed','failed'],true)) break; }
same($resumed['state'],'completed','Resumed export completes from current validation');
$fresh=$store->get_export($resume['id']); $freshBytes=file_get_contents($fresh['directory'].'/prodotti.csv');
same($fresh['snapshot']['rows'][0]['title'],'Titolo corretto dopo errore','Snapshot uses corrected draft title');
same($fresh['snapshot']['rows'][0]['price'],'29.90','Snapshot uses corrected price');
same($fresh['snapshot']['profile']['tax_rule_id'],'9','Snapshot uses current validated profile');
if (!str_contains($freshBytes,'Titolo corretto dopo errore') || !str_contains($freshBytes,'29.90') || str_contains($freshBytes,'Prima della correzione') || str_contains($freshBytes,'OLD_ORPHAN')) throw new RuntimeException('Resume published obsolete or orphan CSV bytes');
$publishedCheckpoint=$store->get_checkpoint($resume['id']);
$store->transaction(fn()=>$export->restart($resumed));
same(file_get_contents($fresh['directory'].'/prodotti.csv'),$freshBytes,'Published export restart preserves immutable bytes');
same($store->get_checkpoint($resume['id']),$publishedCheckpoint,'Published export restart preserves completed checkpoint');
same(file_get_contents($path),$bytes,'Prior history remains immutable after corrected resume');
$checks+=21;
echo "PHP CSV/import/export: $checks parity and integration checks passed\n";
