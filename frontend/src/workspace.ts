import type {Destination,CategoryCatalog,Job} from './models';
export type PageName='import'|'products'|'pricing'|'review'|'exports'|'settings';
export type WorkbenchProps={importId:string,destination:Destination,refresh:number,busy:boolean,catalog:CategoryCatalog|null,onError:(error:unknown)=>void,onRefresh:()=>void,runJob:(path:string,body:unknown)=>Promise<Job|undefined>,navigate:(page:PageName)=>void};
