import {createElement} from 'react';
import {render,screen,waitFor,act,fireEvent} from '@testing-library/react';
import {afterEach,expect,test,vi} from 'vitest';
import * as client from '../api';
import App from '../App';
import {ImportIssues} from '../components/ImportIssues';
import {ProgressPanel} from '../components/ProgressPanel';
import type {Job,Diagnostic} from '../models';

const hosting={apiEndpoint:'/csvstudio/api.php',basePath:'/csvstudio/',csrf:'test-csrf',cooperativeJobs:true};
function useHosting(){window.MW_HOSTING={...hosting};}
function response(body:unknown,status=200){return new Response(JSON.stringify(body),{status,headers:{'Content-Type':'application/json'}});}
function job(state:Job['state']='running',id='job-1'):Job{return {id,kind:'validate',payload:{},request_key:id,state,progress:{stage:'Controllo',completed:1,total:2,unit:'prodotti'},result:null,error:null,created_at:'',updated_at:'',attempts:1};}
function pathOf(input:RequestInfo|URL){const url=String(input);return url.startsWith('/api')?url.slice(4):new URL(url,'https://motorworld.it').searchParams.get('path');}

afterEach(()=>{delete window.MW_HOSTING;localStorage.clear();vi.restoreAllMocks();vi.unstubAllGlobals();});

test('preserves Python URLs and does not send a hosting CSRF header',async()=>{
  const fetch=vi.fn(async(_input:RequestInfo|URL,_options?:RequestInit)=>response({ok:true}));vi.stubGlobal('fetch',fetch);
  await client.send('/settings',{enabled:true},'PUT');
  expect(fetch.mock.calls[0][0]).toBe('/api/settings');
  expect(new Headers(fetch.mock.calls[0][1]?.headers).has('X-CSRF-Token')).toBe(false);
});

test('encodes the whole PHP route and preserves its inner query and download filename',async()=>{
  useHosting();const fetch=vi.fn(async(_input:RequestInfo|URL,_options?:RequestInit)=>response({ok:true}));vi.stubGlobal('fetch',fetch);
  const path='/exports/id/files/prezzi%20moto.csv?download=1&version=2';
  await client.api(path);
  expect(fetch.mock.calls[0][0]).toBe(hosting.apiEndpoint+'?path='+encodeURIComponent(path));
  expect(client.apiUrl('/brand/logo')).toBe('/csvstudio/api.php?path=%2Fbrand%2Flogo');
});

test.each(['POST','PUT','PATCH','DELETE'])('adds CSRF to a %s while preserving caller Headers',async(method)=>{
  useHosting();const fetch=vi.fn(async(_input:RequestInfo|URL,_options?:RequestInit)=>response({ok:true}));vi.stubGlobal('fetch',fetch);
  await client.api('/settings',{method,headers:new Headers({'X-Requested-With':'CSV Studio'}),body:'{}'});
  const headers=new Headers(fetch.mock.calls[0][1]?.headers);
  expect(headers.get('X-CSRF-Token')).toBe('test-csrf');
  expect(headers.get('X-Requested-With')).toBe('CSV Studio');
  expect(headers.get('Content-Type')).toBe('application/json');
});

test('does not add CSRF to read requests and lets FormData set its multipart boundary',async()=>{
  useHosting();const fetch=vi.fn(async(_input:RequestInfo|URL,_options?:RequestInit)=>response({ok:true}));vi.stubGlobal('fetch',fetch);
  await client.api('/categories');
  expect(new Headers(fetch.mock.calls[0][1]?.headers).has('X-CSRF-Token')).toBe(false);
  const data=new FormData();data.append('file',new File(['a;b'],'categorie.csv'));
  await client.api('/categories/ebay/replace',{method:'POST',body:data});
  const options=fetch.mock.calls[1][1];
  expect(options?.body).toBe(data);
  expect(new Headers(options?.headers).has('Content-Type')).toBe(false);
  expect(new Headers(options?.headers).get('X-CSRF-Token')).toBe('test-csrf');
});

test.each([false,true])('uploads real multipart files with the correct Python/PHP field (hosting=%s)',async(php)=>{
  if(php)useHosting();
  let sent:FormData|undefined;let endpoint='';const headers=new Headers();
  class UploadRequest{
    upload={onprogress:null as null|((event:ProgressEvent)=>void)};
    onerror:null|(()=>void)=null;onload:null|(()=>void)=null;status=200;responseText='{"uploads":[]}';
    open(_method:string,url:string){endpoint=url;}
    setRequestHeader(name:string,value:string){headers.set(name,value);}
    send(data:FormData){sent=data;this.onload?.();}
  }
  vi.stubGlobal('XMLHttpRequest',UploadRequest);
  await client.uploadFiles([new File(['a;b'],'listino.csv')],()=>{});
  expect(endpoint).toBe(php?'/csvstudio/api.php?path=%2Fuploads':'/api/uploads');
  expect(Array.from(sent!.keys())).toEqual([php?'files[]':'files']);
  expect((sent!.get(php?'files[]':'files') as File).name).toBe('listino.csv');
  expect(headers.get('X-CSRF-Token')).toBe(php?'test-csrf':null);
  expect(headers.has('Content-Type')).toBe(false);
});

test('steps cooperative jobs with POST and CSRF, keeping Python polling read-only',async()=>{
  const fetch=vi.fn(async(_input:RequestInfo|URL,_options?:RequestInit)=>response(job()));vi.stubGlobal('fetch',fetch);
  await client.updateJob('job-1');
  expect(fetch.mock.calls[0][0]).toBe('/api/jobs/job-1');
  expect(fetch.mock.calls[0][1]?.method??'GET').toBe('GET');
  useHosting();await client.updateJob('job-1');
  expect(pathOf(fetch.mock.calls[1][0])).toBe('/jobs/job-1/step');
  expect(fetch.mock.calls[1][1]?.method).toBe('POST');
  expect(new Headers(fetch.mock.calls[1][1]?.headers).get('X-CSRF-Token')).toBe('test-csrf');
});

test.each([null,'missing-id','completed-id'])('restores active server jobs when a local job is absent or inactive (%s)',async(stored)=>{
  const active=job();
  vi.stubGlobal('fetch',vi.fn(async(input:RequestInfo|URL)=>{
    const path=pathOf(input);
    if(path==='/jobs/missing-id')return response({message:'Assente'},404);
    if(path==='/jobs/completed-id')return response(job('completed','completed-id'));
    if(path==='/jobs')return response([active]);
    throw new Error('Unexpected route '+path);
  }));
  expect(await client.restoreJob(stored)).toEqual(active);
});

test.each([false,true])('uses the oldest cooperative queued job and preserves Python queue selection (hosting=%s)',async(php)=>{
  if(php)useHosting();
  const newest=job('queued','newest'),oldest=job('queued','oldest');
  vi.stubGlobal('fetch',vi.fn(async()=>response([newest,oldest])));
  expect(await client.restoreJob(null)).toEqual(php?oldest:newest);
});

test('restores a running cooperative job before any queued jobs',async()=>{
  useHosting();const running=job('running','active');
  vi.stubGlobal('fetch',vi.fn(async()=>response([job('queued','newest'),running,job('queued','oldest')])));
  expect(await client.restoreJob(null)).toEqual(running);
});

test('a locally stored newer queued job does not bypass the cooperative FIFO',async()=>{
  useHosting();const newest=job('queued','newest'),oldest=job('queued','oldest');
  vi.stubGlobal('fetch',vi.fn(async(input:RequestInfo|URL)=>response(pathOf(input)==='/jobs/newest'?newest:[newest,oldest])));
  expect(await client.restoreJob('newest')).toEqual(oldest);
});

test('lets a failed hosting job resume explicitly through the cooperative API',async()=>{
  useHosting();localStorage.setItem('mw-job-id','failed-job');
  const resumed=job('queued','failed-job');let resumedRequest:RequestInit|undefined;
  vi.stubGlobal('fetch',vi.fn(async(input:RequestInfo|URL,options?:RequestInit)=>{
    const path=pathOf(input);
    if(path==='/jobs/failed-job')return response({...job('failed','failed-job'),error:'Correggi la causa e riprendi.'});
    if(path==='/jobs')return response([]);
    if(path==='/jobs/failed-job/resume'){resumedRequest=options;return response(resumed);}
    if(path==='/jobs/failed-job/step')return await new Promise<Response>(()=>{});
    return response(path==='/categories'?{}:[]);
  }));
  render(createElement(App));
  fireEvent.click(await screen.findByRole('button',{name:'Riprendi'}));
  await waitFor(()=>expect(resumedRequest?.method).toBe('POST'));
  expect(new Headers(resumedRequest?.headers).get('X-CSRF-Token')).toBe(hosting.csrf);
  expect(await screen.findByText('Controllo in corso')).toBeVisible();
});

test('preserves the existing Python resume button only for interrupted jobs',()=>{
  const view=render(createElement(ProgressPanel,{job:job('failed'),onResume:vi.fn()}));
  expect(screen.queryByRole('button',{name:'Riprendi'})).not.toBeInTheDocument();
  view.rerender(createElement(ProgressPanel,{job:job('interrupted'),onResume:vi.fn()}));
  expect(screen.getByRole('button',{name:'Riprendi'})).toBeVisible();
});

test('reloads hosting login once when concurrent requests return 401',async()=>{
  const reload=vi.fn();
  vi.stubGlobal('window',{MW_HOSTING:{...hosting},location:{reload}});
  vi.stubGlobal('fetch',vi.fn(async(_input:RequestInfo|URL,_options?:RequestInit)=>response({message:'Accedi'},401)));
  await Promise.allSettled([client.api('/imports'),client.api('/categories')]);
  expect(reload).toHaveBeenCalledTimes(1);
});

test('uses the nested logo URL and exposes a CSRF logout form only on hosting',async()=>{
  useHosting();vi.stubGlobal('fetch',vi.fn(async(input:RequestInfo|URL)=>response(pathOf(input)==='/categories'?{}:[])));
  render(createElement(App));
  const exit=await screen.findByRole('button',{name:'Esci'});
  expect(screen.getByRole('img',{name:'Motor World Sciacca'})).toHaveAttribute('src',client.apiUrl('/brand/logo'));
  expect(exit.closest('form')).toHaveAttribute('method','post');
  expect(exit.closest('form')).toHaveAttribute('action','/csvstudio/logout.php');
  expect(exit.closest('form')?.querySelector('input[name="csrf"]')).toHaveValue('test-csrf');
});

test('retains the Python logo and hides the hosting logout when no adapter is injected',async()=>{
  vi.stubGlobal('fetch',vi.fn(async(input:RequestInfo|URL)=>response(pathOf(input)==='/categories'?{}:[])));
  render(createElement(App));
  await waitFor(()=>expect(screen.getByRole('img',{name:'Motor World Sciacca'})).toHaveAttribute('src','/api/brand/logo'));
  expect(screen.queryByRole('button',{name:'Esci'})).not.toBeInTheDocument();
});

test.each([0,2])('uses server diagnostic totals with a bounded visible list (%s rows)',(count)=>{
  useHosting();const rows:Diagnostic[]=[{level:'error',code:'missing_code',message:'Codice assente',hint:'Correggi il codice'},{level:'warning',code:'invalid_weight',message:'Peso non valido',hint:'Verifica il peso'}].slice(0,count) as Diagnostic[];
  render(createElement(ImportIssues,{summary:{id:'import-1',created_at:'',product_count:1,source_counts:{},diagnostics:rows,diagnostic_totals:{error:9,warning:4,info:0}}}));
  expect(screen.getByText(/9 errori di origine · 4 avvisi/)).toBeVisible();
  expect(screen.getByText(/Il rapporto contiene tutte le segnalazioni/)).toBeVisible();
  expect(screen.getByRole('link',{name:'Scarica rapporto importazione'})).toHaveAttribute('href',client.apiUrl('/imports/import-1/report'));
  expect(screen.getByText('Mostra segnalazioni (13)')).toBeVisible();
});

test('falls back to actual diagnostic counts for existing Python summaries',()=>{
  render(createElement(ImportIssues,{summary:{id:'old-import',created_at:'',product_count:1,source_counts:{},diagnostics:[{level:'error',code:'missing_code',message:'Codice assente',hint:'Correggi il codice'}]}}));
  expect(screen.getByText(/1 errori di origine · 0 avvisi/)).toBeVisible();
  expect(screen.queryByText(/Il rapporto contiene tutte le segnalazioni/)).not.toBeInTheDocument();
});

test('waits for each cooperative response before scheduling another step',async()=>{
  useHosting();let finish:((value:Response)=>void)|undefined;let steps=0,finished=false;
  vi.stubGlobal('fetch',vi.fn(async(input:RequestInfo|URL)=>{
    const path=pathOf(input);
    if(path==='/jobs')return response(finished?[]:[job()]);
    if(path==='/jobs/job-1/step'){steps++;return await new Promise<Response>(resolve=>{finish=resolve;});}
    return response(path==='/categories'?{}:[]);
  }));
  render(createElement(App));
  await waitFor(()=>expect(steps).toBe(1),{timeout:2000});
  await act(async()=>{await new Promise(resolve=>setTimeout(resolve,750));});
  expect(steps).toBe(1);
  await act(async()=>{finished=true;finish!(response(job('completed')));});
  await waitFor(()=>expect(screen.queryByRole('progressbar')).not.toBeInTheDocument());
});

test('automatically drains the next persisted hosting job after the first completes',async()=>{
  useHosting();const finished=new Set<string>(),steps:string[]=[];
  vi.stubGlobal('fetch',vi.fn(async(input:RequestInfo|URL)=>{
    const path=pathOf(input);
    if(path==='/jobs')return response(['job-2','job-1'].filter(id=>!finished.has(id)).map(id=>job('queued',id)));
    const step=path?.match(/^\/jobs\/(job-[12])\/step$/);
    if(step){steps.push(step[1]);finished.add(step[1]);return response(job('completed',step[1]));}
    return response(path==='/categories'?{}:[]);
  }));
  render(createElement(App));
  await waitFor(()=>expect(steps).toEqual(['job-1','job-2']),{timeout:3000});
});

test('does not replace a newly submitted job with an older pending recovery response',async()=>{
  useHosting();localStorage.setItem('mw-import-id','import-1');
  let restore:((value:Response)=>void)|undefined;let reads=0;const steps:string[]=[];
  vi.stubGlobal('fetch',vi.fn(async(input:RequestInfo|URL)=>{
    const path=pathOf(input);
    if(path==='/imports')return response([{id:'import-1',created_at:'2026-10-12T00:00:00Z',product_count:1,source_counts:{},diagnostics:[]}]);
    if(path==='/jobs'){
      reads++;if(reads===1)return response([job()]);
      return await new Promise<Response>(resolve=>{restore=resolve;});
    }
    if(path==='/jobs/job-1/step')return response(job('completed'));
    if(path==='/imports/import-1/validate')return response({...job('queued','new-job'),progress:{stage:'Nuovo controllo avviato',completed:0,total:2,unit:'prodotti'}});
    if(path?.endsWith('/step')){steps.push(path);return await new Promise<Response>(()=>{});}
    if(path?.startsWith('/imports/import-1/drafts'))return response({items:[],total:0,counts:{}});
    return response(path==='/categories'?{}:[]);
  }));
  render(createElement(App));
  await waitFor(()=>expect(restore).toBeTypeOf('function'),{timeout:2000});
  fireEvent.click(screen.getByRole('button',{name:'Controllo'}));
  const validate=await screen.findByRole('button',{name:'Controlla lotto'});
  await waitFor(()=>expect(validate).toBeEnabled());fireEvent.click(validate);
  expect(await screen.findByText('Nuovo controllo avviato')).toBeVisible();
  await act(async()=>{restore!(response([job('queued','old-leftover')]));});
  await waitFor(()=>expect(steps).toEqual(['/jobs/new-job/step']),{timeout:2000});
  expect(screen.getByText('Nuovo controllo avviato')).toBeVisible();
});
