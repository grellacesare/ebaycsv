import type {Job} from './models';

export interface HostingConfig {
  apiEndpoint:string;
  csrf:string;
  cooperativeJobs:boolean;
  basePath:string;
}
declare global {interface Window {MW_HOSTING?:HostingConfig;}}

export function hostingConfig(){return window.MW_HOSTING;}
export function apiUrl(path:string){
  const hosting=hostingConfig();
  return hosting?hosting.apiEndpoint+'?path='+encodeURIComponent(path):'/api'+path;
}

export class ApiError extends Error {
  constructor(message:string,public diagnostics:{field?:string,message:string}[]=[],public status?:number){super(message);}
}

export function apiHeaders(options:RequestInit={}):Headers {
  const headers=new Headers(options.headers);
  if(typeof options.body==='string'&&!headers.has('Content-Type'))headers.set('Content-Type','application/json');
  const hosting=hostingConfig(),method=(options.method??'GET').toUpperCase();
  if(hosting&&!['GET','HEAD','OPTIONS'].includes(method))headers.set('X-CSRF-Token',hosting.csrf);
  return headers;
}

let loginReloadRequested=false;
function checkSession(status:number){
  if(status===401&&hostingConfig()&&!loginReloadRequested){
    loginReloadRequested=true;
    window.location.reload();
  }
}

export async function api<T>(path:string,options:RequestInit={}):Promise<T>{
  const response=await fetch(apiUrl(path),{...options,headers:apiHeaders(options)});
  checkSession(response.status);
  const body=await response.json();
  if(!response.ok)throw new ApiError(body.message||body.detail||'La richiesta non è riuscita.',body.diagnostics||[],response.status);
  return body as T;
}
export const send=<T>(path:string,body:unknown,method='POST')=>api<T>(path,{method,body:JSON.stringify(body)});
export function query(values:Record<string,unknown>){const q=new URLSearchParams();Object.entries(values).forEach(([k,v])=>{if(v!==null&&v!==undefined&&v!=='')q.set(k,String(v));});return q.toString();}

export function updateJob(id:string):Promise<Job>{
  const path='/jobs/'+encodeURIComponent(id);
  return hostingConfig()?.cooperativeJobs?send<Job>(path+'/step',{}):api<Job>(path);
}

export async function restoreJob(storedId:string|null):Promise<Job|null>{
  const cooperative=hostingConfig()?.cooperativeJobs;
  let stored:Job|null=null;
  if(storedId){
    try{stored=await api<Job>('/jobs/'+encodeURIComponent(storedId));}
    catch(error){if(!(error instanceof ApiError&&error.status===404))throw error;}
    if(stored&&(stored.state==='running'||(stored.state==='queued'&&!cooperative)))return stored;
  }
  const jobs=await api<Job[]>('/jobs');
  const queue=cooperative?[...jobs].reverse():jobs;
  return jobs.find(job=>job.state==='running')??queue.find(job=>job.state==='queued')??stored;
}

export function uploadFiles<T>(files:File[],progress:(loaded:number,total:number)=>void):Promise<T>{
  return new Promise((resolve,reject)=>{
    const xhr=new XMLHttpRequest(),data=new FormData();
    files.forEach(file=>data.append(hostingConfig()?'files[]':'files',file));
    xhr.open('POST',apiUrl('/uploads'));
    apiHeaders({method:'POST',body:data}).forEach((value,name)=>xhr.setRequestHeader(name,value));
    xhr.upload.onprogress=event=>{if(event.lengthComputable)progress(event.loaded,event.total);};
    xhr.onerror=()=>reject(new ApiError('Caricamento interrotto. Riprova.'));
    xhr.onload=()=>{
      checkSession(xhr.status);
      try{
        const body=JSON.parse(xhr.responseText);
        if(xhr.status>=200&&xhr.status<300)resolve(body);
        else reject(new ApiError(body.message||'Errore di caricamento.',body.diagnostics||[],xhr.status));
      }catch{reject(new ApiError('Risposta del servizio non leggibile.',[],xhr.status));}
    };
    xhr.send(data);
  });
}
