from decimal import Decimal, InvalidOperation
import csv
import re
from uuid import uuid5, NAMESPACE_URL
from .models import InputSource, ImportProfile, ImportResult, FileBinding
from .csv_reader import inspect_csv, iter_rows
from ..domain import Product, Packaging, Diagnostic, ProgressCallback, Progress, SourceRef
from ..errors import DomainError

def parse_decimal(value: str, binding: FileBinding) -> Decimal:
    text = value.strip()
    if binding.thousands_separator:
        text = text.replace(binding.thousands_separator,'')
    if binding.decimal_separator == ',':
        if '.' in text:
            raise InvalidOperation('Separatore decimale non confermato')
        text = text.replace(',','.')
    if not re.fullmatch(r'[+-]?(?:\d+(?:\.\d*)?|\.\d+)',text):
        raise InvalidOperation('Importo non numerico')
    value = Decimal(text)
    if not value.is_finite() or value < 0:
        raise InvalidOperation('Valore non finito o negativo')
    return value

def issue(code, message, source=None, field=None, value=None, level='error', product_id=None):
    return Diagnostic(level=level,code=code,message=message,hint='Controlla il dato originale e correggi il campo o il profilo.',
        source=source,field=field,value=value,product_id=product_id)

def import_catalog(sources: list[InputSource], profile: ImportProfile, progress: ProgressCallback) -> ImportResult:
    bindings = {b.role:b for b in profile.bindings}
    if not sources or not any(s.role=='catalog' for s in sources):
        raise DomainError('catalog_required','Carica almeno un file di anagrafica.')
    if any(s.role not in bindings for s in sources):
        raise DomainError('profile_mismatch','Il profilo non definisce tutti i ruoli caricati.')
    inspections = {}
    for s in sources:
        b = bindings[s.role]
        view = inspect_csv(s.path,b.encoding,b.delimiter)
        if any(d.level=='error' for d in view.diagnostics):
            raise DomainError('invalid_file',f'{s.path.name}: '+view.diagnostics[0].message)
        missing = set(b.column_map.values()) - set(view.columns)
        if missing:
            raise DomainError('profile_mismatch',f'{s.path.name}: colonne mancanti: '+', '.join(sorted(missing)))
        inspections[s.id] = view
    products, diagnostics, counts = {}, [], {}
    seen_values: dict[tuple[str,str], set[str]] = {}
    duplicate_rows: dict[tuple[str,str], set[tuple]] = {}
    for source in sorted(sources,key=lambda s: ('catalog','prices','packaging').index(s.role)):
        binding = bindings[source.role]
        total = inspections[source.id].record_count
        stage = {'catalog':'Lettura catalogo','prices':'Collegamento prezzi','packaging':'Collegamento confezioni'}[source.role]
        progress(Progress(stage=stage,completed=0,total=total))
        counts[source.id] = 0
        try:
            for ref, raw in iter_rows(source.path,binding):
                counts[source.id] += 1
                if counts[source.id] % 100 == 0:
                    progress(Progress(stage=stage,completed=counts[source.id],total=total))
                if None in raw or any(v is None for v in raw.values()):
                    diagnostics.append(issue('malformed_row','Riga con colonne mancanti o in eccesso.',ref))
                    continue
                data = {key:raw.get(col,'').strip() for key,col in binding.column_map.items()}
                code = data['code']
                if not code:
                    diagnostics.append(issue('missing_code','Codice prodotto mancante.',ref,'code'))
                    continue
                if source.role=='catalog' and code not in products:
                    products[code] = Product(id=str(uuid5(NAMESPACE_URL,profile.supplier_id+'\0'+code)),supplier_id=profile.supplier_id,code=code)
                if code not in products:
                    diagnostics.append(issue('orphan_'+source.role,'Codice senza anagrafica: non è creato un prodotto.',ref,'code',code,level='warning'))
                    continue
                p = products[code]
                p.sources.append(ref)
                signature = tuple(sorted(ref.columns.items()))
                seen = duplicate_rows.setdefault((source.role,code),set())
                if signature in seen:
                    p.diagnostics.append(issue('identical_duplicate','Riga identica accorpata.',ref,level='info',product_id=p.id))
                    continue
                seen.add(signature)
                if source.role=='packaging':
                    pack = {'source':ref,'weight_unit':binding.weight_unit}
                    for field in ('quantity','gross_weight','volume'):
                        if data.get(field):
                            try:
                                pack[field] = parse_decimal(data[field],binding)
                            except InvalidOperation:
                                p.diagnostics.append(issue('invalid_'+field,'Dato confezione non interpretabile.',ref,field,data[field],level='warning',product_id=p.id))
                    p.packaging.append(Packaging(**pack))
                    continue
                for field in ('name','brand','family','department','ean','cost','image_urls','available'):
                    if not data.get(field):
                        continue
                    value = data[field]
                    prior = seen_values.setdefault((code,field),set())
                    prior.add(value)
                    if len(prior)>1:
                        p.diagnostics.append(issue('conflicting_'+field,'Valori discordanti per lo stesso codice.',ref,field,value,product_id=p.id))
                        if field in ('ean','cost','available'):
                            setattr(p,field,None)
                        continue
                    if field=='cost':
                        try:
                            p.cost = parse_decimal(value,binding)
                        except InvalidOperation:
                            p.diagnostics.append(issue('invalid_cost','Costo IVA esclusa non interpretabile.',ref,field,value,product_id=p.id))
                    elif field=='image_urls':
                        p.image_urls = [u.strip() for u in re.split(r'[\n|]',value) if u.strip()]
                    elif field=='available':
                        normalized = value.lower()
                        if normalized not in ('0','1','true','false','si','sì','no'):
                            p.diagnostics.append(issue('invalid_available','Disponibilità non riconosciuta come indicatore.',ref,field,value,level='warning',product_id=p.id))
                        else:
                            p.available = normalized in ('1','true','si','sì')
                    else:
                        setattr(p,field,value)
                for key,value in ref.columns.items():
                    if key not in binding.column_map.values():
                        p.extra.setdefault(key,value)
            progress(Progress(stage=stage,completed=counts[source.id],total=total))
        except (csv.Error, UnicodeError) as exc:
            raise DomainError('invalid_csv',f'{source.path.name}: {exc}') from exc
    for p in products.values():
        if p.cost is None and not any(d.code in ('invalid_cost','conflicting_cost') for d in p.diagnostics):
            p.diagnostics.append(issue('missing_cost','Costo d’acquisto IVA esclusa mancante.',product_id=p.id,field='cost'))
        if not p.packaging and any(s.role=='packaging' for s in sources):
            p.diagnostics.append(issue('missing_packaging','Dati peso/confezione mancanti.',level='warning',product_id=p.id))
        if any('qta' in k.lower() or 'quantita' in k.lower() for k in p.extra) and not all(b.packaging_basis_confirmed for b in profile.bindings if b.role!='packaging'):
            p.diagnostics.append(issue('unconfirmed_packaging_basis','Verifica minimi di vendita e base del costo: non sono quantità di stock.',level='warning',product_id=p.id))
    return ImportResult(products=list(products.values()),diagnostics=diagnostics,source_counts=counts)
