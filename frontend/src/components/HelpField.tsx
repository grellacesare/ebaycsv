import { useState,type ReactNode } from 'react';
export function HelpField({id,label,help,children}:{id:string,label:string,help:string,children:ReactNode}){
  const[open,setOpen]=useState(false),[pinned,setPinned]=useState(false);
  return <div className="field"><div className="field-label"><label htmlFor={id}>{label}</label><span className="help-anchor"><button type="button" className="help" aria-label={'Aiuto: '+label} aria-expanded={open} aria-controls={id+'-help'} onMouseEnter={()=>setOpen(true)} onMouseLeave={()=>{if(!pinned)setOpen(false);}} onFocus={()=>setOpen(true)} onBlur={()=>{if(!pinned)setOpen(false);}} onClick={()=>{setPinned(!pinned);setOpen(!pinned);}} onKeyDown={e=>{if(e.key==='Escape'){setPinned(false);setOpen(false);}}}>?</button>{open&&<span className="tooltip" role="tooltip" id={id+'-help'}>{help}</span>}</span></div>{children}</div>;
}
