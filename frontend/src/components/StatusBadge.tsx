import {Check,TriangleAlert,Download,RotateCcw} from 'lucide-react';
import type {Draft} from '../models';
export function StatusBadge({draft}:{draft:Draft}){if(draft.stale)return <span className="badge warning"><RotateCcw size={13}/>Da ricontrollare</span>;if(!draft.valid)return <span className="badge error"><TriangleAlert size={13}/>Da correggere</span>;if(draft.exported)return <span className="badge neutral"><Download size={13}/>Esportato</span>;return <span className="badge success"><Check size={13}/>Pronto</span>;}
