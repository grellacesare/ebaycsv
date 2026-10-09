# Motor World CSV Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Realizzare un'app in italiano che trasforma cataloghi di fornitori diversi in nuovi prodotti PrestaShop 9.x o inserzioni eBay.it, con compatibilità manuali, prezzi verificabili, correzioni e download CSV.

**Architecture:** React/TypeScript presenta il workflow; FastAPI esegue importazioni ed esportazioni in background. SQLite conserva profili, prodotti, compatibilità, modifiche, diagnostica e storico; file originali e risultati restano in una directory locale esclusa da Git. La logica dei prezzi e dei formati è indipendente da API e interfaccia.

**Tech Stack:** Python 3.12, FastAPI, Pydantic, SQLite standard library, Decimal, Jinja2, pytest/httpx; Node 24, React, TypeScript, Vite, Vitest/Testing Library e Playwright. Dipendenze risolte durante lo sviluppo e bloccate in `uv.lock` e `frontend/package-lock.json`.

**Spec:** `docs/superpowers/specs/2026-10-09-motor-world-csv-design.md` — approvata dall'utente il 9 ottobre 2026.

## Global Constraints

- Destinazioni: eBay.it e PrestaShop 9.x; creazione di nuovi prodotti/inserzioni, download e caricamento manuale.
- Compatibilità inserite dall'utente nella tabella dell'app: una inserzione eBay per modello, un prodotto PrestaShop per codice; senza modelli una sola inserzione ordinaria.
- Titoli eBay: massimo 80 caratteri; niente troncamenti silenziosi.
- Costi di prodotto e spedizione IVA esclusa; costo spedizione e quantità unici per lavorazione; spedizione gratuita al cliente.
- Margine minimo 20% del ricavo netto IVA: avviso sotto soglia, aumento soltanto con «Usa prezzo minimo».
- Terminazione ,90 mantenendo direttamente la parte intera: 188,94 → 188,90 e 188,999 → 188,90.
- Categorie eBay automatiche soltanto con riconoscimento univoco Moto/Scooter; categorie PrestaShop già esistenti nel negozio.
- Template eBay Motor World dell'allegato; descrizione distinta per modello, HTML statico e immagini pubbliche HTTPS.
- Esportare le righe valide e conservare quelle errate modificabili; avvisi distinti dagli errori.
- Soglia 15 MB = 15.000.000 byte; sopra soglia esattamente due CSV con record integri e intestazioni, avviso se due parti non bastano.
- Un pulsante «?» accessibile vicino a ogni campo personalizzabile; avanzamento misurato e stati con testo.
- CSV PrestaShop: solo `Price tax included`, `Tax rule ID` reale, `Cost price` netto; ID prodotto omesso.
- Non versionare integralmente i listini commerciali; conservare originali senza modificarli; utilizzare il checkout esistente, senza worktree.

## Review Focus

1. CSV con BOM, codici con zeri iniziali, delimitatori nel testo e righe multilinea: nessuna perdita di valori o interpretazione silenziosa della codifica (Task 2).
2. EAN discordanti e modelli duplicati/riordinati: nessun dato scelto arbitrariamente, nessuna nuova inserzione accidentale, riferimenti stabili (Task 2 e 4).
3. Lo stesso ricambio su Moto e Scooter e repertori aggiornati: categoria controllata per variante, vecchie associazioni non più valide segnalate (Task 5).
4. Margine appena sotto 20% dopo ,90 e commissioni impossibili: avviso corretto, nessun aumento implicito, minimo matematicamente raggiungibile (Task 1).
5. Riavvio durante un lavoro o doppio clic su esportazione: niente risultato parziale dichiarato completo, storico recuperabile e una sola esportazione per richiesta (Task 3 e 8).

---

## Mappa dei file e contratti comuni

La root Python è `backend/src/motorworld_csv/`. `backend/tests/factories.py` offre `product(**changes)`, `vehicle(**changes)` e `price_settings(**changes)` per i test: codice `00123`, famiglia `Pastiglie Freno`, marca `Ferodo`; veicolo `Honda/CBR 600`, tipo `moto`; spedizione 5 €, ricarico prodotto 20%, spedizione 0%, commissione 10%, quota fissa 0, IVA 22%, quantità 1. Questi sono dati di test, non impostazioni confermate dell'utente.

| Area | File nuovi | Responsabilità |
| --- | --- | --- |
| Dominio | `domain.py`, `pricing.py`, `errors.py` | Tipi condivisi, prezzi decimali, errori applicativi |
| Importazione | `importing/models.py`, `importing/csv_reader.py`, `importing/service.py` | Profili, anteprima, lettura e collegamento file |
| Archivio/lavori | `storage.py`, `jobs.py` | Migrazioni SQLite, transazioni, progressi e ripartenza |
| Prodotti | `compatibility.py`, `titles.py` | Modelli manuali, riferimenti, titoli |
| Categorie | `categories/models.py`, `categories/catalog.py`, `categories/matching.py` | Repertori, contesti, associazioni salvate |
| Piattaforme | `platforms/profiles.py`, `platforms/descriptions.py`, `resources/*` | Impostazioni, template e risorse allegate |
| Controllo/export | `pipeline.py`, `exporting/contracts.py`, `exporting/writer.py`, `exporting/service.py` | Bozze, diagnostica, CSV, parti e storico |
| API | `api/app.py`, `api/schemas.py`, `api/routes/{imports,catalog,settings,exports,jobs}.py` | HTTP e composizione servizi |
| Interfaccia | `frontend/src/{App.tsx,api.ts,types.ts,styles.css}`, `components/*`, `pages/*` | Workflow e aiuti |
| Esercizio | `scripts/{dev.sh,smoke_real_supplier.py,extract_builder_resources.py}`, `README.md`, `docs/{cloud-setup.md,import-platforms.md}` | Avvio, verifiche e istruzioni |

Task 1 definisce i tipi Pydantic comuni. I Decimal viaggiano come stringhe JSON; gli ID sono stringhe UUID o chiavi stabili, mai numeri ricavati dai codici fornitore.

- `Product`: `id`, `supplier_id`, `code`, `name`, `brand`, `family`, `department`, `cost: Decimal | None`, `ean: str | None`, `image_urls: list[str]`, `available: bool | None`, `packaging: list[Packaging]`, `extra: dict[str, str]`, `sources: list[SourceRef]`, `diagnostics: list[Diagnostic]`.
- `Packaging`: `quantity: Decimal | None`, `gross_weight: Decimal | None`, `volume: Decimal | None`, `weight_unit: str | None`, `source: SourceRef`. `SourceRef`: file, riga, colonne originali.
- `Compatibility`: `id`, `product_id`, `make`, `model`, `displacement`, `year_from: int | None`, `year_to: int | None`, `vehicle_type: Literal['moto','scooter','other','unspecified']`.
- `Diagnostic`: `level: Literal['error','warning','info']`, `code`, `message`, `hint`, `product_id: str | None`, `listing_id: str | None`, `source: SourceRef | None`, `field: str | None`, `value: str | None`.
- `PriceSettings`: `shipping_cost`, `product_markup`, `shipping_markup`, `commission_rate`, `commission_fixed`, `vat_rate` come Decimal; `quantity: int`. Percentuali come frazioni, non numeri 20/22/10.
- `PriceResult`: `calculated_gross`, `final_gross`, `net_revenue`, `commission`, `profit`, `margin: Decimal`, `minimum_gross: Decimal | None`, `below_minimum: bool`, `diagnostics`.
- `ListingOverrides`: `listing_id`, `title: str | None`, `reference: str | None`, `gross_price: Decimal | None`, `category_id: str | None`; modifiche prodotto/risoluzioni conflitti restano separate dalle modifiche variante.
- `ListingDraft`: `id`, `product_id`, `compatibility_id: str | None`, `destination: Literal['ebay','prestashop']`, `reference`, `title`, `category_ids: list[str]`, `price: PriceResult | None`, `quantity`, `description_html`, `fields: dict[str,str]`, `diagnostics`, `valid: bool`.
- `Progress`: `stage`, `completed: int`, `total: int`, `unit`. `ProgressCallback = Callable[[Progress], None]`; nessun avanzamento inventato tramite timer.

## Task 1: Calcolo economico indipendente e verificabile

**Files:** Creare `backend/pyproject.toml`, `backend/uv.lock`, `backend/src/motorworld_csv/{__init__.py,domain.py,pricing.py,errors.py}`, `backend/tests/{factories.py,test_pricing.py}`, `.gitignore`.

**Interfaces:** Produce `calculate_price(cost: Decimal, settings: PriceSettings, override_gross: Decimal | None = None) -> PriceResult` e `end_in_90(value: Decimal) -> Decimal`. Gli errori di configurazione sono `DomainError(code: str, message: str)`; un minimo irraggiungibile è diagnostica distinta se il prezzo rimane calcolabile.

- [ ] Scrivere i test `test_rounding_keeps_original_euros`, `test_margin_uses_gross_commission_and_net_revenue`, `test_minimum_is_explicit`, `test_impossible_commission`:
  ```python
  assert end_in_90(Decimal('188.999')) == Decimal('188.90')
  p = calculate_price(Decimal('100'), price_settings(), Decimal('188.90'))
  assert p.below_minimum and Decimal('.19986') < p.margin < Decimal('.19987')
  assert p.final_gross == Decimal('188.90') and p.minimum_gross == Decimal('189.90')
  assert calculate_price(Decimal('100'), price_settings(), p.minimum_gross).margin >= Decimal('.20')
  ```
  Includere commissione 90% con IVA 22% (D≤0), commissione 70% (D>0 ma D−0,20≤0), quantità non positiva, costo negativo/non finito e ricarico che produce prezzo positivo ma margine insufficiente. Il calcolo senza override con i dati della factory deve dare 173,90 €, senza elevarlo al minimo 189,90 €.
- [ ] Eseguire `cd backend && uv run --with pytest pytest tests/test_pricing.py -q`: deve fallire per modulo/calcolo assente. Il bootstrap minimo del pacchetto appartiene a questa verifica.
- [ ] Implementare i tipi comuni e le due funzioni in `pricing.py` con Decimal, precisione locale 28 e formule della specifica. Applicare `floor(G)+0.90` prima di qualsiasi quantizzazione; minimo `floor(Gmin)+0.90`, più 1 se inferiore a Gmin, poi rivalutazione. Accettare un override monetario positivo applicando la stessa terminazione ,90, mantenere il suggerimento separato e il confronto non arrotondato. Escludere da Git `.venv`, `var`, upload, build e listini.
- [ ] Eseguire `cd backend && uv run pytest tests/test_pricing.py -q`: tutti i casi passano. Risolvere dipendenze in `uv.lock` e verificare `uv sync --frozen`.
- [ ] Committare i file del task: `git commit -m "feat: add decimal pricing and domain contracts"`.

## Task 2: Profili e collegamento CSV senza perdita di dati

**Files:** Creare `importing/{__init__.py,models.py,csv_reader.py,service.py}` e `backend/tests/test_importing.py`.

**Interfaces:** Consuma Product/Diagnostic/ProgressCallback. Produce `inspect_csv(path: Path, encoding: str | None = None, delimiter: str | None = None) -> CsvInspection`; `import_catalog(sources: list[InputSource], profile: ImportProfile, progress: ProgressCallback) -> ImportResult`. In `models.py`, CsvInspection contiene `columns: list[str]`, `preview: list[dict[str,str]]`, `record_count: int`, proposte e diagnostica. `InputSource` contiene `id: str`, `path: Path`, `role: Literal['catalog','prices','packaging']`. `ImportProfile` contiene ID/fornitore e `bindings: list[FileBinding]`; ogni FileBinding ha role, encoding, delimiter, decimal_separator e `column_map: dict[str,str]` da campo canonico a intestazione originale, chiave `code` obbligatoria, unità opzionali e base confezione confermata. Esattamente un binding mappa `cost`, scelto esplicitamente. `ImportResult` contiene `products: list[Product]`, `diagnostics: list[Diagnostic]` e `source_counts: dict[str,int]`.

- [ ] Scrivere `test_codes_and_multiline_round_trip`, `test_profile_requires_cost_choice`, `test_conflicting_ean_is_not_arbitrarily_selected`, `test_packaging_is_not_product_duplication` con file temporanei:
  ```python
  assert result.products[0].code == '00123'
  assert result.products[0].name == 'Filtro; "speciale"\nseconda riga'
  assert len(result.products[0].packaging) == 2
  assert any(d.code == 'conflicting_ean' for d in conflicted.products[0].diagnostics)
  ```
  Aggiungere UTF-8 BOM, CP1252 con accento che richiede scelta, costo `12,30` con separatore scelto, costo non finito, intestazioni duplicate, prezzo senza anagrafica, peso assente e Disponibile=0 senza convertirlo in quantità.
- [ ] Eseguire `cd backend && uv run pytest tests/test_importing.py -q`: fallimento perché l'importatore non esiste.
- [ ] Implementare le firme in `csv_reader.py` e `service.py` con `csv` standard, `newline=''`, codici stringa e normalizzazione dei soli nomi colonne. Anteprima e proposta non confermano il costo. Usare l'anagrafica come universo; conservare confezioni multiple, diagnosticare righe orfane/identiche/discordanti e origine esatta. Un conflitto conserva le alternative nelle sorgenti e resta bloccante fino alla correzione. Riportare progressi su record realmente letti; non inventare unità né significati delle soglie quantità. Esporre minimi vendita e fasce in extra con avviso se la base confezione non è confermata dal profilo.
- [ ] Eseguire `cd backend && uv run pytest tests/test_importing.py -q`: tutti i casi passano.
- [ ] Committare: `git commit -m "feat: import supplier CSVs with reusable mappings"`.

## Task 3: Archivio persistente e lavori recuperabili

**Files:** Creare `storage.py`, `jobs.py`, `backend/tests/{test_storage.py,test_jobs.py}`.

**Interfaces:** `Repository(db_path: Path)` espone `save_profile(profile: ImportProfile) -> str`, `get_profile(id: str) -> ImportProfile`, `save_import(id: str, result: ImportResult) -> None`, `get_product(id: str) -> Product`, `save_product(product: Product) -> None`, `list_products(import_id: str | None, filters: ProductFilters, offset: int, limit: int) -> ProductPage`, `save_compatibilities(product_id: str, rows: list[Compatibility]) -> None`, `get_compatibilities(product_id: str) -> list[Compatibility]`, `save_overrides(rows: list[ListingOverrides]) -> None`, `get_overrides(product_id: str) -> dict[str,ListingOverrides]`. In storage.py, ProductFilters contiene query e filtri opzionali available/category_id/state/destination; ProductPage contiene items e total. `JobService(repo: Repository, data_dir: Path)` espone `register(kind: str, handler: JobHandler)`, `submit(kind: str, payload: dict, request_key: str) -> Job`, `get(id: str) -> Job`, `resume(id: str) -> Job`, `close() -> None`; `JobHandler(payload: dict, progress: ProgressCallback) -> dict`. Job ha ID, kind, payload, `state: Literal['queued','running','completed','failed','interrupted']`, Progress, result, error e date.

- [ ] Scrivere `test_archive_survives_reopen`, `test_interrupted_job_can_resume` e `test_same_request_key_returns_same_job`:
  ```python
  assert reopened.get_product(saved.id).code == '00123'
  assert reopened.get_compatibilities(saved.id)[0].id == model.id
  assert service.submit('export', payload, 'request-1').id == first.id
  assert recovered.get(incomplete.id).state == 'interrupted'
  ```
- [ ] Eseguire `cd backend && uv run pytest tests/test_storage.py tests/test_jobs.py -q`: fallimento per archivio assente.
- [ ] Implementare Repository e JobService nelle firme indicate: SQLite WAL, migrazioni versionate, connessione per operazione/thread, chiavi prodotto stabili fornitore/codice, transazioni e Decimal come stringhe. Tabelle per impostazioni, upload, import, snapshot, compatibilità, override, regole categoria, bozze, lavori ed esportazioni; aggiungere metodi specifici nei task proprietari. Un worker locale usa thread e coda; al riavvio i running diventano interrupted, queued ripartono; resume mantiene request key ma crea un tentativo senza pubblicare risultati parziali. Gli originali restano immutati e gli output completati sono sostituiti atomicamente.
- [ ] Eseguire `cd backend && uv run pytest tests/test_storage.py tests/test_jobs.py -q`: riapertura, duplicazione richiesta e recupero passano.
- [ ] Committare: `git commit -m "feat: persist catalog and background job history"`.

## Task 4: Compatibilità manuali, riferimenti e titoli

**Files:** Creare `compatibility.py`, `titles.py`, `backend/tests/{test_compatibility.py,test_titles.py}`; modificare `storage.py` solo per le operazioni catalogo del task.

**Interfaces:** Consuma Product/Compatibility/Repository. Produce `validate_compatibilities(rows: list[Compatibility]) -> list[Diagnostic]`, `listing_identity(product: Product, compatibility: Compatibility | None, destination: str) -> str`, `propose_title(product: Product, compatibility: Compatibility | None) -> str`, `validate_title(title: str) -> list[Diagnostic]`, `validate_prestashop_reference(reference: str) -> list[Diagnostic]`. Le bozze del Task 7 usano queste identità.

- [ ] Scrivere `test_reordering_models_keeps_listing_identity`, `test_duplicate_model_requires_correction`, `test_title_limit_and_normalization`, `test_supplier_references_do_not_merge`:
  ```python
  assert listing_identity(p, a, 'ebay') == listing_identity(p, reordered[1], 'ebay')
  assert not validate_title('A' * 80)
  assert validate_title('A' * 81)[0].level == 'error'
  assert validate_prestashop_reference('abc;def')[0].level == 'error'
  ```
  Testare anni invertiti, ID modello persistiti, Unicode NFC, spazi ripetuti e riferimenti PrestaShop oltre 64 caratteri.
- [ ] Eseguire `cd backend && uv run pytest tests/test_compatibility.py tests/test_titles.py -q`: fallimento atteso.
- [ ] Implementare le firme in `compatibility.py`/`titles.py`. ID compatibilità UUID persistito, mai indice della tabella; duplicato semantico normalizzato diagnosticato. SKU eBay `MW-` più primi 24 caratteri SHA-256 di fornitore/codice/ID modello (o base), con verifica collisioni nell'archivio. Riferimento PrestaShop codice più prefisso configurabile e controllo collisioni fra fornitori; codice originale separato. Titolo NFC, spazi normalizzati, conteggio code point coerente Python/`Array.from` JS, nessun taglio automatico. Non dedurre modelli dai testi del fornitore.
- [ ] Eseguire i due file di test: tutti i casi passano.
- [ ] Committare: `git commit -m "feat: manage manual fitments and stable listing titles"`.

## Task 5: Repertori e categorie controllate per variante

**Files:** Creare `categories/{__init__.py,models.py,catalog.py,matching.py}`, `resources/{ebay_categories.json,prestashop_categories.json,category_rules.json,resource_manifest.json}`, `scripts/extract_builder_resources.py`, `backend/tests/test_categories.py`; estendere `storage.py` con `save_category_rule(rule: CategoryRule) -> None`, `list_category_rules(supplier_id: str) -> list[CategoryRule]`.

**Interfaces:** `CategoryCatalog` contiene i due repertori e versione/hash; `load_catalog(resources_dir: Path) -> CategoryCatalog`; `import_prestashop_categories(path: Path) -> list[StoreCategory]`; `resolve_category(product: Product, compatibility: Compatibility | None, destination: str, catalog: CategoryCatalog, rules: list[CategoryRule], override: str | None = None) -> CategoryDecision`. Decision contiene IDs, metodo, motivazione, diagnostica; Rule contiene fornitore, famiglia, contesto e destinazione. `validate_catalog_replacement(current: CategoryCatalog, replacement: CategoryCatalog, rules: list[CategoryRule]) -> list[Diagnostic]`.

- [ ] Scrivere `test_ambiguous_path_is_unresolved`, `test_moto_and_scooter_do_not_share_incompatible_override`, `test_saved_rule_is_reused`, `test_deleted_category_invalidates_rule`, `test_hidden_store_category_warns`:
  ```python
  assert ambiguous.category_ids == [] and ambiguous.diagnostics[0].level == 'error'
  assert moto.category_ids == ['177773']
  assert scooter.category_ids == ['82205']
  assert any(d.code == 'hidden_store_category' for d in hidden.diagnostics)
  ```
  Usare carburatori Moto/Scooter e filtro aria con contesto non specificato; verificare 714 ID eBay unici e 21 categorie negozio, tre nascoste.
- [ ] Eseguire `cd backend && uv run pytest tests/test_categories.py -q`: fallimento atteso.
- [ ] Implementare estrazione e catalogo leggendo gli allegati come dati, senza eseguire JS/PHP. Estrarre array JSON dal builder e CSV negozio, documentare SHA-256 e provenienza. Annotare solo contesti dimostrabili dalla tabella; seed conservativo per famiglie riconosciute, mai fuzzy match come certezza. Risoluzione: override valido nel contesto, regola salvata, corrispondenza univoca; percorsi duplicati/contesti incompatibili restano errori. Sostituzione repertorio invalida regole mancanti e forza ricontrollo bozze. Non presentare il dataset come tassonomia live verificata.
- [ ] Eseguire `cd backend && uv run pytest tests/test_categories.py -q`: passano anche contesti misti e aggiornamento repertorio.
- [ ] Committare: `git commit -m "feat: resolve marketplace categories with explicit ambiguity"`.

## Task 6: Profili piattaforma e descrizioni del builder

**Files:** Creare `platforms/{__init__.py,profiles.py,descriptions.py}`, `resources/{ebay_description.html.j2,ebay_template.css,ebay_headers.json,ebay_store_categories.json,motorworld-logo.png}`, `backend/tests/test_descriptions.py`; aggiornare estrattore/manifest e `storage.py` con `save_platform_profile(profile: PlatformProfile) -> None`, `get_platform_profile(destination: str) -> PlatformProfile`.

**Interfaces:** `PlatformProfile = EbayProfile | PrestashopProfile`. EbayProfile contiene condizioni, ubicazione, policy, resi, categoria negozio, logo pubblico e dati produttore; PrestashopProfile contiene gruppo regole IVA, prefisso riferimento e conferma configurazione spedizione. `validate_platform_profile(profile: PlatformProfile) -> list[Diagnostic]`; `render_description(product: Product, fitments: list[Compatibility], profile: PlatformProfile, title: str, reference: str, quantity: int) -> str`. Per eBay fitments ha zero o un elemento; PrestaShop include tutti.

- [ ] Scrivere `test_each_ebay_description_has_its_own_vehicle`, `test_supplier_text_is_escaped`, `test_return_policy_is_consistent`, `test_tax_rule_id_is_required`:
  ```python
  assert 'CBR 600' in first_html and 'TMAX' not in first_html
  assert '&lt;script&gt;' in escaped_html and '<script>' not in escaped_html
  assert '60 giorni' in html_for_60_days
  assert any(d.code == 'tax_rule_required' for d in validate_platform_profile(missing_tax))
  ```
  Verificare immagini HTTPS, logo locale assente dall'export, testo di marca alternativo, sezioni vuote rimosse, nessun OEM/MPN inventato e descrizione PrestaShop con due modelli.
- [ ] Eseguire `cd backend && uv run pytest tests/test_descriptions.py -q`: fallimento atteso.
- [ ] Implementare le firme: estrarre `template-style` e `preview-root`, trasformare in template Jinja2 con autoescape e testo/a capo controllati. Conservare identità e struttura Motor World; nessuno script, form o handler nell'HTML esportato. Rifiutare URL immagine non HTTPS o host non pubblico; non scaricarli dal backend. Resi nella descrizione derivati dal profilo CSV. Estrarre contratto eBay effettivo di 55 colonne, incluse Relationship/RelationshipDetails vuote per inserzioni normali, e 50 categorie negozio separate. Mostrare i default builder come modificabili; spedizione, commissione e gruppo IVA mancanti richiedono scelta esplicita.
- [ ] Eseguire i test descrizioni: tutti passano; validare template statico e manifest delle risorse.
- [ ] Committare: `git commit -m "feat: render Motor World descriptions and platform profiles"`.

## Task 7: Controllo righe ed esportazioni tracciabili

**Files:** Creare `pipeline.py`, `exporting/{__init__.py,contracts.py,writer.py,service.py}`, `backend/tests/{test_pipeline.py,test_exports.py}`; estendere `storage.py` con `save_drafts(import_id: str, drafts: list[ListingDraft]) -> None`, `list_drafts(import_id: str, destination: str, state: str | None, offset: int, limit: int) -> DraftPage`, `record_export(export: ExportRecord) -> None`, `get_export(id: str) -> ExportRecord`, `list_exports(import_id: str | None) -> list[ExportRecord]`.

**Interfaces:** `compile_drafts(product: Product, fitments: list[Compatibility], price_settings: PriceSettings, platform_profile: PlatformProfile, catalog: CategoryCatalog, rules: list[CategoryRule], overrides: dict[str,ListingOverrides]) -> list[ListingDraft]`. `csv_columns(destination: str) -> list[str]`; `draft_to_row(draft: ListingDraft, product: Product, profile: PlatformProfile) -> dict[str,str]`; `write_csv_parts(rows: Iterable[dict[str,str]], headers: list[str], output_dir: Path, threshold_bytes: int = 15_000_000) -> CsvParts`; `export_selected(repo: Repository, import_id: str, destination: str, draft_ids: list[str], output_dir: Path, progress: ProgressCallback) -> ExportRecord`. In contracts.py, CsvParts contiene percorsi, dimensioni, conteggi e avvisi; ExportRecord include ID, draft_ids inclusi/esclusi, snapshot, CSV, ZIP, rapporto e data. DraftPage contiene items, total e `counts: dict[str,int]` per pronti/errori/avvisi/esportate. Estendere filtri archivio del Task 3 con le bozze di questo task.

- [ ] Scrivere `test_ebay_expands_and_prestashop_does_not`, `test_only_invalid_variant_is_excluded`, `test_price_warning_does_not_raise_price`, `test_split_preserves_multiline_utf8_records`, `test_two_parts_over_limit_warn`:
  ```python
  assert len(ebay_drafts) == 2 and len(prestashop_drafts) == 1
  assert valid_draft.price.final_gross == Decimal('188.90')
  assert ps_row['Price tax included'] == '188.90' and 'Price tax excluded' not in ps_row
  assert ps_row['Cost price'] == '100.00' and 'ID' not in ps_row
  assert len(large_parts.paths) == 2
  assert decoded_records == original_records
  ```
  Testare 15.000.000 byte esatti (un file) e 15.000.001 con almeno due record (due file), BOM/header conteggiati, record singolo troppo grande (errore unsplittable_record), selezione senza validi (errore no_valid_rows), riga HTML multilinea, condizione eBay `1000`, Add Italia/EUR, quantità globale, rapporto scaricabile e correzione di una riga già esclusa.
- [ ] Eseguire `cd backend && uv run pytest tests/test_pipeline.py tests/test_exports.py -q`: fallimento atteso.
- [ ] Implementare pipeline e adapter usando solo i contratti dei task precedenti. Errori prodotto si propagano alle sue varianti; avvisi non bloccano. Generare CSV UTF-8 con BOM, `;`, CRLF e `csv.DictWriter`, senza appiattire dati multilinea. eBay usa header esatti estratti; PrestaShop usa sottoinsieme supportato dal controller 9.0.0 e solo ID categoria importati. Scrivere spool di record con lunghezze byte, scegliere confine contiguo che minimizza differenza delle due parti includendo entrambi gli header; nessun record spezzato. Se non esiste confine adeguato, due parti con avviso; un unico record sopra soglia impedisce una divisione valida: DomainError unsplittable_record e richiesta di ridurre la descrizione, nessuna parte vuota. Download comprende rapporto diagnostico e ZIP; marcatura righe esportate soltanto dopo chiusura atomica dei file.
- [ ] Eseguire i test pipeline/export: tutti passano; parsing indipendente conferma intestazioni, numero righe e prezzi.
- [ ] Committare: `git commit -m "feat: validate listings and export bounded CSV batches"`.

## Task 8: API completa per il workflow e lavori in background

**Files:** Creare `api/{__init__.py,app.py,schemas.py}`, `api/routes/{__init__.py,imports.py,catalog.py,settings.py,exports.py,jobs.py}`, `backend/tests/{conftest.py,test_api_workflow.py}`.

**Interfaces:** `create_app(data_dir: Path | None = None) -> FastAPI`. Prefisso `/api`; schema OpenAPI genera `frontend/src/types.ts` nel Task 9. Endpoint:

| HTTP | Percorso | Contratto |
| --- | --- | --- |
| GET | `/health` | Stato servizio e versione schema |
| POST | `/uploads` | Multipart CSV originali → ID, nomi, dimensioni, anteprime |
| GET/PUT | `/supplier-profiles` e `/supplier-profiles/{id}` | Elenco/salvataggio ImportProfile |
| POST | `/imports` | ID upload, profilo e request key → Job |
| GET | `/imports/{id}` e `/products` | Risultato lavorazione; paginazione/ricerca/filtri |
| PATCH | `/products/{id}` | Correzione dati e risoluzione esplicita conflitti |
| GET/PUT | `/products/{id}/compatibilities` | Tabella manuale con ID stabili |
| PUT | `/listing-overrides/{id}` | Modifiche singola bozza |
| GET/PUT | `/settings/{destination}` | PlatformProfile e PriceSettings |
| GET | `/categories` | Repertori per destinazione |
| GET/PUT | `/category-rules` | Associazioni salvate |
| POST | `/categories/{destination}/replace` | Nuovo repertorio verificato e invalidazione regole |
| POST | `/imports/{id}/validate` | Destinazione e impostazioni snapshot → Job |
| GET | `/imports/{id}/drafts` | Bozze filtrate/paginate, conteggi errori/avvisi/esportate |
| POST | `/exports` | Bozze selezionate e request key → Job |
| GET | `/exports` e `/exports/{id}` | Storico e metadati immutabili |
| GET | `/exports/{id}/files/{name}` | CSV/ZIP/rapporto dello storico |
| GET/POST | `/jobs/{id}` e `/jobs/{id}/resume` | Stato persistito e ripresa |

- [ ] Scrivere `test_manual_workflow_and_partial_export` con TestClient e directory temporanea: upload, conferma mapping/costo, importazione, due modelli, impostazioni, controllo, export valido, correzione escluso e secondo export. Scrivere `test_restart_keeps_export_download`, `test_duplicate_export_request_is_idempotent`, `test_download_rejects_unowned_path`:
  ```python
  assert response.status_code == 202
  assert completed['state'] == 'completed'
  assert first['id'] == duplicate['id']
  assert download_after_restart.content == original_download.content
  assert client.get('/api/exports/x/files/../../secret').status_code in (400,404)
  ```
- [ ] Eseguire `cd backend && uv run pytest tests/test_api_workflow.py -q`: fallimento perché app assente.
- [ ] Implementare create_app, route e schema espliciti, composizione Repository/JobService e handler import/validate/export. Lifespan chiude il worker; snapshot delle impostazioni impedisce cambiamenti a metà export. File caricati con ID generato e nome originale come metadato, percorsi derivati solo dall'archivio. Paginazione server (default 50, massimo 200); diagnostica JSON con codice/correzione e HTTP 422 per dati invalidi. Dopo modifica segnare bozze da ricontrollare; download storico non rigenera. Servire la build frontend da FastAPI, API same origin e proxy Vite in sviluppo.
- [ ] Eseguire `cd backend && uv run pytest tests/test_api_workflow.py -q`: workflow, riavvio e idempotenza passano.
- [ ] Committare: `git commit -m "feat: expose persistent CSV processing workflow API"`.

## Task 9: Interfaccia Motor World con controlli e aiuti

**Files:** Creare `frontend/{package.json,package-lock.json,tsconfig.json,vite.config.ts,index.html}`, `frontend/src/{main.tsx,App.tsx,api.ts,types.ts,styles.css}`, `frontend/src/components/{HelpField.tsx,ProgressPanel.tsx,StatusBadge.tsx,ProductTable.tsx,CompatibilityEditor.tsx,PricePreview.tsx}`, `frontend/src/pages/{ImportPage.tsx,ProductsPage.tsx,PricingPage.tsx,ReviewPage.tsx,ExportPage.tsx,SettingsPage.tsx}`, `frontend/src/tests/{workflow.test.tsx,help-field.test.tsx}`, `frontend/e2e/workflow.spec.ts` e configurazioni dei runner.

**Interfaces:** Consuma soltanto l'API del Task 8. `HelpField({id,label,help,children})` associa label e aiuto; `ProgressPanel({job})` mostra stage e unità misurate; `CompatibilityEditor({productId,rows,onSave})` mantiene ID; `PricePreview({result,onUseMinimum})` applica override solo su clic. `api.ts` centralizza errori, polling e upload via XMLHttpRequest per progressi byte effettivi; `types.ts` generato dallo schema OpenAPI con `openapi-typescript`, senza replicare formule nel browser.

- [ ] Scrivere `shows_help_on_hover_focus_and_click`, `shows_below_twenty_without_changing_price` e un percorso e2e `imports_edits_and_downloads_valid_csv`: costo/commissione/gruppo IVA esplicitati, due compatibilità, titolo di 81 caratteri escluso, una riga valida scaricata, errore corretto e nuovo download. Assertions:
  ```typescript
  expect(screen.getByRole('tooltip')).toHaveTextContent('IVA esclusa');
  expect(screen.getByText(/sotto 20%/i)).toBeVisible();
  expect(screen.getByText('188,90 €')).toBeVisible();
  await page.getByRole('button', {name: 'Usa prezzo minimo'}).click();
  await expect(page.getByText('189,90 €')).toBeVisible();
  ```
  Testare titolo NFC/contatore 80, conferma mapping prima dell'import, ID modelli durante riordino, stato interrotto con ripresa, aiuti da tastiera e CSV scaricato riletto.
- [ ] Eseguire `cd frontend && npm test -- --run`: fallimento prima dei componenti. Installare bootstrap dei test e bloccare le dipendenze come parte del task.
- [ ] Implementare i file indicati. Menu Importazione, Prodotti e compatibilità, Prezzi, Controllo, Esportazione, Impostazioni; logo e palette Motor World, responsive, focus visibile e stato con icona/testo. Ogni campo personalizzabile usa HelpField con effetto ed esempio. Anteprima HTML eBay in iframe sandbox senza script, tabella paginata e filtri disponibili/errori/avvisi/esportate; niente render di 17.000 righe. Mostrare prezzi, margine, minimo, dimensioni parti, riepilogo selezione e storico; distinguere sempre esportato da pubblicato. Le modifiche persistono via API; campi manuali obbligatori non vengono compilati con dati inventati.
- [ ] Eseguire `cd frontend && npm test -- --run && npm run build` e `npm run test:e2e` con API temporanea. Devono passare test, TypeScript, build e browser workflow; verificare visivamente desktop/mobile e screenshot locali. Se browser runtime richiede rete aggiuntiva, configurare soltanto i domini d'installazione necessari e riportare un blocco reale senza dichiarare il controllo riuscito.
- [ ] Committare: `git commit -m "feat: add Motor World CSV workspace interface"`.

## Task 10: Campioni reali, istruzioni e ambiente cloud riutilizzabile

**Files:** Creare `scripts/{dev.sh,smoke_real_supplier.py}`, `README.md`, `docs/{cloud-setup.md,import-platforms.md}`; aggiornare solo i file necessari dopo le verifiche. Gli allegati restano fuori Git.

**Interfaces:** `scripts/dev.sh` esegue backend `uv run uvicorn motorworld_csv.api.app:create_app --factory --host 0.0.0.0 --port 8000` e Vite `--host 0.0.0.0`; chiude entrambi con trap. `smoke_real_supplier.py --anagrafica PATH --prezzi PATH --pesi PATH --cost-field PrezzoBase` usa esplicitamente PrezzoBase come scelta di verifica, non default imposto agli utenti. Stampa conteggi e diagnostica aggregata, senza riversare il listino.

- [ ] Aggiungere la verifica integrata sui tre allegati reali con associazioni esplicite: 17.184 righe anagrafica, 17.173 codici, 11 conflitti EAN, 430 prezzi orfani, due codici senza pesi e 5.437 codici con confezioni multiple nel file pesi originale. Verificare separatamente quest'ultimo conteggio e la conservazione di tutte le confezioni per i codici presenti nell'anagrafica. Verificare un prodotto con compatibilità manuali, le due destinazioni e un conflitto corretto; creare un archivio temporaneo eliminabile. Il comando deve fallire se i conteggi o la conservazione dei dati cambiano.
- [ ] Eseguire lo smoke sui percorsi degli allegati in `/workspace/attachments`, poi `cd backend && uv run pytest -q` e `cd frontend && npm test -- --run && npm run build && npm run test:e2e`. Risolvere difetti effettivi, ripetendo soltanto verifiche coinvolte.
- [ ] Scrivere README e guide: installazione bloccata, cartella dati/backup, avvio/stop, limiti dei repertori forniti, CSV eBay manuale, PrestaShop 9 associazione colonne/salto intestazione/gruppo IVA/ID categorie, match reference e force IDs disattivati per nuovi prodotti, importare ciascun lotto una sola volta e configurare realmente spedizione gratuita nel negozio. Precisare che i test locali non provano un'importazione effettiva sulle piattaforme.
- [ ] Provare installazione `cd backend && uv sync --frozen` e `cd frontend && npm ci`, avvio con `scripts/dev.sh`, `/api/health`, pagina e download campione. Salvare nel draft cloud soltanto install_script/start_skill realmente provati, mantenendo repository e rete esistenti; documentare necessità di pubblicazione del draft e ripartenza processi. Non presentare loopback come anteprima pubblica e non dichiarare persistenza in nuovi task senza verificarla.
- [ ] Committare guide e smoke: `git commit -m "docs: verify real supplier workflow and cloud setup"`.

## Verifica finale e consegna

- [ ] Far controllare l'intera implementazione da un reviewer con contesto nuovo rispetto a specifica e piano; correggere problemi materiali con verifiche mirate.
- [ ] Verificare `git status --short`, test backend, build frontend e workflow browser già eseguiti dopo l'ultima modifica pertinente; nessun listino/upload/archivio nel diff.
- [ ] Consegnare comportamento realizzato, risultati delle verifiche, percorso istruzioni di avvio e limiti concreti rimasti. La pubblicazione del sito e il caricamento su eBay/PrestaShop restano fuori dal task; nessun test viene descritto come importazione reale.

## Autorevisione del piano

Copertura verificata rispetto a tutte le sezioni della specifica: profili/import Task 2, archivio/lavori Task 3, compatibilità/titoli Task 4, categorie Task 5, prezzi Task 1, builder/profili Task 6, errori/export/storico Task 7–8, interfaccia/aiuti Task 9, campioni/ambiente Task 10. I cinque punti Review Focus hanno test nei task indicati; contratti e tipi condivisi sono definiti prima del consumo.

Un CSV composto da un unico record sopra 15 MB non può essere diviso in due record completi: la generazione si ferma con una spiegazione e richiesta di ridurre quella descrizione. Con almeno due record si producono esattamente due parti, con avviso se ancora troppo grandi.
