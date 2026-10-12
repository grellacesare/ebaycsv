<?php
declare(strict_types=1);

namespace MotorWorld\Hosting;

use Throwable;

/** Each authenticated step runs one bounded handler under a process-wide file lock. */
final class JobService
{
    private array $handlers=[];
    private array $resumeHandlers=[];

    public function __construct(private readonly Store $store) {}
    public function register(string $kind, callable $handler, ?callable $onResume=null): void
    {
        $this->handlers[$kind]=$handler;
        if ($onResume===null) unset($this->resumeHandlers[$kind]); else $this->resumeHandlers[$kind]=$onResume;
    }

    public function submit(string $kind, array $payload, string $requestKey): array
    {
        if ($requestKey === '' || strlen($requestKey)>512) throw new AppError('invalid_request_key','Chiave della richiesta non valida.');
        return $this->store->transaction(function() use($kind,$payload,$requestKey): array {
            $query=$this->store->pdo()->prepare('SELECT id FROM jobs WHERE request_key=?'); $query->execute([$requestKey]);
            $existing=$query->fetchColumn();
            if ($existing!==false) {
                $job=$this->get($existing);
                if ($job['kind']!==$kind || self::canonical($job['payload'])!==self::canonical($payload)) throw new AppError('request_key_conflict','La chiave della richiesta è già utilizzata con dati diversi.');
                return $job;
            }
            if (!isset($this->handlers[$kind])) throw new AppError('unknown_job','Tipo di lavorazione non disponibile.');
            $this->assertNoActive();
            $now=Support::now();
            return $this->store->create_job(['id'=>Support::uuid(),'kind'=>$kind,'payload'=>$payload,'request_key'=>$requestKey,'state'=>'queued',
                'progress'=>['stage'=>'In coda','completed'=>0,'total'=>1,'unit'=>'righe'],'result'=>null,'error'=>null,'created_at'=>$now,'updated_at'=>$now,'attempts'=>0]);
        });
    }

    private function assertNoActive(?string $except=null): void
    {
        $query=$this->store->pdo()->prepare("SELECT 1 FROM jobs WHERE state IN ('queued','running')".($except===null ? '' : ' AND id<>?').' LIMIT 1');
        $query->execute($except===null ? [] : [$except]);
        if ($query->fetchColumn()!==false) throw new AppError('job_in_progress','Attendi il completamento della lavorazione in corso.',409);
    }

    private function assertStepOwner(array $job): void
    {
        $query=$this->store->pdo()->prepare("SELECT 1 FROM jobs WHERE state='running' AND id<>? LIMIT 1"); $query->execute([$job['id']]);
        if ($query->fetchColumn()!==false) throw new AppError('job_in_progress','Attendi il completamento della lavorazione in corso.',409);
        if ($job['state']==='queued') {
            $next=$this->store->pdo()->query("SELECT id FROM jobs WHERE state='queued' ORDER BY rowid LIMIT 1")->fetchColumn();
            if ($next!==$job['id']) throw new AppError('job_in_progress','Attendi il completamento della lavorazione in corso.',409);
        }
    }

    private static function canonical(mixed $value): mixed
    {
        if ($value instanceof \stdClass) $value=(array)$value;
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value);
        foreach ($value as &$entry) $entry=self::canonical($entry);
        return $value;
    }
    public function get(string $id): array { return $this->store->get_job($id); }

    private function lock(string $id): mixed
    {
        $directory=$this->store->dataDir().'/locks';
        if (!is_dir($directory) && !@mkdir($directory,0700,true) && !is_dir($directory)) throw new AppError('storage_unavailable','Archivio non disponibile.',503);
        $handle=@fopen($directory.'/job-'.hash('sha256',$id).'.lock','c');
        if ($handle === false) throw new AppError('storage_unavailable','Archivio non disponibile.',503);
        if (!flock($handle,LOCK_EX|LOCK_NB)) { fclose($handle); return null; }
        return $handle;
    }

    public function step(string $id): array
    {
        $job=$this->get($id);
        if ($job['state']==='completed') return $job;
        $lock=$this->lock($id);
        if ($lock === null) return $this->get($id);
        try {
            $job=$this->get($id);
            if ($job['state']==='completed') return $job;
            if (in_array($job['state'],['failed','interrupted'],true)) throw new AppError('job_not_resumable','Riprendi il lavoro prima di continuare.');
            if (!isset($this->handlers[$job['kind']])) throw new AppError('unknown_job','Tipo di lavorazione non disponibile.');
            // Persist running separately: an abrupt process exit leaves the confirmed checkpoint intact.
            $job=$this->store->transaction(function() use($job): array {
                $this->assertStepOwner($job);
                $job['state']='running'; $job['error']=null; $job['attempts']++; $job['updated_at']=Support::now(); $this->store->save_job($job);
                return $job;
            });
            try {
                $confirmed=$this->store->transaction(function() use($job): array {
                    $outcome=($this->handlers[$job['kind']])($job,$this->store->get_checkpoint($job['id']));
                    if (!is_array($outcome) || !isset($outcome['done'],$outcome['checkpoint'],$outcome['progress']) || !is_bool($outcome['done']) || !is_array($outcome['checkpoint']) || !is_array($outcome['progress'])) {
                        throw new AppError('invalid_job_result','Risultato della lavorazione non valido.');
                    }
                    $job['state']=$outcome['done'] ? 'completed' : 'running';
                    $job['progress']=$outcome['progress'] + ['unit'=>'righe'];
                    if ($outcome['done']) $job['result']=$outcome['result'] ?? null;
                    $job['updated_at']=Support::now(); $job['error']=null;
                    $this->store->save_checkpoint($job['id'],$outcome['checkpoint']); $this->store->save_job($job);
                    return $job;
                });
            } catch (Throwable $error) {
                $job['state']='failed'; $job['updated_at']=Support::now();
                $job['error']=$error instanceof AppError ? $error->getMessage() : 'La lavorazione non è riuscita. Puoi riprenderla.';
                $this->store->save_job($job); return $job;
            }
            if ($confirmed['state']==='completed') $this->cleanupCompleted($confirmed);
            return $confirmed;
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }

    /** Cleanup is separate from publication and cannot change its committed outcome. */
    private function cleanupCompleted(array $job): void
    {
        try {
            if (!$this->published($job)) return;
        } catch (Throwable) { return; }
        try { $this->store->transaction(fn()=>$this->store->clear_staging($job['id'])); }
        catch (Throwable) { /* A later maintenance pass may retry leftover staging. */ }
        try {
            $base=$this->store->dataDir().'/work';
            if (is_link($base) || !is_dir($base) || realpath($base)!==$base) return;
            $path=$base.'/'.$job['id'];
            if (is_link($path)) { @unlink($path); return; }
            if (!file_exists($path)) return;
            if (realpath($path)!==$path) return;
            $this->removeWork($path);
        } catch (Throwable) { /* The immutable result remains available. */ }
    }

    private function published(array $job): bool
    {
        if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$job['id'])) return false;
        if (in_array($job['kind'],['import','validate'],true)) {
            $importId=$job['result']['import_id'] ?? null;
            if (!is_string($importId) || ($job['kind']==='import' && $importId!==$job['id'])) return false;
            $query=$this->store->pdo()->prepare("SELECT 1 FROM records WHERE kind='import' AND id=?"); $query->execute([$importId]);
            return $query->fetchColumn()!==false;
        }
        if ($job['kind']!=='export' || ($job['result']['export_id'] ?? null)!==$job['id']) return false;
        // Read only the small artifact manifest; export snapshots can be very large.
        $query=$this->store->pdo()->prepare("SELECT json_extract(payload,'$.artifacts') FROM records WHERE kind='export' AND id=?"); $query->execute([$job['id']]);
        $manifest=$query->fetchColumn();
        if (!is_string($manifest)) return false;
        $artifacts=json_decode($manifest,true,512,JSON_THROW_ON_ERROR);
        if (!is_array($artifacts) || !$artifacts) return false;
        $base=$this->store->dataDir().'/exports'; $directory=$base.'/'.$job['id'];
        if (is_link($base) || is_link($directory) || !is_dir($directory) || realpath($directory)!==$directory) return false;
        foreach ($artifacts as $artifact) {
            $name=$artifact['name'] ?? '';
            if (!is_string($name) || $name==='.' || $name==='..' || !preg_match('/^[A-Za-z0-9_.-]+$/D',$name)) return false;
            $path=$directory.'/'.$name;
            if (is_link($path) || !is_file($path) || realpath($path)!==$path || !isset($artifact['size']) || @filesize($path)!==$artifact['size']) return false;
        }
        return true;
    }

    private function removeWork(string $path): void
    {
        if (is_link($path) || !is_dir($path)) { @unlink($path); return; }
        foreach (new \FilesystemIterator($path,\FilesystemIterator::SKIP_DOTS) as $entry) $this->removeWork($entry->getPathname());
        @rmdir($path);
    }

    public function resume(string $id): array
    {
        $this->get($id); $lock=$this->lock($id);
        if ($lock === null) throw new AppError('job_busy','Il lavoro è già in esecuzione.',409);
        try {
            return $this->store->transaction(function() use($id): array {
                $job=$this->get($id);
                if (!in_array($job['state'],['failed','interrupted'],true)) throw new AppError('job_not_resumable','Questo lavoro non richiede una ripresa.');
                $this->assertNoActive($id);
                if (isset($this->resumeHandlers[$job['kind']])) ($this->resumeHandlers[$job['kind']])($job);
                $job['state']='queued'; $job['error']=null; $job['updated_at']=Support::now(); $this->store->save_job($job); return $job;
            });
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }
}
