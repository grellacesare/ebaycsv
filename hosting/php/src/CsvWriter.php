<?php
declare(strict_types=1);
namespace MotorWorld\Hosting;

final class CsvWriter
{
    public const LIMIT = 15_000_000;
    public const BOM = "\xef\xbb\xbf";

    /** Python csv.writer QUOTE_MINIMAL: spaces and tabs do not force quoting. */
    public static function encodeRecord(array $values): string
    {
        $fields=[];
        foreach ($values as $value) {
            $value=$value===null ? '' : (is_bool($value) ? ($value?'True':'False') : (string)$value);
            if (strpbrk($value,";\"\r\n")!==false || (count($values)===1 && $value===''))
                $value='"'.str_replace('"','""',$value).'"';
            $fields[]=$value;
        }
        return implode(';',$fields)."\r\n";
    }

    public static function header(array $headers): string { return self::BOM.self::encodeRecord($headers); }

    public static function limitDiagnostic(): array
    {
        return Support::diagnostic('warning','parts_over_limit','Le due parti superano ancora la soglia di 15 MB.',
            ['hint'=>'Seleziona meno righe. Non sono create ulteriori parti.']);
    }

    /** Standalone streaming writer; workers use the same encoder with persisted spool offsets. */
    public static function writeParts(iterable $rows,array $headers,string $outputDir,int $threshold=self::LIMIT): array
    {
        if (!is_dir($outputDir) && !mkdir($outputDir,0700,true) && !is_dir($outputDir))
            throw new AppError('storage_error','Impossibile creare i file di esportazione.',500);
        $bodyPath=tempnam($outputDir,'.records-'); $lengthPath=tempnam($outputDir,'.lengths-');
        $body=fopen($bodyPath,'w+b'); $lengths=fopen($lengthPath,'w+b');
        $total=0; $count=0;
        try {
            foreach ($rows as $row) {
                $bytes=self::encodeRecord(array_map(fn($h)=>$row[$h]??'',$headers));
                self::writeAll($body,$bytes); self::writeAll($lengths,(string)strlen($bytes)."\n");
                $total+=strlen($bytes); $count++;
            }
            if (!$count) throw new AppError('no_valid_rows','Nessuna riga valida selezionata.');
            $header=self::header($headers);
            if ($total+strlen($header)<=$threshold) $groups=[[$total,$count]];
            else {
                if ($count===1) throw new AppError('unsplittable_record','Un solo record supera 15 MB: riduci la descrizione prima di esportare.');
                rewind($lengths); $partial=0; $best=null;
                for ($i=1;$i<$count;$i++) {
                    $partial+=(int)fgets($lengths); $distance=abs($total-2*$partial);
                    if ($best===null || $distance<$best[0]) $best=[$distance,$i,$partial];
                }
                $groups=[[$best[2],$best[1]],[$total-$best[2],$count-$best[1]]];
            }
            rewind($body); $paths=[]; $sizes=[]; $counts=[];
            foreach ($groups as $i=>[$bytes,$rows]) {
                $path=$outputDir.'/'.(count($groups)===1?'prodotti.csv':'prodotti_parte_'.($i+1).'.csv');
                $pending=$path.'.tmp'; $dest=fopen($pending,'wb');
                try {
                    self::writeAll($dest,$header);
                    for ($remaining=$bytes;$remaining>0;) {
                        $chunk=fread($body,min(1_048_576,$remaining));
                        if ($chunk===false || $chunk==='') throw new AppError('storage_error','File di esportazione incompleto.',500);
                        self::writeAll($dest,$chunk); $remaining-=strlen($chunk);
                    }
                } finally { fclose($dest); }
                if (!rename($pending,$path)) throw new AppError('storage_error','Impossibile salvare il file di esportazione.',500);
                $paths[]=$path; $sizes[]=$bytes+strlen($header); $counts[]=$rows;
            }
            return ['paths'=>$paths,'sizes'=>$sizes,'row_counts'=>$counts,
                'diagnostics'=>max($sizes)>$threshold?[self::limitDiagnostic()]:[]];
        } finally {
            fclose($body); fclose($lengths); @unlink($bodyPath); @unlink($lengthPath);
        }
    }

    /** Do not accept a short fwrite as a successful checkpoint. */
    public static function writeAll($handle,string $bytes): void
    {
        $offset=0; $length=strlen($bytes);
        while ($offset<$length) {
            $written=fwrite($handle,substr($bytes,$offset));
            if ($written===false || $written===0) throw new AppError('storage_error','Spazio insufficiente per salvare il file.',500);
            $offset+=$written;
        }
    }
}
