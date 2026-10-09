# CSV Studio dal browser con GitHub Codespaces

La configurazione installa Python 3.12, uv e Node 24, compila l'interfaccia e
avvia il programma automaticamente. Si usa un solo processo sulla porta 8000.
L'accesso riservato è fornito dalla porta **Private** di GitHub Codespaces:
l'app è personale e non richiede un account eBay o PrestaShop.

## Primo avvio

1. Nel repository `grellacesare/ebaycsv` apri **Code → Codespaces → Create
   codespace on main**. In alternativa usa [Nuovo Codespace](https://codespaces.new/grellacesare/ebaycsv).
2. Attendi l'installazione iniziale. Il programma si avvia e la porta 8000
   viene inoltrata. Se la pagina non si apre, nella scheda **Ports / Porte**
   clicca il collegamento della porta 8000 o **Open in Browser**.
3. Controlla che **Port Visibility / Visibilità** sia **Private**. Conserva
   questa impostazione: tutte le pagine, API e download devono richiedere
   l'accesso al tuo GitHub. L'app non ha un login separato e non va resa pubblica.

## Uso quotidiano

Apri il tuo ambiente da [GitHub Codespaces](https://github.com/codespaces).
Il programma riparte automaticamente. Apri il link della porta 8000 e lavora
con CSV, compatibilità, prezzi ed esportazioni dall'interfaccia.

Un Codespace sospeso va riaperto prima di usare il link. Non è un servizio
sempre attivo: sospensione, disponibilità e costi dipendono dalle quote e dalle
impostazioni del tuo account. Non attivare opzioni di pagamento se vuoi restare
entro l'utilizzo incluso; GitHub deve confermare che la creazione sia consentita.

L'archivio è in `var/archive` dentro il workspace persistente. Conserva dati,
CSV originali e storico tra arresti e ricostruzioni dello stesso Codespace.
Non viene pubblicato nel repository. **Eliminare il Codespace elimina anche
l'archivio**; GitHub può inoltre cancellare ambienti rimasti inattivi oltre
il periodo di conservazione configurato. Mantieni un backup scaricato.

## Backup completo

Attendi la fine della lavorazione, poi nel terminale di Codespaces esegui:

```bash
bash .devcontainer/backup.sh
```

Il comando arresta il programma, copia l'intero archivio in uno ZIP sotto
`var/backups` e riavvia il programma. Nel pannello file apri quella cartella,
clicca lo ZIP con il tasto destro e scegli **Download**. Conserva la copia sul
tuo dispositivo: una copia rimasta solo nel Codespace non protegge dalla sua
eliminazione. Lo ZIP contiene dati privati e non va inserito in Git.

Per ripristinare un backup, arresta il programma con `bash .devcontainer/stop.sh`, estrai la
cartella `archive` dello ZIP in `var` senza sovrascrivere un archivio esistente
che vuoi conservare, quindi esegui `bash .devcontainer/start.sh`. Richiedi assistenza per un
ripristino se hai dati già presenti da mantenere.

## Diagnosi dell'avvio

```bash
bash .devcontainer/install.sh
bash .devcontainer/start.sh
```

Il secondo comando riconosce un'istanza già avviata e non crea un secondo
worker. I log sono in `var/runtime/codespaces-8000.log`. Un errore di avvio
viene segnalato; non rimuovere il database per risolverlo.

La configurazione usa le dipendenze bloccate dell'app. La prova locale degli
script non dimostra che un Codespace sia stato creato: GitHub deve consentire
la creazione, terminare il primo avvio e confermare la porta privata.
