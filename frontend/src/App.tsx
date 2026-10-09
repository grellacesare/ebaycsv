import {useEffect,useRef,useState} from 'react';
import {Upload,Boxes,Calculator,ClipboardCheck,Download,Settings,ShieldCheck,ChevronRight,TriangleAlert,X} from 'lucide-react';
import {api,send,ApiError} from './api';
import type {Destination,Job,ImportSummary,CategoryCatalog} from './models';
import type {PageName,WorkbenchProps} from './workspace';
import {HelpField} from './components/HelpField';
import {ProgressPanel} from './components/ProgressPanel';
import {ImportIssues} from './components/ImportIssues';
import {ImportPage} from './pages/ImportPage';
import {ProductsPage} from './pages/ProductsPage';
import {PricingPage} from './pages/PricingPage';
import {ReviewPage} from './pages/ReviewPage';
import {ExportPage} from './pages/ExportPage';
import {SettingsPage} from './pages/SettingsPage';
const nav=[['import','Importazione',Upload],['products','Prodotti e compatibilità',Boxes],['pricing','Prezzi',Calculator],['review','Controllo',ClipboardCheck],['exports','Esportazione',Download],['settings','Impostazioni',Settings]] as const;
export default function App(){
  const[page,setPage]=useState<PageName>('import'),[destination,setDestination]=useState<Destination>((localStorage.getItem('mw-destination') as Destination)||'ebay');
  const[importId,setImportId]=useState(localStorage.getItem('mw-import-id')||''),[imports,setImports]=useState<ImportSummary[]>([]),[catalog,setCatalog]=useState<CategoryCatalog|null>(null);
  const[job,setJob]=useState<Job|null>(null),[refresh,setRefresh]=useState(0),[working,setWorking]=useState(false),[error,setError]=useState<{message:string,details?:string[]}|null>(null);
  const handled=useRef(new Set<string>());
  const busy=working||job?.state==='queued'||job?.state==='running';
  const onError=(e:unknown)=>setError({message:e instanceof Error?e.message:'Si è verificato un errore.',details:e instanceof ApiError?e.diagnostics.map(d=>(d.field?d.field+': ':'')+d.message):[]});
  useEffect(()=>{api<ImportSummary[]>('/imports').then(list=>{setImports(list);if(importId&&!list.some(i=>i.id===importId))setImportId('');}).catch(onError);api<CategoryCatalog>('/categories').then(setCatalog).catch(onError);},[refresh]);
  useEffect(()=>{localStorage.setItem('mw-import-id',importId);},[importId]);
  useEffect(()=>{localStorage.setItem('mw-destination',destination);},[destination]);
  useEffect(()=>{const id=localStorage.getItem('mw-job-id');if(id)api<Job>('/jobs/'+id).then(setJob).catch(()=>localStorage.removeItem('mw-job-id'));},[]);
  useEffect(()=>{
    if(!job)return;
    localStorage.setItem('mw-job-id',job.id);
    if(job.state==='completed'&&!handled.current.has(job.id)){
      handled.current.add(job.id);setRefresh(n=>n+1);
      if(job.kind==='import'&&typeof job.result?.import_id==='string'){setImportId(job.result.import_id);setPage('products');}
      if(job.kind==='export')setPage('exports');
    }
    if(job.state!=='queued'&&job.state!=='running')return;
    let alive=true;const timer=setTimeout(()=>api<Job>('/jobs/'+job.id).then(j=>{if(alive)setJob(j);}).catch(onError),300);
    return()=>{alive=false;clearTimeout(timer);};
  },[job]);
  async function runJob(path:string,body:unknown){setError(null);setJob(null);setWorking(true);try{const j=await send<Job>(path,body);setJob(j);return j;}catch(e){onError(e);}finally{setWorking(false);}}
  const props:WorkbenchProps={importId,destination,refresh,busy:!!busy,catalog,onError,onRefresh:()=>setRefresh(n=>n+1),runJob,navigate:setPage};
  const chosen=imports.find(i=>i.id===importId);
  return <div className="workspace"><aside className="sidebar"><div className="brand"><img src="/api/brand/logo" alt="Motor World Sciacca"/><div><b>MOTOR WORLD</b><span>CSV STUDIO</span></div></div><div className="side-caption">IL TUO CATALOGO, PRONTO A VENDERE</div><nav aria-label="Navigazione principale">{nav.map(([id,label,Icon],i)=><button key={id} aria-label={label} className={page===id?'active':''} onClick={()=>setPage(id)}><Icon size={19}/><span>{label}</span>{i<5&&<small>{String(i+1).padStart(2,'0')}</small>}</button>)}</nav><div className="side-foot"><ShieldCheck size={21}/><div><b>Archivio personale</b><span>Profili, compatibilità e lavorazioni salvate.</span></div></div></aside><div className="main-shell"><header className="topbar"><div className="breadcrumb">CSV Studio <ChevronRight size={14}/><b>{nav.find(n=>n[0]===page)?.[1]}</b></div><div className="destination"><HelpField id="destination" label="Destinazione" help="eBay.it crea un’inserzione per modello compatibile. PrestaShop 9 crea un prodotto per codice con tutti i modelli nella descrizione."><select id="destination" value={destination} disabled={!!busy} onChange={e=>setDestination(e.target.value as Destination)}><option value="ebay">eBay.it</option><option value="prestashop">PrestaShop 9</option></select></HelpField></div></header><main><div className="lot-selector"><HelpField id="current-import" label="Lavorazione" help="Riprendi un lotto salvato. Le compatibilità e le correzioni restano nell’archivio; i file esportati si riscaricano dallo storico."><select id="current-import" value={importId} disabled={!!busy} onChange={e=>setImportId(e.target.value)}><option value="">Nuova lavorazione</option>{imports.map(i=><option key={i.id} value={i.id}>{new Date(i.created_at).toLocaleString('it-IT')} · {i.product_count.toLocaleString('it-IT')} prodotti</option>)}</select></HelpField>{chosen&&<span className="lot-count"><Boxes size={17}/>{chosen.product_count.toLocaleString('it-IT')} prodotti</span>}</div>{error&&<div className="alert error" role="alert"><TriangleAlert size={20}/><div><b>{error.message}</b>{error.details?.map((d,i)=><p key={i}>{d}</p>)}</div><button className="icon-button" aria-label="Chiudi errore" onClick={()=>setError(null)}><X size={17}/></button></div>}{job&&<ProgressPanel job={job} onResume={()=>runJob('/jobs/'+job.id+'/resume',{})}/>}{chosen&&<ImportIssues summary={chosen}/>}<div key={destination}>{page==='import'?<ImportPage {...props}/>:page==='products'?<ProductsPage {...props}/>:page==='pricing'?<PricingPage {...props}/>:page==='review'?<ReviewPage {...props}/>:page==='exports'?<ExportPage {...props}/>:<SettingsPage {...props}/>}</div></main><footer className="app-footer">Motor World Sciacca <span>Esporta il CSV, poi caricalo manualmente sulla piattaforma scelta.</span></footer></div></div>;
}
