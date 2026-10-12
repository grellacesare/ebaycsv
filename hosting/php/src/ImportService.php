<?php
declare(strict_types=1);
namespace MotorWorld\Hosting;

final class ImportService
{
    private const BATCH=1000;
    private const ALIASES=[
        'code'=>['codiceprodotto','codice','code','sku','reference'],
        'name'=>['descrizioneita','descrizione','name','nome','title','titolo'],
        'brand'=>['brand','marca','manufacturer'],'ean'=>['ean','barcode','gtin'],
        'family'=>['categoriaita','categoria','category','famiglia'],
        'department'=>['compartoita','comparto','department'],
        'image_urls'=>['foto','imageurls','immagini','images','image'],
        'available'=>['disponibile','available'],'quantity'=>['quantitaconfezione','packquantity'],
        'gross_weight'=>['pesolordo','grossweight','weight'],'volume'=>['volume']];

    public function __construct(private Store $store) {}

    private static function strip(string $value): string { return preg_replace('/^\s+|\s+$/u','',$value); }

    private static function normalize(string $value): string
    {
        $value=mb_convert_case($value,MB_CASE_FOLD,'UTF-8');
        if (class_exists(\Normalizer::class)) $value=\Normalizer::normalize($value,\Normalizer::FORM_KD);
        else $value=iconv('UTF-8','ASCII//TRANSLIT',$value);
        return preg_replace('/[^a-z0-9]/','',$value);
    }

    private static function decode(string $bytes,string $encoding): string
    {
        $encoding=str_replace('_','-',$encoding);
        if (in_array(strtolower($encoding),['utf-8','utf-8-sig'],true)) {
            if (!mb_check_encoding($bytes,'UTF-8')) throw new AppError('encoding_required','La codifica del file deve essere scelta.');
            return $bytes;
        }
        try { $converted=@iconv($encoding,'UTF-8',$bytes); }
        catch (\Throwable) { $converted=false; }
        if ($converted===false) throw new AppError('encoding_required','La codifica del file deve essere scelta.');
        return $converted;
    }

    /** Strict Python-compatible parser, including lone CR and spaces before a quote. */
    private static function record($handle,string $delimiter,string $encoding): ?array
    {
        $start=ftell($handle);
        if ($start===0 && in_array(strtolower(str_replace('_','-',$encoding)),['utf-8','utf-8-sig'],true)) {
            $prefix=fread($handle,3);
            if ($prefix===CsvWriter::BOM) $start=3; else rewind($handle);
        }
        $buffer=''; $cursor=0; $consumed=0;
        $next=function() use($handle,&$buffer,&$cursor,&$consumed): ?string {
            if ($cursor===strlen($buffer)) {
                $buffer=fread($handle,8192); $cursor=0;
                if ($buffer===false) throw new AppError('invalid_csv','CSV non leggibile.');
                if ($buffer==='') return null;
            }
            $consumed++; return $buffer[$cursor++];
        };
        $values=[]; $field=''; $state='start'; $lines=0; $last=''; $nonempty=false; $ended=false;
        while (($char=$next())!==null) {
            if ($consumed>100_000_000) throw new AppError('invalid_csv','CSV non leggibile: campo troppo grande.');
            if ($char==="\r" || ($char==="\n" && $last!=="\r")) $lines++;
            $last=$char;
            if ($state==='quoted') {
                if ($char==='"') $state='after'; else $field.=$char;
                continue;
            }
            if ($state==='after' && $char==='"') { $field.='"'; $state='quoted'; continue; }
            if ($char===$delimiter) { $values[]=$field; $field=''; $state='start'; $nonempty=true; continue; }
            if ($char==="\r" || $char==="\n") {
                if ($char==="\r") {
                    $following=$next();
                    if ($following!==null && $following!=="\n") $consumed--;
                }
                $ended=true; break;
            }
            if ($state==='after') throw new AppError('invalid_csv','CSV non leggibile: delimitatore atteso dopo le virgolette.');
            $nonempty=true;
            if ($state==='start' && $char==='"') $state='quoted';
            else { $field.=$char; $state='plain'; }
        }
        if ($consumed===0) return null;
        if ($state==='quoted') throw new AppError('invalid_csv','CSV non leggibile: fine del file dentro un campo tra virgolette.');
        if (!$ended) $lines++;
        $values[]=$field; $end=$start+$consumed; fseek($handle,$end);
        return ['values'=>array_map(fn($v)=>self::decode($v,$encoding),$values),'lines'=>$lines,'offset'=>$end,'blank'=>!$nonempty];
    }

    private static function headers($handle,string $delimiter,string $encoding): array
    {
        $record=self::record($handle,$delimiter,$encoding);
        if ($record===null || $record['blank']) throw new AppError('duplicate_headers','Intestazioni vuote o duplicate.');
        $record['values'][0]=preg_replace('/^\x{FEFF}/u','',$record['values'][0]);
        $keys=array_map([self::class,'normalize'],$record['values']);
        if (count(array_unique($keys))!==count($keys)) throw new AppError('duplicate_headers','Intestazioni vuote o duplicate.');
        return $record;
    }

    private static function detectDelimiter(string $sample,string $encoding): ?string
    {
        $candidates=[];
        foreach ([',',"\t",';','|'] as $priority=>$candidate) {
            $handle=fopen('php://temp','w+b'); fwrite($handle,$sample); rewind($handle); $counts=[];
            try {
                while (($record=self::record($handle,$candidate,$encoding))!==null) if (!$record['blank']) $counts[]=count($record['values']);
            } catch (AppError) { /* A sample can end in the middle of its last quoted record. */ }
            finally { fclose($handle); }
            if (!$counts || $counts[0]<=1) continue;
            $matched=count(array_filter($counts,fn($count)=>$count===$counts[0]));
            $candidates[]=['delimiter'=>$candidate,'matched'=>$matched,'count'=>count($counts),'columns'=>$counts[0],'priority'=>$priority];
        }
        if ($candidates) {
            usort($candidates,fn($a,$b)=>($b['matched']*$a['count']<=>$a['matched']*$b['count']) ?: ($a['priority']<=>$b['priority']));
            return $candidates[0]['delimiter'];
        }
        return null;
    }

    public function inspect(string $path,?string $encoding=null,?string $delimiter=null): array
    {
        $view=['columns'=>[],'preview'=>[],'record_count'=>0,'encoding'=>$encoding??'utf-8','delimiter'=>null,'proposed_map'=>[],'diagnostics'=>[]];
        $handle=fopen($path,'rb');
        if ($handle===false) throw new AppError('invalid_file','File originale non disponibile.');
        try {
            $sample=fread($handle,8192);
            // read() in Python returns characters, so do not mistake a truncated UTF-8 sample for invalid input.
            if (in_array(strtolower(str_replace('_','-',$view['encoding'])),['utf-8','utf-8-sig'],true) && !mb_check_encoding($sample,'UTF-8')) {
                $prefix=$sample;
                for ($n=0;$n<3 && !mb_check_encoding($prefix,'UTF-8');$n++) $prefix=substr($prefix,0,-1);
                self::decode($prefix,$view['encoding']);
            } else self::decode($sample,$view['encoding']);
            if ($delimiter===null) {
                $delimiter=self::detectDelimiter($sample,$view['encoding']);
                if ($delimiter===null) {
                    $view['diagnostics'][]=Support::diagnostic('error','delimiter_required','Scegli il delimitatore del CSV.',
                        ['hint'=>'Controlla l’anteprima con ;, virgola, tab o |.']); return $view;
                }
            }
            if (strlen($delimiter)!==1) throw new AppError('invalid_delimiter','Il delimitatore deve avere un solo carattere.');
            $view['delimiter']=$delimiter; rewind($handle);
            $header=self::record($handle,$delimiter,$view['encoding']);
            if ($header===null || $header['blank']) throw new AppError('duplicate_headers','Intestazioni vuote o duplicate.');
            $header['values'][0]=preg_replace('/^\x{FEFF}/u','',$header['values'][0]); $view['columns']=$header['values'];
            $keys=array_map([self::class,'normalize'],$view['columns']);
            if (count(array_unique($keys))!==count($keys)) throw new AppError('duplicate_headers','Intestazioni vuote o duplicate.');
            $line=$header['lines']; $omittedMalformed=0;
            while (($record=self::record($handle,$delimiter,$view['encoding']))!==null) {
                $line+=$record['lines']; if ($record['blank']) continue;
                $view['record_count']++; $row=self::row($view['columns'],$record['values']);
                if (count($record['values'])!==count($view['columns'])) {
                    if (count($view['diagnostics'])<100) $view['diagnostics'][]=Support::diagnostic('warning','malformed_row',
                        'Numero colonne diverso dall’intestazione.',['source'=>['file'=>basename($path),'row'=>$line,'columns'=>[]],'hint'=>'Correggi delimitatori e virgolette.']);
                    else $omittedMalformed++;
                }
                if (count($view['preview'])<5) $view['preview'][]=$row;
            }
            if ($omittedMalformed) $view['diagnostics'][]=Support::diagnostic('warning','malformed_row',
                'Altre '.$omittedMalformed.' righe hanno un numero di colonne diverso dall’intestazione.',
                ['hint'=>'L’anteprima mostra i primi 100 avvisi. Il rapporto di importazione conserva tutte le righe da correggere.']);
            foreach (self::ALIASES as $field=>$aliases) {
                $matched=array_values(array_filter($view['columns'],fn($c)=>in_array(self::normalize($c),$aliases,true)));
                if (count($matched)===1) $view['proposed_map'][$field]=$matched[0];
            }
        } catch (AppError $error) {
            if ($error->errorCode==='invalid_delimiter') throw $error;
            $hints=['duplicate_headers'=>'Correggi la prima riga del file.','encoding_required'=>'Prova Windows-1252 o UTF-8; controlla gli accenti.','invalid_csv'=>'Correggi virgolette e delimitatori del file.'];
            if ($error->errorCode==='encoding_required') $view['encoding']=null;
            $view['diagnostics'][]=Support::diagnostic('error',$error->errorCode,$error->getMessage(),['hint'=>$hints[$error->errorCode]??'']);
        } finally { fclose($handle); }
        return $view;
    }

    private static function row(array $columns,array $values): array
    {
        $row=[]; foreach ($columns as $i=>$column) $row[$column]=$values[$i]??''; return $row;
    }

    /** Shared stream for category replacement; no user-controlled paths enter through API jobs. */
    public static function readHeaders(string $path,string $encoding='utf-8',string $delimiter=';'): array
    {
        if (strlen($delimiter)!==1) throw new AppError('invalid_delimiter','Il delimitatore deve avere un solo carattere.');
        $handle=fopen($path,'rb');
        if ($handle===false) throw new AppError('invalid_file','File originale non disponibile.');
        try { return self::headers($handle,$delimiter,$encoding)['values']; }
        finally { fclose($handle); }
    }

    public static function readRows(string $path,string $encoding='utf-8',string $delimiter=';'): \Generator
    {
        if (strlen($delimiter)!==1) throw new AppError('invalid_delimiter','Il delimitatore deve avere un solo carattere.');
        $handle=fopen($path,'rb');
        if ($handle===false) throw new AppError('invalid_file','File originale non disponibile.');
        try {
            $header=self::headers($handle,$delimiter,$encoding); $line=$header['lines'];
            while (($record=self::record($handle,$delimiter,$encoding))!==null) {
                $start=$line+1; $line+=$record['lines']; if ($record['blank']) continue;
                yield ['row'=>self::row($header['values'],$record['values']),'line'=>$start,'line_end'=>$line,
                    'malformed'=>count($header['values'])!==count($record['values']),
                    'extra_columns'=>count($record['values'])>count($header['values'])];
            }
        } finally { fclose($handle); }
    }

    private static function issue(string $code,string $message,?array $source=null,?string $field=null,?string $value=null,string $level='error',?string $productId=null): array
    {
        return Support::diagnostic($level,$code,$message,['hint'=>'Controlla il dato originale e correggi il campo o il profilo.',
            'source'=>$source,'field'=>$field,'value'=>$value,'product_id'=>$productId]);
    }

    private static function decimal(string $value,array $binding): string
    {
        $text=self::strip($value);
        if (!empty($binding['thousands_separator'])) $text=str_replace($binding['thousands_separator'],'',$text);
        if (($binding['decimal_separator']??'.')===',') {
            if (str_contains($text,'.')) throw new \InvalidArgumentException('Unconfirmed decimal separator');
            $text=str_replace(',','.',$text);
        }
        if (!preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)$/D',$text)) throw new \InvalidArgumentException('Invalid decimal');
        $negative=str_starts_with($text,'-'); $unsigned=ltrim($text,'+-');
        if ($negative && preg_match('/[1-9]/',$unsigned)) throw new \InvalidArgumentException('Negative decimal');
        [$whole,$fraction]=array_pad(explode('.',$unsigned,2),2,null); $whole=ltrim($whole,'0'); $whole=$whole===''?'0':$whole;
        return ($negative?'-':'').$whole.($fraction!==null && $fraction!==''?'.'.$fraction:'');
    }

    private static function productId(string $supplier,string $code): string
    {
        $hex=sha1(hex2bin('6ba7b8119dad11d180b400c04fd430c8').$supplier."\0".$code);
        $hex[12]='5'; $hex[16]=dechex((hexdec($hex[16])&3)|8);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20,12);
    }

    private function staged(string $job,string $kind,string $id): ?array
    {
        $query=$this->store->pdo()->prepare('SELECT payload FROM staging_records WHERE job_id=? AND kind=? AND id=?');
        $query->execute([$job,$kind,$id]); $value=$query->fetchColumn();
        return $value===false?null:json_decode($value,true,512,JSON_THROW_ON_ERROR);
    }

    private function addDiagnostic(string $job,array &$cp,array $diagnostic): void
    {
        $position=$cp['diagnostic_count']++; $this->store->stage_record($job,'import_diagnostic',(string)$position,$diagnostic,$position);
    }

    public function step(array $job,array $checkpoint): array
    {
        $id=$job['id'];
        $existing=$this->store->pdo()->prepare("SELECT json_extract(payload,'$.product_count') products,json_array_length(payload,'$.diagnostics') diagnostics FROM records WHERE kind='import_summary' AND id=?");
        $existing->execute([$id]); $completed=$existing->fetch(\PDO::FETCH_ASSOC);
        if ($completed) return ['done'=>true,'checkpoint'=>$checkpoint,'progress'=>['stage'=>'Importazione completata',
            'completed'=>(int)$completed['products'],'total'=>(int)$completed['products'],'unit'=>'prodotti'],
            'result'=>['import_id'=>$id,'product_count'=>(int)$completed['products'],'diagnostic_count'=>(int)$completed['diagnostics']]];
        $payload=$job['payload']; $profile=$payload['profile'];
        $bindings=[]; foreach ($profile['bindings'] as $binding) $bindings[$binding['role']]=$binding;
        $sources=$payload['sources']??array_map(function($upload) {
            $record=$this->store->get_record('upload',$upload['upload_id']);
            return ['id'=>$upload['upload_id'],'path'=>$this->store->dataDir().'/uploads/'.$upload['upload_id'].'.csv','role'=>$upload['role'],'name'=>$record['name']];
        },$payload['uploads']??[]);
        usort($sources,fn($a,$b)=>array_search($a['role'],['catalog','prices','packaging'],true)<=>array_search($b['role'],['catalog','prices','packaging'],true));
        if (!$sources || !array_filter($sources,fn($s)=>$s['role']==='catalog')) throw new AppError('catalog_required','Carica almeno un file di anagrafica.');
        foreach ($sources as $source) if (!isset($bindings[$source['role']])) throw new AppError('profile_mismatch','Il profilo non definisce tutti i ruoli caricati.');
        $cp=$checkpoint?:['phase'=>'read','source_index'=>0,'offset'=>0,'line'=>0,'columns'=>[],
            'source_counts'=>[],'product_count'=>0,'diagnostic_count'=>0,'finalized'=>0,'created_at'=>Support::now()];
        if ($cp['phase']==='read' && $cp['source_index']>=count($sources)) $cp['phase']='finalize';
        if ($cp['phase']==='read') {
            $source=$sources[$cp['source_index']]; $binding=$bindings[$source['role']];
            $handle=fopen($source['path'],'rb');
            if ($handle===false) throw new AppError('invalid_file','File originale non disponibile.');
            try {
                if ($cp['offset']===0) {
                    $header=self::headers($handle,$binding['delimiter'],$binding['encoding']??'utf-8');
                    $cp['columns']=$header['values']; $cp['line']=$header['lines']; $cp['offset']=$header['offset'];
                    $missing=array_diff(array_values($binding['column_map']),$cp['columns']);
                    if ($missing) { sort($missing); throw new AppError('profile_mismatch',($source['name']??basename($source['path'])).': colonne mancanti: '.implode(', ',$missing)); }
                    $cp['source_counts'][$source['id']]=0;
                } else fseek($handle,$cp['offset']);
                $start=$cp['offset']; $eof=false; $deadline=microtime(true)+1.5;
                for ($i=0;$i<self::BATCH && $cp['offset']-$start<1_048_576 && microtime(true)<$deadline;$i++) {
                    $previous=$cp['line']; $record=self::record($handle,$binding['delimiter'],$binding['encoding']??'utf-8');
                    if ($record===null) { $eof=true; break; }
                    $cp['line']+=$record['lines']; $cp['offset']=$record['offset']; if ($record['blank']) { $i--; continue; }
                    $cp['source_counts'][$source['id']]++;
                    $raw=self::row($cp['columns'],$record['values']);
                    $ref=['file'=>$source['name']??basename($source['path']),'row'=>$previous+1,'columns'=>$raw];
                    if (count($record['values'])!==count($cp['columns'])) { $this->addDiagnostic($id,$cp,self::issue('malformed_row','Riga con colonne mancanti o in eccesso.',$ref)); continue; }
                    $data=[]; foreach ($binding['column_map'] as $key=>$column) $data[$key]=self::strip($raw[$column]??'');
                    $code=$data['code']??'';
                    if ($code==='') { $this->addDiagnostic($id,$cp,self::issue('missing_code','Codice prodotto mancante.',$ref,'code')); continue; }
                    $productId=self::productId($profile['supplier_id'],$code);
                    $query=$this->store->pdo()->prepare('SELECT position,payload FROM staging_products WHERE job_id=? AND id=?');
                    $query->execute([$id,$productId]); $saved=$query->fetch(\PDO::FETCH_ASSOC);
                    if (!$saved && $source['role']!=='catalog') { $this->addDiagnostic($id,$cp,self::issue('orphan_'.$source['role'],'Codice senza anagrafica: non è creato un prodotto.',$ref,'code',$code,'warning')); continue; }
                    $position=$saved?(int)$saved['position']:$cp['product_count']++;
                    $p=$saved?json_decode($saved['payload'],true,512,JSON_THROW_ON_ERROR):['id'=>$productId,'supplier_id'=>$profile['supplier_id'],'code'=>$code,
                        'name'=>'','brand'=>'','family'=>'','department'=>'','cost'=>null,'ean'=>null,'image_urls'=>[],'available'=>null,
                        'packaging'=>[],'extra'=>[],'sources'=>[],'diagnostics'=>[]];
                    $p['sources'][]=$ref; $signature=$ref['columns']; ksort($signature);
                    $duplicateId=hash('sha256',json_encode([$source['role'],$code,$signature],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
                    if ($this->staged($id,'import_duplicates',$duplicateId)!==null) {
                        $p['diagnostics'][]=self::issue('identical_duplicate','Riga identica accorpata.',$ref,null,null,'info',$p['id']);
                        $this->store->stage_product($id,$p,$position); continue;
                    }
                    $this->store->stage_record($id,'import_duplicates',$duplicateId,['seen'=>true],0);
                    if ($source['role']==='packaging') {
                        $pack=['quantity'=>null,'gross_weight'=>null,'volume'=>null,'weight_unit'=>$binding['weight_unit']??null,'source'=>$ref];
                        foreach (['quantity','gross_weight','volume'] as $field) if (($data[$field]??'')!=='') {
                            try { $pack[$field]=self::decimal($data[$field],$binding); }
                            catch (\InvalidArgumentException) { $p['diagnostics'][]=self::issue('invalid_'.$field,'Dato confezione non interpretabile.',$ref,$field,$data[$field],'warning',$p['id']); }
                        }
                        $p['packaging'][]=$pack; $this->store->stage_product($id,$p,$position); continue;
                    }
                    foreach (['name','brand','family','department','ean','cost','image_urls','available'] as $field) {
                        $value=$data[$field]??''; if ($value==='') continue;
                        $key=hash('sha256',$code."\0".$field); $prior=$this->staged($id,'import_seen_values',$key);
                        $conflict=$prior!==null && (($prior['conflict']??false) || $prior['first']!==$value);
                        $this->store->stage_record($id,'import_seen_values',$key,['first'=>$prior['first']??$value,'conflict'=>$conflict],0);
                        if ($conflict) {
                            $p['diagnostics'][]=self::issue('conflicting_'.$field,'Valori discordanti per lo stesso codice.',$ref,$field,$value,'error',$p['id']);
                            if (in_array($field,['ean','cost','available'],true)) $p[$field]=null;
                            continue;
                        }
                        if ($field==='cost') {
                            try { $p['cost']=self::decimal($value,$binding); }
                            catch (\InvalidArgumentException) { $p['diagnostics'][]=self::issue('invalid_cost','Costo IVA esclusa non interpretabile.',$ref,$field,$value,'error',$p['id']); }
                        } elseif ($field==='image_urls') $p[$field]=array_values(array_filter(array_map([self::class,'strip'],preg_split('/[\n|]/',$value)),fn($v)=>$v!==''));
                        elseif ($field==='available') {
                            $normalized=mb_strtolower($value,'UTF-8');
                            if (!in_array($normalized,['0','1','true','false','si','sì','no'],true)) $p['diagnostics'][]=self::issue('invalid_available','Disponibilità non riconosciuta come indicatore.',$ref,$field,$value,'warning',$p['id']);
                            else $p[$field]=in_array($normalized,['1','true','si','sì'],true);
                        } else $p[$field]=$value;
                    }
                    foreach ($ref['columns'] as $key=>$value) if (!in_array($key,array_values($binding['column_map']),true) && !array_key_exists($key,$p['extra'])) $p['extra'][$key]=$value;
                    $this->store->stage_product($id,$p,$position);
                }
                if ($eof) { $cp['source_index']++; $cp['offset']=0; $cp['line']=0; $cp['columns']=[]; }
            } catch (AppError $error) {
                if (in_array($error->errorCode,['duplicate_headers','encoding_required','invalid_csv'],true)) throw new AppError('invalid_file',($source['name']??basename($source['path'])).': '.$error->getMessage());
                throw $error;
            } finally { fclose($handle); }
            return ['done'=>false,'checkpoint'=>$cp,'progress'=>['stage'=>['catalog'=>'Lettura catalogo','prices'=>'Collegamento prezzi','packaging'=>'Collegamento confezioni'][$source['role']],
                'completed'=>$cp['source_counts'][$source['id']]??0,'total'=>$cp['source_counts'][$source['id']]??0,'unit'=>'righe']];
        }
        if ($cp['phase']==='finalize') {
            $query=$this->store->pdo()->prepare('SELECT id,position,COALESCE(source_payload,payload) payload FROM staging_products WHERE job_id=? ORDER BY position LIMIT ? OFFSET ?');
            $query->bindValue(1,$id); $query->bindValue(2,self::BATCH,\PDO::PARAM_INT); $query->bindValue(3,$cp['finalized'],\PDO::PARAM_INT); $query->execute();
            $count=0; $bytes=0; $deadline=microtime(true)+1.5;
            while ($count<self::BATCH && $bytes<1_048_576 && microtime(true)<$deadline && ($row=$query->fetch(\PDO::FETCH_ASSOC))!==false) {
                $count++; $bytes+=strlen($row['payload']);
                $p=json_decode($row['payload'],true,512,JSON_THROW_ON_ERROR); $codes=array_column($p['diagnostics'],'code');
                if ($p['cost']===null && !array_intersect($codes,['invalid_cost','conflicting_cost','missing_cost'])) $p['diagnostics'][]=self::issue('missing_cost','Costo d’acquisto IVA esclusa mancante.',null,'cost',null,'error',$p['id']);
                if (!$p['packaging'] && !in_array('missing_packaging',$codes,true) && array_filter($sources,fn($s)=>$s['role']==='packaging')) $p['diagnostics'][]=self::issue('missing_packaging','Dati peso/confezione mancanti.',null,null,null,'warning',$p['id']);
                $extraQuantity=array_filter(array_keys($p['extra']),fn($k)=>str_contains(strtolower($k),'qta') || str_contains(strtolower($k),'quantita'));
                if ($extraQuantity && !in_array('unconfirmed_packaging_basis',$codes,true) && array_filter($bindings,fn($b)=>$b['role']!=='packaging' && !($b['packaging_basis_confirmed']??false)))
                    $p['diagnostics'][]=self::issue('unconfirmed_packaging_basis','Verifica minimi di vendita e base del costo: non sono quantità di stock.',null,null,null,'warning',$p['id']);
                $this->store->stage_product($id,$this->store->prepare_import_product($p),(int)$row['position'],$p); $cp['finalized']++;
            }
            if ($cp['finalized']>=$cp['product_count']) $cp['phase']='publish';
            return ['done'=>false,'checkpoint'=>$cp,'progress'=>['stage'=>'Completamento importazione','completed'=>$cp['finalized'],'total'=>$cp['product_count'],'unit'=>'prodotti']];
        }
        if (!$this->store->import_preparation_is_current($id)) {
            $cp['phase']='finalize'; $cp['finalized']=0;
            return ['done'=>false,'checkpoint'=>$cp,'progress'=>['stage'=>'Aggiornamento correzioni manuali','completed'=>0,'total'=>$cp['product_count'],'unit'=>'prodotti']];
        }
        $this->store->publish_import($id,$id,['created_at'=>$cp['created_at'],'source_counts'=>$cp['source_counts'],'diagnostics'=>null]);
        return ['done'=>true,'checkpoint'=>$cp,'progress'=>['stage'=>'Importazione completata','completed'=>$cp['product_count'],'total'=>$cp['product_count'],'unit'=>'prodotti'],
            'result'=>['import_id'=>$id,'product_count'=>$cp['product_count'],'diagnostic_count'=>$cp['diagnostic_count']]];
    }
}
