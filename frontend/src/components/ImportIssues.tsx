import type {ImportSummary} from '../models';
import {Diagnostics} from './Common';
import {Download,TriangleAlert} from 'lucide-react';
export function ImportIssues({summary}:{summary:ImportSummary}){
 const rows=summary.diagnostics??[];if(!rows.length)return null;
 const errors=rows.filter(d=>d.level==='error').length,warnings=rows.filter(d=>d.level==='warning').length;
 const rank={error:0,warning:1,info:2};const visible=[...rows].sort((a,b)=>rank[a.level]-rank[b.level]).slice(0,10);
 return <section className="card import-issues"><div className="section-heading"><div><h3><TriangleAlert size={17}/> Segnalazioni dei file importati</h3><p className="muted">{errors} errori di origine · {warnings} avvisi. Le righe senza codice non diventano prodotti: correggi il file e reimportalo.</p></div><a className="button secondary" href={'/api/imports/'+summary.id+'/report'} download><Download size={15}/>Scarica rapporto importazione</a></div><details open={errors>0}><summary>Mostra segnalazioni ({rows.length})</summary><Diagnostics items={visible}/>{rows.length>10&&<p className="muted">Mostrate le prime 10. Il rapporto contiene tutte le segnalazioni, con file, riga e correzione.</p>}</details></section>;
}
