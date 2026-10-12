<?php
declare(strict_types=1);
namespace MotorWorld\Hosting;

final class Portal
{
    private Installer $installer;
    private ?Auth $auth = null;
    private string $basePath;

    private function __construct(private string $publicDir)
    {
        $script=(string)($_SERVER['SCRIPT_NAME']??'/index.php');
        $this->basePath=rtrim(str_replace('\\','/',dirname($script)),'/').'/';
        if($this->basePath==='./')$this->basePath='/';
        $this->installer=new Installer($publicDir,(string)($_SERVER['DOCUMENT_ROOT']??''));
        header('Cache-Control: no-store, private');header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');header('X-Frame-Options: SAMEORIGIN');
        if(is_dir($this->installer->dataDir()))ini_set('error_log',$this->installer->dataDir().'/php-errors.log');
    }

    public static function run(string $directory,string $action): void
    {
        ini_set('display_errors','0');ini_set('log_errors','1');
        try{(new self($directory))->handle($action);}
        catch(\Throwable $error){
            $status=$error instanceof AppError?$error->status:500;
            http_response_code($status);
            $message=$error instanceof AppError?$error->getMessage():'Operazione non riuscita. Verifica che tutti i file del pacchetto siano stati caricati.';
            if($action==='api'){
                header('Content-Type: application/json; charset=utf-8');
                echo Support::json(['code'=>$error instanceof AppError?$error->errorCode:'internal_error','message'=>$message,
                    'diagnostics'=>$error instanceof AppError?$error->diagnostics:[]]);
            }else{
                header('Content-Type: text/html; charset=utf-8');
                echo '<!doctype html><html lang="it"><meta charset="utf-8"><title>CSV Studio</title><body><h1>CSV Studio</h1><p>'.self::e($message).'</p></body></html>';
            }
            if(!$error instanceof AppError)error_log('CSV Studio: '.get_class($error));
        }
    }

    private function secureRequest(): bool
    {
        if(in_array(strtolower((string)($_SERVER['HTTPS']??'')),['on','1','true'],true)||(int)($_SERVER['SERVER_PORT']??0)===443)return true;
        return in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true)
            &&strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO']??''))==='https';
    }
    private function openSession(): Auth
    {
        if($this->auth===null){$this->auth=new Auth($this->installer->dataDir(),$this->basePath);$this->auth->start();}
        return $this->auth;
    }
    private function handle(string $action): void
    {
        if(!$this->secureRequest())throw new AppError('https_required','Apri CSV Studio con un indirizzo HTTPS per proteggere l’accesso.',400);
        if($action==='setup'){$this->setup();return;}
        if(!$this->installer->installed()){
            if($action==='api')throw new AppError('setup_required','Completa la configurazione iniziale di CSV Studio.',409);
            header('Location: '.$this->basePath.'setup.php',true,303);return;
        }
        $auth=$this->openSession();
        if($action==='logout'){
            if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')throw new AppError('method_not_allowed','Usa il pulsante Esci.',405);
            $auth->logout(is_string($_POST['csrf']??null)?$_POST['csrf']:'');
            header('Location: '.$this->basePath,true,303);return;
        }
        if($action==='api'){$this->api($auth);return;}
        if(!$auth->isAuthenticated()){$this->login($auth);return;}
        $token=$auth->csrf();$auth->release();$nonce=base64_encode(random_bytes(18));
        $this->csp($nonce);
        $template=dirname(__DIR__).'/index.html';
        if(!is_file($template))throw new AppError('missing_interface','L’interfaccia non è stata caricata. Carica tutti i file del pacchetto FTP.',503);
        $configuration=json_encode(['apiEndpoint'=>$this->basePath.'api.php','basePath'=>$this->basePath,'csrf'=>$token,'cooperativeJobs'=>true],JSON_THROW_ON_ERROR|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
        $html=(string)file_get_contents($template);
        if(!str_contains($html,'</head>'))throw new AppError('missing_interface','Il file dell’interfaccia non è completo.',503);
        header('Content-Type: text/html; charset=utf-8');
        echo str_replace('</head>','<script nonce="'.self::e($nonce).'">window.MW_HOSTING='.$configuration.';</script></head>',$html);
    }
    private function api(Auth $auth): void
    {
        $auth->requireAuthenticated();$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
        if(!in_array($method,['GET','HEAD'],true))$auth->requireCsrf((string)($_SERVER['HTTP_X_CSRF_TOKEN']??''));
        $auth->release();
        $type=strtolower((string)($_SERVER['CONTENT_TYPE']??''));$body=[];
        $length=(int)($_SERVER['CONTENT_LENGTH']??0);
        if(str_starts_with($type,'application/json')){
            if($length>2097152)throw new AppError('request_too_large','La richiesta contiene troppi dati. Seleziona un lotto più piccolo.',413);
            $raw=(string)file_get_contents('php://input',false,null,0,2097153);
            if(strlen($raw)>2097152)throw new AppError('request_too_large','La richiesta contiene troppi dati.',413);
            try{$body=$raw===''?[]:json_decode($raw,true,128,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}
            catch(\JsonException){throw new AppError('invalid_json','La richiesta non è un JSON valido.');}
            $body=Input::object($body,'request');
        }elseif($method==='POST'&&str_starts_with($type,'multipart/form-data')&&$_FILES===[]&&$length>0){
            throw new AppError('upload_too_large','Caricamento non completato: il limite PHP è '.self::formatBytes(Requirements::bytes((string)ini_get('post_max_size'))).'. Aumenta il limite o carica i file uno alla volta.',413);
        }
        $path=$_GET['path']??'/';if(!is_string($path))throw new NotFound();
        $query=$_GET;unset($query['path']);
        $application=new Application($this->installer->dataDir(),Support::resources());
        $result=(new Router($application))->dispatch($method,$path,$query,$body,$_FILES);
        if($result instanceof Download){$this->download($result,$application);return;}
        header('Content-Type: application/json; charset=utf-8');echo Support::json($result);
    }
    private function download(Download $download,Application $app): void
    {
        $path=realpath($download->path);$logo=realpath($app->resources.'/motorworld-logo.png');
        if($path===false||($path!==$logo&&!str_starts_with($path,$app->store->dataDir().DIRECTORY_SEPARATOR)))throw new NotFound();
        header('Content-Type: '.$download->type);header('Content-Length: '.filesize($path));
        if($path!==$logo)header("Content-Disposition: attachment; filename=\"csvstudio-download\"; filename*=UTF-8''".rawurlencode(basename($download->name)));
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD'){
            $file=fopen($path,'rb');if($file===false)throw new NotFound();
            try{fpassthru($file);}finally{fclose($file);}
        }
    }
    private function login(Auth $auth): void
    {
        $error='';
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
            try{
                $auth->requireCsrf(is_string($_POST['csrf']??null)?$_POST['csrf']:'');
                $auth->login(is_string($_POST['username']??null)?$_POST['username']:'',is_string($_POST['password']??null)?$_POST['password']:'',(string)($_SERVER['REMOTE_ADDR']??''));
                $auth->release();header('Location: '.$this->basePath,true,303);return;
            }catch(AppError $exception){$error=$exception->getMessage();http_response_code($exception->status);}
        }
        $csrf=$auth->csrf();$auth->release();
        $this->page('Accedi a CSV Studio','Il tuo catalogo, pronto per eBay e PrestaShop.',
            '<form method="post"><input type="hidden" name="csrf" value="'.self::e($csrf).'">'
            .'<label>Nome utente<input name="username" autocomplete="username" required maxlength="60"></label>'
            .'<label>Password<input name="password" type="password" autocomplete="current-password" required maxlength="72"></label>'
            .'<button>Accedi</button></form>', $error);
    }
    private function setup(): void
    {
        if($this->installer->installed()){header('Location: '.$this->basePath,true,303);return;}
        $view=$this->installer->prepare();$error='';$csrf='';
        if($view['ready']){
            $auth=$this->openSession();
            if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
                try{
                    $auth->requireCsrf(is_string($_POST['csrf']??null)?$_POST['csrf']:'');
                    $ip=(string)($_SERVER['REMOTE_ADDR']??'');
                    $auth->checkSetupAttempt($ip);
                    try{
                        $this->installer->install(is_string($_POST['installation_key']??null)?trim($_POST['installation_key']):'',
                            is_string($_POST['username']??null)?$_POST['username']:'',is_string($_POST['password']??null)?$_POST['password']:'');
                    }catch(AppError $exception){
                        if($exception->errorCode==='invalid_installation_key')$auth->failedSetupAttempt($ip);
                        throw $exception;
                    }
                    $auth->release();header('Location: '.$this->basePath,true,303);return;
                }catch(AppError $exception){$error=$exception->getMessage();http_response_code($exception->status);}
            }
            $csrf=$auth->csrf();$auth->release();
        }
        $body='<div class="checks">';
        foreach($view['checks']as$check)$body.='<div class="check '.($check['ok']?'good':'bad').'"><b>'.($check['ok']?'✓':'!').' '.self::e($check['label']).'</b>'.($check['ok']?'':'<p>'.self::e($check['help']).'</p>').'</div>';
        $body.='</div><p class="small">PHP '.self::e($view['php_version']).' · File singolo: '.self::e(self::formatBytes($view['upload_limit'])).' · Caricamento totale: '.self::e(self::formatBytes($view['request_limit'])).'</p>';
        if($view['ready']){
            $body.='<div class="note"><b>Recupera il codice dal File Manager</b><p>Nella cartella privata <strong>csvstudio-data</strong>, accanto al webroot del dominio, apri <strong>installation-key.txt</strong>. Copia il codice qui sotto per creare il tuo accesso.</p></div>'
                .'<form method="post"><input type="hidden" name="csrf" value="'.self::e($csrf).'">'
                .'<label>Codice di installazione<input name="installation_key" type="password" required minlength="64" maxlength="64" autocomplete="off"></label>'
                .'<label>Scegli il nome utente<input name="username" required minlength="3" maxlength="60" autocomplete="username"></label>'
                .'<label>Scegli la password<input name="password" type="password" required minlength="12" maxlength="72" autocomplete="new-password"><small>Da 12 a 72 caratteri.</small></label>'
                .'<button>Completa installazione</button></form>';
        }
        $this->page('Installa CSV Studio','Configurazione iniziale del tuo archivio personale.',$body,$error);
    }
    private function csp(string $nonce): void
    {
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-".$nonce."'; style-src 'self' 'unsafe-inline'; img-src 'self' https: data:; font-src 'self'; connect-src 'self'; frame-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'; object-src 'none'");
    }
    private function page(string $heading,string $subtitle,string $content,string $error=''): void
    {
        $this->csp(base64_encode(random_bytes(18)));header('Content-Type: text/html; charset=utf-8');
        $logo=Support::resources().'/motorworld-logo.png';$image=is_file($logo)?'<img class="logo" src="data:image/png;base64,'.base64_encode((string)file_get_contents($logo)).'" alt="Motor World">':'';
        echo '<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Motor World · CSV Studio</title><style>'
            .'*{box-sizing:border-box}body{margin:0;background:#f3f6fb;color:#17314d;font:16px/1.5 system-ui,sans-serif;min-height:100vh;display:grid;place-items:center;padding:28px}.card{width:min(100%,570px);background:white;border:1px solid #dae4ee;border-radius:20px;padding:36px;box-shadow:0 20px 70px #203a5312}.logo{width:180px;height:auto;max-height:85px;object-fit:contain}.eyebrow{font-size:12px;font-weight:750;letter-spacing:2px;color:#378572;margin:20px 0 6px}h1{font-size:29px;line-height:1.2;margin:8px 0}.muted,.small,small{color:#64778a}.small{font-size:13px}form{display:grid;gap:18px;margin-top:24px}label{display:grid;gap:7px;font-weight:650;font-size:14px}input{width:100%;padding:13px;border:1px solid #bdccd9;border-radius:9px;font:inherit;color:#17314d}input:focus{outline:3px solid #d7eee8;border-color:#258570}button{background:#167863;color:white;border:0;border-radius:9px;padding:14px;font:700 15px system-ui;cursor:pointer}.error{background:#fff1f0;color:#a42934;border:1px solid #f3c8ca;padding:14px;border-radius:10px;margin:18px 0}.note{background:#eaf5f2;border:1px solid #cce5dc;padding:16px;border-radius:10px;font-size:14px}.note p{margin:6px 0 0}.checks{display:grid;gap:10px;margin-top:22px}.check{padding:11px 13px;border:1px solid #d8e8e1;border-radius:8px;font-size:14px}.good{color:#23725a}.bad{color:#a13a39;border-color:#ecc8c8;background:#fff8f7}.check p{margin:6px 0;font-size:13px}.foot{margin-top:24px;font-size:12px;color:#7b8c9b}@media(max-width:500px){body{padding:14px}.card{padding:24px}h1{font-size:25px}}'
            .'</style></head><body><main class="card">'.$image.'<div class="eyebrow">CSV STUDIO</div><h1>'.self::e($heading).'</h1><p class="muted">'.self::e($subtitle).'</p>'
            .($error!==''?'<div class="error" role="alert">'.self::e($error).'</div>':'').$content.'<div class="foot">Motor World · Archivio personale</div></main></body></html>';
    }
    private static function e(string $value): string {return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    private static function formatBytes(int $value): string {return $value===PHP_INT_MAX?'senza limite':number_format($value/1048576,1,',','.').' MB';}
}
