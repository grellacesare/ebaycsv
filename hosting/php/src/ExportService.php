<?php
declare(strict_types=1);
namespace MotorWorld\Hosting;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class ExportService
{
    private const BATCH=100;
    public const REPORT_COLUMNS=['Riferimento','Codice prodotto','Livello','Errore','Messaggio','File','Riga','Campo','Valore','Correzione'];
    public const PS_HEADERS=['Active (0/1)','Name','Categories (x,y,z...)','Price tax included','Tax rule ID','Cost price',
        'Reference #','Supplier reference #','Supplier','Brand','Quantity','EAN13','Summary','Description','Image URLs (x,y,z...)'];

    public function __construct(private Store $store,private string $resources) {}

    /** Failed/interrupted exports restart against current validation; published history is final. */
    public function restart(array $job): void
    {
        $this->store->transaction(function() use($job): void {
            $id=$job['id']??null;
            if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$id)) throw new AppError('invalid_job','Lavoro non valido.');
            $query=$this->store->pdo()->prepare("SELECT 1 FROM records WHERE kind='export' AND id=?");
            $query->execute([$id]);
            if ($query->fetchColumn()!==false) return;
            $roots=[$this->store->dataDir().'/work',$this->store->dataDir().'/exports'];
            foreach ($roots as $root) {
                if (is_link($root) || (file_exists($root) && (!is_dir($root) || realpath($root)!==$root)))
                    throw new AppError('storage_unavailable','Impossibile preparare la ripresa dell’esportazione.',503);
            }
            $this->store->clear_staging($id); $this->store->save_checkpoint($id,[]);
            foreach ($roots as $root) self::removePending($root.'/'.$id);
        });
    }

    private static function removePending(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) return;
        if (is_link($path) || !is_dir($path)) {
            if (!@unlink($path)) throw new AppError('storage_unavailable','Impossibile preparare la ripresa dell’esportazione.',503);
            return;
        }
        foreach (new \FilesystemIterator($path,\FilesystemIterator::SKIP_DOTS) as $entry) self::removePending($entry->getPathname());
        if (!@rmdir($path)) throw new AppError('storage_unavailable','Impossibile preparare la ripresa dell’esportazione.',503);
    }

    public function columns(string $destination): array
    {
        if ($destination==='prestashop') return self::PS_HEADERS;
        if ($destination!=='ebay') throw new AppError('wrong_destination','Destinazione non valida.');
        return json_decode(file_get_contents($this->resources.'/ebay_headers.json'),true,512,JSON_THROW_ON_ERROR);
    }

    public static function draftToRow(array $draft,array $headers): array
    {
        if (empty($draft['price'])) throw new AppError('missing_cost','Prezzo non calcolato.');
        $fields=$draft['fields'];
        $price=(string)BigDecimal::of($draft['price']['final_gross'])->toScale(2,RoundingMode::HALF_EVEN);
        if ($draft['destination']==='ebay') $fields=array_replace($fields,['Custom label (SKU)'=>$draft['reference'],'Category Id'=>$draft['category_ids'][0],
            'Title'=>$draft['title'],'Start Price'=>$price,'Quantity'=>(string)$draft['quantity'],'Description'=>$draft['description_html']]);
        else $fields=array_replace($fields,['Name'=>$draft['title'],'Reference #'=>$draft['reference'],'Categories (x,y,z...)'=>implode(',',$draft['category_ids']),
            'Price tax included'=>$price,'Quantity'=>(string)$draft['quantity'],'Description'=>$draft['description_html']]);
        $row=[]; foreach ($headers as $header) $row[$header]=$fields[$header]??''; return $row;
    }

    private static function reportRow(array $d,string $reference='',string $code=''): array
    {
        return [$reference,$code,$d['level'],$d['code'],$d['message'],$d['source']['file']??'',$d['source']['row']??'',
            $d['field']??'',$d['value']??'',$d['hint']??''];
    }

    private static function appendHandle(string $path,int $offset)
    {
        $handle=fopen($path,'c+b');
        if ($handle===false || !ftruncate($handle,$offset) || fseek($handle,$offset)!==0)
            throw new AppError('storage_error','Impossibile riprendere il file di esportazione.',500);
        return $handle;
    }

    private function records(string $job,string $kind,int $offset,int $limit=self::BATCH): \Generator
    {
        $query=$this->store->pdo()->prepare('SELECT payload FROM staging_records WHERE job_id=? AND kind=? ORDER BY position,id LIMIT ? OFFSET ?');
        $query->bindValue(1,$job); $query->bindValue(2,$kind); $query->bindValue(3,$limit,\PDO::PARAM_INT); $query->bindValue(4,$offset,\PDO::PARAM_INT); $query->execute();
        while (($json=$query->fetchColumn())!==false) yield json_decode($json,true,512,JSON_THROW_ON_ERROR);
    }

    private function selection(string $job,array $draft,int $position,string $destination): bool
    {
        if ($draft['destination']!==$destination) throw new AppError('wrong_destination','La selezione contiene righe di un’altra destinazione.');
        $query=$this->store->pdo()->prepare("SELECT 1 FROM staging_records WHERE job_id=? AND kind='export_selection' AND id=?");
        $query->execute([$job,$draft['id']]); if ($query->fetchColumn()!==false) return false;
        $query=$this->store->pdo()->prepare('SELECT code FROM products WHERE id=?'); $query->execute([$draft['product_id']]); $code=$query->fetchColumn();
        if ($code===false) throw new NotFound($draft['product_id']);
        $this->store->stage_record($job,'export_selection',$draft['id'],['draft'=>$draft,'code'=>$code],$position); return true;
    }

    public function step(array $job,array $checkpoint): array
    {
        $id=$job['id']; $payload=$job['payload']; $importId=$payload['import_id']; $destination=$payload['destination'];
        // An interrupted response after publication must return the original completed result.
        $query=$this->store->pdo()->prepare("SELECT json_array_length(payload,'$.included_ids') included,json_array_length(payload,'$.excluded_ids') excluded FROM records WHERE kind='export' AND id=?");
        $query->execute([$id]); $existing=$query->fetch(\PDO::FETCH_ASSOC);
        if ($existing) return ['done'=>true,'checkpoint'=>$checkpoint,'progress'=>['stage'=>'Esportazione completata','completed'=>(int)$existing['included'],'total'=>(int)$existing['included'],'unit'=>'righe'],
            'result'=>['export_id'=>$id,'included_count'=>(int)$existing['included'],'excluded_count'=>(int)$existing['excluded']]];
        $work=$this->store->dataDir().'/work/'.$id; $artifacts=$work.'/artifacts'; $bodyPath=$work.'/records.spool'; $reportPath=$artifacts.'/rapporto.csv';
        $headers=$this->columns($destination); $header=CsvWriter::header($headers);
        $cp=$checkpoint;
        if (!$cp) {
            try { $settings=$this->store->get_record('validation',$importId.':'.$destination); }
            catch (NotFound) { throw new AppError('validation_required','Controlla prima il lotto con le impostazioni attuali.'); }
            if (!is_dir($artifacts) && !mkdir($artifacts,0700,true) && !is_dir($artifacts)) throw new AppError('storage_error','Impossibile creare i file di esportazione.',500);
            $handle=fopen($bodyPath,'wb'); fclose($handle); $handle=fopen($reportPath,'wb');
            try { CsvWriter::writeAll($handle,CsvWriter::header(self::REPORT_COLUMNS)); $reportOffset=ftell($handle); } finally { fclose($handle); }
            $query=$this->store->pdo()->prepare("SELECT SUM(json_extract(j.value,'$.level')='error') errors,SUM(json_extract(j.value,'$.level')='warning') warnings FROM records r,json_each(r.payload,'$.diagnostics') j WHERE r.kind='import_summary' AND r.id=?");
            $query->execute([$importId]); $counts=$query->fetch(\PDO::FETCH_ASSOC);
            $cp=['phase'=>'sources','source_offset'=>0,'select_offset'=>0,'selected_count'=>0,'rows_offset'=>0,'body_offset'=>0,'report_offset'=>$reportOffset,
                'row_count'=>0,'included_count'=>0,'excluded_count'=>0,'profile'=>$settings['profile'],'created_at'=>Support::now(),
                'import_error_count'=>(int)($counts['errors']??0),'import_warning_count'=>(int)($counts['warnings']??0),'diagnostics'=>[]];
            return $this->pending($cp,'Preparazione esportazione',0,0);
        }
        if ($cp['phase']==='sources') {
            $query=$this->store->pdo()->prepare("SELECT j.value FROM records r,json_each(r.payload,'$.diagnostics') j WHERE r.kind='import_summary' AND r.id=? ORDER BY CAST(j.key AS INTEGER) LIMIT ? OFFSET ?");
            $query->bindValue(1,$importId); $query->bindValue(2,self::BATCH,\PDO::PARAM_INT); $query->bindValue(3,$cp['source_offset'],\PDO::PARAM_INT); $query->execute();
            $handle=self::appendHandle($reportPath,$cp['report_offset']); $count=0;
            try {
                while (($json=$query->fetchColumn())!==false) {
                    $diagnostic=json_decode($json,true,512,JSON_THROW_ON_ERROR);
                    CsvWriter::writeAll($handle,CsvWriter::encodeRecord(self::reportRow($diagnostic,'',($diagnostic['field']??null)==='code'?($diagnostic['value']??''):'')));
                    $cp['source_offset']++; $count++;
                }
                $cp['report_offset']=ftell($handle);
            } finally { fclose($handle); }
            if ($count<self::BATCH) $cp['phase']='select';
            return $this->pending($cp,'Preparazione rapporto',$cp['source_offset'],$cp['source_offset']);
        }
        if ($cp['phase']==='select') {
            if (($payload['selection_mode']??'new_valid')==='selected') {
                $ids=$payload['draft_ids']??[]; $scannedBytes=0;
                for ($i=0;$i<self::BATCH && $cp['select_offset']<count($ids) && $scannedBytes<1_048_576;$i++) {
                    $draft=$this->store->get_draft($ids[$cp['select_offset']++],$importId);
                    $scannedBytes+=strlen($draft['description_html']??'');
                    if ($this->selection($id,$draft,$cp['selected_count'],$destination)) $cp['selected_count']++;
                }
                $finished=$cp['select_offset']>=count($ids); $total=count($ids);
            } else {
                $scannedBytes=0; $deadline=microtime(true)+1.5;
                $total=$this->store->count_filtered_drafts($importId,$destination,$payload['state']??null,$payload['query']??'',$payload['category_id']??null,$payload['available']??null);
                $drafts=$this->store->iter_filtered_drafts($importId,$destination,$payload['state']??null,$cp['select_offset'],self::BATCH,
                    $payload['query']??'',$payload['category_id']??null,$payload['available']??null);
                foreach ($drafts as $draft) {
                    $cp['select_offset']++; $scannedBytes+=strlen($draft['description_html']??'');
                    if (!$draft['exported'] && $this->selection($id,$draft,$cp['selected_count'],$destination)) $cp['selected_count']++;
                    if ($scannedBytes>=1_048_576 || microtime(true)>=$deadline) break;
                }
                $finished=$cp['select_offset']>=$total;
            }
            if ($finished) $cp['phase']='rows';
            return $this->pending($cp,'Preparazione selezione',$cp['select_offset'],$total);
        }
        if ($cp['phase']==='rows') {
            $rows=$this->records($id,'export_selection',$cp['rows_offset']);
            $body=self::appendHandle($bodyPath,$cp['body_offset']); $report=self::appendHandle($reportPath,$cp['report_offset']);
            try {
                foreach ($rows as $selection) {
                    $draft=$selection['draft']; $position=$cp['rows_offset']++;
                    foreach ($draft['diagnostics'] as $d) CsvWriter::writeAll($report,CsvWriter::encodeRecord(self::reportRow($d,$draft['reference'],$selection['code'])));
                    if (!$draft['valid'] || $draft['stale']) {
                        $this->store->stage_record($id,'export_excluded',$draft['id'],['id'=>$draft['id']],$position); $cp['excluded_count']++;
                        if ($draft['stale']) CsvWriter::writeAll($report,CsvWriter::encodeRecord(self::reportRow(Support::diagnostic('error','stale_draft','Dati modificati: ricontrolla il lotto.'),$draft['reference'],$selection['code'])));
                    } else {
                        if ($draft['exported']) CsvWriter::writeAll($report,CsvWriter::encodeRecord(self::reportRow(Support::diagnostic('warning','previously_exported',
                            'Riga già inclusa in un precedente file: non importare due volte lo stesso prodotto.'),$draft['reference'],$selection['code'])));
                        $row=self::draftToRow($draft,$headers); $bytes=CsvWriter::encodeRecord(array_values($row)); CsvWriter::writeAll($body,$bytes);
                        $this->store->stage_record($id,'export_included',$draft['id'],['id'=>$draft['id']],$position);
                        $this->store->stage_record($id,'export_summary',$draft['id'],['id'=>$draft['id'],'product_id'=>$draft['product_id'],'reference'=>$draft['reference'],
                            'title'=>$draft['title'],'price'=>$draft['price']['final_gross'],'category_ids'=>$draft['category_ids']],$position);
                        $this->store->stage_record($id,'export_length',(string)$cp['row_count'],['bytes'=>strlen($bytes)],$cp['row_count']);
                        $cp['row_count']++; $cp['included_count']++;
                    }
                    if (ftell($body)-$cp['body_offset']>=1_048_576) break;
                }
                $cp['body_offset']=ftell($body); $cp['report_offset']=ftell($report);
            } finally { fclose($body); fclose($report); }
            if ($cp['rows_offset']>=$cp['selected_count']) {
                if (!$cp['row_count']) throw new AppError('no_valid_rows','Nessuna riga valida selezionata.');
                if ($cp['body_offset']+strlen($header)<=CsvWriter::LIMIT) {
                    $cp['groups']=[['bytes'=>$cp['body_offset'],'rows'=>$cp['row_count']]]; $cp['phase']='parts';
                } else {
                    if ($cp['row_count']===1) throw new AppError('unsplittable_record','Un solo record supera 15 MB: riduci la descrizione prima di esportare.');
                    $cp['phase']='split'; $cp['split_offset']=0; $cp['partial']=0; $cp['best']=null;
                }
            }
            return $this->pending($cp,'Generazione CSV',$cp['rows_offset'],$cp['selected_count']);
        }
        if ($cp['phase']==='split') {
            $lengths=$this->records($id,'export_length',$cp['split_offset']);
            foreach ($lengths as $length) {
                if ($cp['split_offset']>=$cp['row_count']-1) break;
                $cp['partial']+=$length['bytes']; $cp['split_offset']++; $distance=abs($cp['body_offset']-2*$cp['partial']);
                if ($cp['best']===null || $distance<$cp['best']['distance']) $cp['best']=['distance'=>$distance,'index'=>$cp['split_offset'],'bytes'=>$cp['partial']];
            }
            if ($cp['split_offset']>=$cp['row_count']-1) {
                $best=$cp['best']; $cp['groups']=[['bytes'=>$best['bytes'],'rows'=>$best['index']],['bytes'=>$cp['body_offset']-$best['bytes'],'rows'=>$cp['row_count']-$best['index']]];
                $cp['phase']='parts';
            }
            return $this->pending($cp,'Suddivisione CSV',$cp['split_offset'],$cp['row_count']-1);
        }
        if ($cp['phase']==='parts') {
            $over=false; foreach ($cp['groups'] as $group) if ($group['bytes']+strlen($header)>CsvWriter::LIMIT) $over=true;
            $report=self::appendHandle($reportPath,$cp['report_offset']);
            try {
                if ($over) {
                    $d=CsvWriter::limitDiagnostic(); $cp['diagnostics'][]=$d; CsvWriter::writeAll($report,CsvWriter::encodeRecord(self::reportRow($d)));
                }
                $cp['report_offset']=ftell($report);
            } finally { fclose($report); }
            $cp['phase']='copy'; $cp['part_index']=0; $cp['part_offset']=0; $cp['source_byte_offset']=0;
            return $this->pending($cp,'Preparazione file CSV',0,count($cp['groups']));
        }
        if ($cp['phase']==='copy') {
            $name=count($cp['groups'])===1?'prodotti.csv':'prodotti_parte_'.($cp['part_index']+1).'.csv';
            $path=$artifacts.'/'.$name; $pending=$path.'.tmp';
            if (!is_file($pending) && is_file($path)) rename($path,$pending);
            $dest=self::appendHandle($pending,$cp['part_offset']);
            $source=fopen($bodyPath,'rb');
            try {
                if ($cp['part_offset']===0) { CsvWriter::writeAll($dest,$header); $cp['part_offset']=strlen($header); }
                fseek($source,$cp['source_byte_offset']);
                $remaining=min(1_048_576,$cp['groups'][$cp['part_index']]['bytes']-($cp['part_offset']-strlen($header)));
                while ($remaining>0) {
                    $chunk=fread($source,min(65_536,$remaining));
                    if ($chunk===false || $chunk==='') throw new AppError('storage_error','File CSV incompleto.',500);
                    CsvWriter::writeAll($dest,$chunk); $size=strlen($chunk); $cp['part_offset']+=$size; $cp['source_byte_offset']+=$size; $remaining-=$size;
                }
            } finally { fclose($source); fclose($dest); }
            if ($cp['part_offset']===strlen($header)+$cp['groups'][$cp['part_index']]['bytes']) {
                if (!rename($pending,$path)) throw new AppError('storage_error','Impossibile completare il file CSV.',500);
                $cp['part_index']++; $cp['part_offset']=0;
                if ($cp['part_index']===count($cp['groups'])) { $cp['phase']='zip'; $cp['zip']=[]; }
            }
            return $this->pending($cp,'Scrittura file CSV',$cp['source_byte_offset'],$cp['body_offset'],'byte');
        }
        if ($cp['phase']==='zip') {
            $paths=[];
            foreach ($cp['groups'] as $i=>$group) $paths[]=$artifacts.'/'.(count($cp['groups'])===1?'prodotti.csv':'prodotti_parte_'.($i+1).'.csv');
            $paths[]=$reportPath;
            if (!is_file($artifacts.'/esportazione.zip.tmp') && is_file($artifacts.'/esportazione.zip')) rename($artifacts.'/esportazione.zip',$artifacts.'/esportazione.zip.tmp');
            $result=ZipWriter::step($paths,$artifacts.'/esportazione.zip.tmp',$cp['zip']); $cp['zip']=$result['checkpoint'];
            if ($result['done']) {
                if (!rename($artifacts.'/esportazione.zip.tmp',$artifacts.'/esportazione.zip')) throw new AppError('storage_error','Impossibile completare lo ZIP.',500);
                $cp['phase']='publish';
            }
            return $this->pending($cp,'Creazione archivio ZIP',$cp['zip']['offset'],$cp['body_offset']+$cp['report_offset'],'byte');
        }
        $directory=$this->store->dataDir().'/exports/'.$id;
        if (!is_dir(dirname($directory))) mkdir(dirname($directory),0700,true);
        if (!is_dir($directory) && !rename($artifacts,$directory)) throw new AppError('storage_error','Impossibile pubblicare l’esportazione.',500);
        $files=[];
        foreach ($cp['groups'] as $i=>$group) {
            $name=count($cp['groups'])===1?'prodotti.csv':'prodotti_parte_'.($i+1).'.csv';
            $files[]=['name'=>$name,'size'=>$group['bytes']+strlen($header),'rows'=>$group['rows'],'kind'=>'csv'];
        }
        $files[]=['name'=>'rapporto.csv','size'=>filesize($directory.'/rapporto.csv'),'rows'=>null,'kind'=>'report'];
        $files[]=['name'=>'esportazione.zip','size'=>filesize($directory.'/esportazione.zip'),'rows'=>null,'kind'=>'zip'];
        $this->store->publish_export($id,$id,['import_id'=>$importId,'destination'=>$destination,'snapshot'=>['profile'=>$cp['profile'],
            'import_error_count'=>$cp['import_error_count'],'import_warning_count'=>$cp['import_warning_count']],
            'artifacts'=>$files,'diagnostics'=>$cp['diagnostics'],'directory'=>$directory,'created_at'=>$cp['created_at']]);
        return ['done'=>true,'checkpoint'=>$cp,'progress'=>['stage'=>'Esportazione completata','completed'=>$cp['selected_count'],'total'=>$cp['selected_count'],'unit'=>'righe'],
            'result'=>['export_id'=>$id,'included_count'=>$cp['included_count'],'excluded_count'=>$cp['excluded_count']]];
    }

    private function pending(array $cp,string $stage,int $completed,int $total,string $unit='righe'): array
    {
        return ['done'=>false,'checkpoint'=>$cp,'progress'=>compact('stage','completed','total','unit')];
    }
}
