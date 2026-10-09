# Ambiente cloud Codex

Usare il checkout esistente `/workspace/ebaycsv`, senza creare Git worktree salvo richiesta esplicita. Sorgenti e lockfile costituiscono l’app; lo script di installazione deve installare le dipendenze, senza riscriverli.

## Installazione ripetibile

```bash
set -e
export UV_CACHE_DIR=/workspace/.cache/uv
export NPM_CONFIG_CACHE=/workspace/.cache/npm
mkdir -p "$UV_CACHE_DIR" "$NPM_CONFIG_CACHE"
cd /workspace/ebaycsv/backend
uv sync --frozen
cd /workspace/ebaycsv/frontend
npm ci
npm run build
```

Python 3.12, uv, Node 24, npm e Chromium di sistema sono disponibili nell’ambiente verificato. Le cache esplicite evitano il percorso utente non scrivibile. Non sono necessari segreti eBay/PrestaShop: l’app prepara file per caricamento manuale.

## Avvio e readiness

```bash
cd /workspace/ebaycsv
./scripts/dev.sh
```

La cartella dati predefinita è `/workspace/ebaycsv/var`; `MW_DATA_DIR` consente un archivio distinto. Il processo backend usa 8000, Vite 5173 con proxy `/api`. Verificare internamente `GET /api/health` sul backend (`status: ok`), la pagina frontend e il download di un export campione. Non presentare all’utente indirizzi loopback come un’anteprima pubblica.

`Ctrl+C` termina entrambi i processi avviati dallo script. Le dipendenze possono essere presenti in un ambiente ripristinato; **i processi devono essere riavviati**. Conservare SQLite e file archivio insieme. Al riavvio, lavori già in esecuzione vengono marcati interrotti e possono essere ripresi esplicitamente.

## Bozza configurazione

L’installazione e queste istruzioni di avvio vengono salvate nella bozza ambiente con gli strumenti di onboarding. Il salvataggio della bozza non esegue gli script, non applica la configurazione e non la pubblica. Per renderla riutilizzabile, rivedere/salvare la configurazione nelle impostazioni dell’ambiente e pubblicarla quando richiesto dall’interfaccia.

Le prove svolte riguardano questo checkout: installazione con lockfile, avvio/stop/riavvio, controlli HTTP, test backend/UI/browser e listini originali. Il ripristino in un nuovo task cloud e l’importazione effettiva nei negozi non sono stati verificati. Le modifiche applicative sono commit locali nel ramo di lavoro; la bozza non li invia automaticamente al repository remoto.
