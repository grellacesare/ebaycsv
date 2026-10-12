import {apiUrl} from '../api';
import type {ImportSummary} from '../models';
import {Diagnostics} from './Common';
import {Download,TriangleAlert} from 'lucide-react';
export function ImportIssues({summary}:{summary:ImportSummary}){
 const rows=summary.diagnostics??[];
 const errors=summary.diagnostic_totals?.error??rows.filter(d=>d.level==='error').length,warnings=summary.diagnostic_totals?.warning??rows.filter(d=>d.level==='warning').length;
 const total=summary.diagnostic_totals?errors+warnings+summary.diagnostic_totals.info:rows.length;if(!total&&!rows.length)return null;
 const rank={error:0,warning:1,info:2};const visible=[...rows].sort((a,b)=>rank[a.level]-rank[b.level]).slice(0,10);
 return <section className="card import-issues"><div className="section-heading"><div><h3><TriangleAlert size={17}/> Segnalazioni dei file importati</h3><p className="muted">{errors} errori di origine · {warnings} avvisi. Le righe senza codice non diventano prodotti: correggi il file e reimportalo.</p></div><a className="button secondary" href={apiUrl('/imports/'+summary.id+'/report')} download><Download size={15}/>Scarica rapporto importazione</a></div><details open={errors>0}><summary>Mostra segnalazioni ({total})</summary><Diagnostics items={visible}/>{total>visible.length&&<p className="muted">{visible.length?'Mostrate le prime '+visible.length+' segnalazioni su '+total+'.':'Le singole segnalazioni non sono mostrate in questa pagina.'} Il rapporto contiene tutte le segnalazioni, con file, riga e correzione.</p>}</details></section>;
}
