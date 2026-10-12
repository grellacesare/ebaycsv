import type {Job} from '../models';
import {hostingConfig} from '../api';
import {LoaderCircle,CheckCircle2,TriangleAlert,RotateCcw} from 'lucide-react';
export function ProgressPanel({job,onResume}:{job:Job,onResume?:()=>void}){
  const done=job.state==='completed',failed=job.state==='failed'||job.state==='interrupted',p=job.progress??{stage:"In attesa",completed:0,total:0,unit:"righe"};
  const titles={import:'Importazione',validate:'Controllo',export:'Esportazione'};
  const title=titles[job.kind as keyof typeof titles]||'Lavorazione';
  const resumable=job.state==='interrupted'||(hostingConfig()?.cooperativeJobs&&job.state==='failed');
  return <section className={'progress-panel '+(failed?'failed':'')} aria-live="polite"><div className="progress-heading">{done?<CheckCircle2 size={22}/>:failed?<TriangleAlert size={22}/>:<LoaderCircle className="spin" size={22}/>}<div><b>{done?(job.kind==='import'?'Importazione completata':job.kind==='validate'?'Controllo completato':'Esportazione completata'):failed?title+' da riprendere':title+' in corso'}</b><small>{job.error||p.stage}</small></div>{resumable&&onResume&&<button className="button secondary" onClick={onResume}><RotateCcw size={16}/>Riprendi</button>}</div>{!failed&&<div className="progress-track"><progress max={p.total||1} value={done?(p.total||1):p.completed}/><span>{p.completed.toLocaleString('it-IT')} / {p.total.toLocaleString('it-IT')} {p.unit}</span></div>}</section>;
}
