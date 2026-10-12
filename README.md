# Motor World CSV Studio

App personale in italiano per trasformare CSV di fornitori diversi in **nuove inserzioni eBay.it** oppure **nuovi prodotti PrestaShop 9.x**. I file si scaricano e si caricano manualmente sul negozio.

Importazione con anteprima e profili salvati, collegamento di anagrafica/prezzi/pesi tramite codice, editor delle compatibilità, template del builder Motor World, prezzi e margine verificati, controllo degli errori ed esportazione parziale. Ogni campo personalizzabile ha un aiuto «?» utilizzabile con mouse, tastiera o tocco.

## Installazione su motorworld.it tramite FTP

La [guida FTP](docs/ftp-hosting.md) spiega come installare la versione PHP
con accesso riservato su `https://motorworld.it/csvstudio/`. Il pacchetto pronto
si trova nelle [release](https://github.com/grellacesare/ebaycsv/releases).
Sul hosting servono PHP 8.1+, PDO SQLite/JSON, mbstring/iconv e una cartella
dati privata scrivibile; non servono Python, Node o Codespaces.

[![Apri con GitHub Codespaces](https://github.com/codespaces/badge.svg)](https://codespaces.new/grellacesare/ebaycsv)

Per usarla dal browser con accesso GitHub riservato, la [guida Codespaces](docs/codespaces.md)
descrive creazione, avvio automatico, archivio persistente e backup.

## Avvio

Requisiti: Python 3.12+, `uv`, Node 24 e npm. Dalla root del repository:

```bash
cd backend
uv sync --frozen
cd ../frontend
npm ci
cd ..
./scripts/dev.sh
```

Lo script avvia FastAPI sulla porta 8000 e Vite sulla 5173; `Ctrl+C` chiude entrambi. In cloud usa cache scrivibili sotto `var/cache`; se installi separatamente imposta `UV_CACHE_DIR` e `NPM_CONFIG_CACHE` a directory scrivibili. [Configurazione cloud](docs/cloud-setup.md) descrive i comandi verificati e la ripartenza dei processi.

Per eseguire la build con il solo backend:

```bash
cd frontend
npm run build
cd ../backend
uv run --frozen uvicorn motorworld_csv.api.app:create_app --factory --host 0.0.0.0 --port 8000
```

FastAPI serve la build dalla root. L’uso previsto è personale nell’ambiente privato dell’operatore.

## Prima lavorazione

1. Carica uno o più CSV. Verifica codifica, separatori e associazioni delle colonne. Scegli esplicitamente **una colonna del costo di acquisto IVA esclusa**: il sistema non seleziona un prezzo del fornitore al tuo posto.
2. Apri un prodotto e inserisci i modelli compatibili verificati da te. eBay genera un’inserzione per modello; PrestaShop un prodotto per codice, con tutti i modelli nella descrizione. Senza modelli resta una sola inserzione ordinaria.
3. Nei Prezzi scegli commissione, ricarichi, costo spedizione netto e quantità manuale comune. Nelle Impostazioni verifica policy, spedizione gratuita e, per PrestaShop, ID reale del gruppo regole IVA.
4. Controlla il lotto. Correggi titoli, categorie, dati discordanti e altri errori. Un avviso sul margine non blocca l’esportazione; «Usa prezzo minimo» è una scelta esplicita.
5. Esporta le valide, scarica CSV/ZIP/rapporto e carica manualmente sulla piattaforma. Correggi le escluse e ricontrolla: l’export successivo omette le già esportate, salvo selezione esplicita.

Il titolo eBay è limitato a 80 caratteri, senza taglio automatico. Il prezzo mantiene la parte intera e termina in `,90`: `188,94 → 188,90`. Il margine è il guadagno sul ricavo IVA esclusa, dopo costo prodotto, spedizione e commissione sul prezzo lordo. Sotto il 20% viene mostrato un avviso; il minimo suggerito è ricalcolato dopo l’arrotondamento.

Sopra **15.000.000 byte** il CSV viene diviso in esattamente due parti complete con intestazione. Se le due parti sono ancora troppo grandi viene mostrato un avviso; un singolo record oltre soglia richiede di ridurre la descrizione.

## Archivio e backup

Il percorso predefinito è `var/`, oppure `MW_DATA_DIR` se impostato. Comprende SQLite, upload originali, esportazioni e lavori. Non è versionato in Git. Arresta l’app prima di copiare **l’intera cartella dati** per il backup: conservare solo SQLite non basta a recuperare i CSV storici. Non eliminare i file originali o le esportazioni se vuoi riscaricarli.

Profili, correzioni, compatibilità e storico persistono al riavvio nella stessa cartella. Un lavoro interrotto viene segnalato e può essere ripreso. Le modifiche ai prodotti e alle impostazioni attendono la fine del lavoro in corso.

Una nuova importazione dello stesso fornitore/codice aggiorna i dati sorgente e richiede un nuovo controllo delle bozze interessate. Le correzioni manuali restano salvate separatamente: un costo non corretto manualmente segue il nuovo CSV; un costo corretto viene mantenuto con avviso. «Ripristina dati fornitore» rimuove le correzioni comuni e recupera l’ultimo CSV. Le segnalazioni dell’importazione, comprese le righe prive di codice che non diventano prodotti, sono visibili e scaricabili anche prima di generare le bozze; sono incluse pure nei rapporti di esportazione.

Il repertorio iniziale contiene 714 ID eBay estratti dal builder e 21 categorie del negozio PrestaShop allegato. Le categorie eBay vengono assegnate automaticamente soltanto nei casi riconosciuti e univoci Moto/Scooter; le altre richiedono una scelta manuale salvabile. Il repertorio non è una verifica in tempo reale della tassonomia eBay. Puoi sostituire i repertori nelle Impostazioni; le associazioni non più valide vengono segnalate.

[Guida caricamento eBay / PrestaShop](docs/import-platforms.md).

## Verifiche

La distribuzione PHP viene verificata nel runtime Apache/PHP 8.3, anche
senza Intl e ZipArchive. Per ricreare il pacchetto e provarlo via HTTPS:

```bash
python scripts/package_php_hosting.py
python hosting/php/tests/http_test.py --browser
```

Il secondo comando usa Docker e Chromium locali, installa lo ZIP in un
archivio temporaneo privato e controlla accesso, CSV ed interfaccia. Il
certificato locale di prova non modifica la verifica HTTPS del sito reale.

```bash
cd backend
uv run pytest -q
cd ../frontend
npm test -- --run
npm run build
npm run test:e2e
```

Il browser test usa Chromium del sistema (`/usr/bin/chromium`), oppure `MW_CHROMIUM_PATH`, e un archivio separato. Verifica upload, EAN discordanti, modelli, margine, titolo di 81 caratteri, CSV scaricati, esportazione parziale e successiva correzione. Questi controlli locali **non provano un’importazione effettiva su eBay o PrestaShop**.

Per verificare i tre allegati originali senza versionarli:

```bash
uv run --project backend python scripts/smoke_real_supplier.py \
  --anagrafica /percorso/M_AnagraficaPlus_002761.csv \
  --prezzi /percorso/M_PrezziQta_002761.csv \
  --pesi /percorso/M_Pesi_002761.csv \
  --cost-field PrezzoBase
```

`PrezzoBase` è una scelta esplicita della verifica, **non un’impostazione imposta alle lavorazioni**. Lo script controlla conteggi, EAN discordanti, prezzi orfani e conservazione delle confezioni, poi crea e riapre un archivio temporaneo. I modelli e il gruppo fiscale impiegati dallo smoke sono dati di test.

Tipi API generati: `cd frontend && npm run generate:types`. Le dipendenze sono bloccate in `backend/uv.lock` e `frontend/package-lock.json`.
