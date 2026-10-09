export class ApiError extends Error{constructor(message:string,public diagnostics:{field?:string,message:string}[]=[]){super(message);}}
export async function api<T>(path:string,options:RequestInit={}):Promise<T>{
  const r=await fetch('/api'+path,{...options,headers:{...(options.body?{'Content-Type':'application/json'}:{}),...options.headers}});
  const body=await r.json();
  if(!r.ok)throw new ApiError(body.message||body.detail||'La richiesta non è riuscita.',body.diagnostics||[]);
  return body as T;
}
export const send=<T>(path:string,body:unknown,method='POST')=>api<T>(path,{method,body:JSON.stringify(body)});
export function query(values:Record<string,unknown>){const q=new URLSearchParams();Object.entries(values).forEach(([k,v])=>{if(v!==null&&v!==undefined&&v!=='')q.set(k,String(v));});return q.toString();}
export function uploadFiles<T>(files:File[],progress:(loaded:number,total:number)=>void):Promise<T>{
  return new Promise((resolve,reject)=>{
    const xhr=new XMLHttpRequest(),data=new FormData();files.forEach(f=>data.append('files',f));
    xhr.open('POST','/api/uploads');xhr.upload.onprogress=e=>{if(e.lengthComputable)progress(e.loaded,e.total);};
    xhr.onerror=()=>reject(new ApiError('Caricamento interrotto. Riprova.'));
    xhr.onload=()=>{try{const body=JSON.parse(xhr.responseText);xhr.status>=200&&xhr.status<300?resolve(body):reject(new ApiError(body.message||'Errore di caricamento.'));}catch{reject(new ApiError('Risposta del servizio non leggibile.'));}};
    xhr.send(data);
  });
}
