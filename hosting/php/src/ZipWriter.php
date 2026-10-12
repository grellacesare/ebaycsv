<?php
declare(strict_types=1);
namespace MotorWorld\Hosting;

/** ZIP STORE with data descriptors: no optional extension and bounded request work. */
final class ZipWriter
{
    private static ?array $crcTable=null;

    private static function crc(int $state,string $bytes): int
    {
        if (self::$crcTable===null) {
            self::$crcTable=[];
            for ($i=0;$i<256;$i++) {
                $value=$i;
                for ($j=0;$j<8;$j++) $value=($value&1)?(0xedb88320^($value>>1)):($value>>1);
                self::$crcTable[$i]=$value;
            }
        }
        for ($i=0,$length=strlen($bytes);$i<$length;$i++) $state=self::$crcTable[($state^ord($bytes[$i]))&255]^($state>>8);
        return $state;
    }

    /** Paths and names are server-generated immutable artifacts. */
    public static function step(array $paths,string $target,array $checkpoint,int $maxBytes=1_048_576): array
    {
        $cp=$checkpoint?:['index'=>0,'offset'=>0,'file_offset'=>0,'entries'=>[],'current'=>false];
        $handle=fopen($target,'c+b');
        if ($handle===false) throw new AppError('storage_error','Impossibile creare l’archivio ZIP.',500);
        try {
            if (!ftruncate($handle,$cp['offset']) || fseek($handle,$cp['offset'])!==0)
                throw new AppError('storage_error','Impossibile riprendere l’archivio ZIP.',500);
            if ($cp['index']>=count($paths)) {
                $start=$cp['offset'];
                foreach ($cp['entries'] as $entry) {
                    CsvWriter::writeAll($handle,pack('VvvvvvvVVVvvvvvVV',0x02014b50,20,20,8,0,0,33,
                        $entry['crc'],$entry['size'],$entry['size'],strlen($entry['name']),0,0,0,0,0,$entry['local_offset']).$entry['name']);
                }
                $end=ftell($handle);
                CsvWriter::writeAll($handle,pack('VvvvvVVv',0x06054b50,0,0,count($cp['entries']),count($cp['entries']),$end-$start,$start,0));
                $cp['offset']=ftell($handle);
                return ['done'=>true,'checkpoint'=>$cp];
            }
            $path=$paths[$cp['index']]; $name=basename($path);
            if (!$cp['current']) {
                $size=filesize($path);
                if ($size===false || $size>0xffffffff) throw new AppError('zip_file_too_large','Seleziona un lotto più piccolo per creare lo ZIP.');
                $cp['local_offset']=ftell($handle); $cp['size']=$size; $cp['crc']=0xffffffff;
                CsvWriter::writeAll($handle,pack('VvvvvvVVVvv',0x04034b50,20,8,0,0,33,0,0,0,strlen($name),0).$name);
                $cp['current']=true; $cp['file_offset']=0;
            }
            $source=fopen($path,'rb');
            try {
                if (fseek($source,$cp['file_offset'])!==0) throw new AppError('storage_error','File ZIP incompleto.',500);
                $remaining=min($maxBytes,$cp['size']-$cp['file_offset']);
                while ($remaining>0) {
                    $chunk=fread($source,min(65_536,$remaining));
                    if ($chunk===false || $chunk==='') throw new AppError('storage_error','File ZIP incompleto.',500);
                    $cp['crc']=self::crc($cp['crc'],$chunk); CsvWriter::writeAll($handle,$chunk);
                    $length=strlen($chunk); $cp['file_offset']+=$length; $remaining-=$length;
                }
            } finally { fclose($source); }
            if ($cp['file_offset']===$cp['size']) {
                $crc=$cp['crc']^0xffffffff;
                CsvWriter::writeAll($handle,pack('VVVV',0x08074b50,$crc,$cp['size'],$cp['size']));
                $cp['entries'][]=['name'=>$name,'crc'=>$crc,'size'=>$cp['size'],'local_offset'=>$cp['local_offset']];
                $cp['index']++; $cp['current']=false;
            }
            $cp['offset']=ftell($handle);
            if ($cp['offset']>0xffffffff) throw new AppError('zip_file_too_large','Seleziona un lotto più piccolo per creare lo ZIP.');
            return ['done'=>false,'checkpoint'=>$cp];
        } finally { fclose($handle); }
    }
}
