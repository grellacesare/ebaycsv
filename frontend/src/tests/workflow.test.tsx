import { render,screen,fireEvent } from '@testing-library/react';
import { test,expect,vi } from 'vitest';
import { PricePreview } from '../components/PricePreview';
import { CompatibilityEditor } from '../components/CompatibilityEditor';
import { ProgressPanel } from '../components/ProgressPanel';
import { titleLength } from '../models';
test('shows_below_twenty_without_changing_price',()=>{
  const apply=vi.fn();
  const result={calculated_gross:'188.94',final_gross:'188.90',net_revenue:'154.836',commission:'18.89',profit:'30.946',margin:'.199863',minimum_gross:'189.90',below_minimum:true,diagnostics:[]};
  render(<PricePreview result={result} onUseMinimum={apply}/>);
  expect(screen.getByText(/sotto 20%/i)).toBeVisible();
  expect(screen.getByText('188,90 €')).toBeVisible();expect(apply).not.toHaveBeenCalled();
  fireEvent.click(screen.getByRole('button',{name:'Usa prezzo minimo'}));expect(apply).toHaveBeenCalledWith('189.90');
});
test('interrupted_job_has_explicit_resume_and_measured_counts',()=>{
  const resume=vi.fn();
  const job={id:'j',kind:'export',payload:{},request_key:'key',state:'interrupted' as const,progress:{stage:'Generazione CSV',completed:40,total:100,unit:'righe'},result:null,error:'Processo interrotto',created_at:'',updated_at:'',attempts:1};
  const {rerender}=render(<ProgressPanel job={job} onResume={resume}/>);
  fireEvent.click(screen.getByRole('button',{name:'Riprendi'}));expect(resume).toHaveBeenCalledOnce();
  rerender(<ProgressPanel job={{...job,state:'running',error:null}}/>);
  expect(screen.getByText('40 / 100 righe')).toBeVisible();
  expect(screen.getByRole('progressbar')).toHaveAttribute('value','40');
});
test('title_counter_counts_normalized_unicode_characters',()=>{
  expect(titleLength('e\u0301'.repeat(80))).toBe(80);
  expect(titleLength('🔧'.repeat(81))).toBe(81);
});
test('reordering_fitments_keeps_ids',()=>{
  let saved:unknown;
  const rows=[{id:'first',product_id:'p',make:'Honda',model:'CBR',displacement:'',year_from:null,year_to:null,vehicle_type:'moto' as const},{id:'second',product_id:'p',make:'Yamaha',model:'TMAX',displacement:'',year_from:null,year_to:null,vehicle_type:'scooter' as const}];
  render(<CompatibilityEditor productId="p" rows={rows} onSave={async (r)=>{saved=r;}}/>);
  fireEvent.click(screen.getByRole('button',{name:'Sposta TMAX su'}));
  fireEvent.click(screen.getByRole('button',{name:'Salva compatibilità'}));
  expect((saved as typeof rows).map(r=>r.id)).toEqual(['second','first']);
});
