<?php
declare(strict_types=1);
namespace MotorWorld\Hosting;

final class Application
{
    public readonly Store $store;
    public readonly JobService $jobs;
    public readonly ImportService $importer;
    public readonly ExportService $exporter;
    private DraftCompiler $compiler;
    private Catalog $categories;

    public function __construct(string $dataDir,public readonly string $resources)
    {
        $this->store=new Store($dataDir);$this->jobs=new JobService($this->store);
        $this->importer=new ImportService($this->store);$this->exporter=new ExportService($this->store,$resources);
        $this->compiler=new DraftCompiler($resources);$this->categories=new Catalog($resources);
        $this->jobs->register('import',[$this->importer,'step']);
        $this->jobs->register('export',[$this->exporter,'step'],[$this->exporter,'restart']);
        $this->jobs->register('validate',[$this,'validateStep'],[$this,'restartValidation']);
    }
    public function catalog(): array
    {
        try{return $this->store->get_record('catalog','active');}catch(NotFound){return $this->categories->load();}
    }
    public function ensureIdle(): void
    {
        if($this->store->list_jobs(['queued','running']))throw new AppError('job_busy','Attendi il completamento della lavorazione prima di modificare i dati o le impostazioni.',409);
    }
    public function mutate(callable $operation): mixed
    {
        return $this->store->transaction(function()use($operation){$this->ensureIdle();return $operation();});
    }
    public function validateStep(array $job,array $checkpoint): array
    {
        $payload=array_replace($job['payload'],$checkpoint['settings']??[]);$import=$payload['import_id'];$destination=$payload['destination'];
        $total=$this->store->count_products($import);
        $cp=$checkpoint?:['offset'=>0,'draft_count'=>0];$bytes=0;$started=microtime(true);
        foreach($this->store->iter_products($import,$cp['offset'],100) as$product){
            $bytes+=strlen(Support::json($product));
            $drafts=$this->compiler->compile($product,$this->store->get_compatibilities($product['id']),$payload['price_settings'],$payload['profile'],$payload['catalog'],$this->store->list_category_rules($product['supplier_id']),$this->store->get_overrides($product['id']));
            foreach($drafts as$draft){$this->store->stage_draft($job['id'],$draft,$cp['draft_count']++);$bytes+=strlen($draft['description_html']);}
            $cp['offset']++;
            if($bytes>=1048576||microtime(true)-$started>1.5)break;
        }
        $progress=['stage'=>'Categorie, prezzi e controllo','completed'=>$cp['offset'],'total'=>$total,'unit'=>'prodotti'];
        if($cp['offset']<$total)return ['done'=>false,'checkpoint'=>$cp,'progress'=>$progress];
        $this->store->publish_drafts($job['id'],$import,[$destination]);
        $this->store->put_record('validation',$import.':'.$destination,['profile'=>$payload['profile'],'price_settings'=>$payload['price_settings'],'catalog_version'=>$payload['catalog']['version']]);
        return ['done'=>true,'checkpoint'=>$cp,'progress'=>$progress,'result'=>['import_id'=>$import,'destination'=>$destination,'counts'=>$this->store->list_drafts($import,$destination,null,0,1)['counts']]];
    }

    public function restartValidation(array $job): void
    {
        $destination=$job['payload']['destination'];
        $settings=['price_settings'=>$this->store->get_record('price_settings',$destination),
            'profile'=>$this->store->get_platform_profile($destination),'catalog'=>$this->catalog()];
        $this->store->clear_staging($job['id']);
        $this->store->save_checkpoint($job['id'],['offset'=>0,'draft_count'=>0,'settings'=>$settings]);
    }
}
