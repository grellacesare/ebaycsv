# CSV Studio sul hosting FTP di motorworld.it

Il pacchetto `motorworld-csvstudio-ftp.zip` prepara l'app per
`https://motorworld.it/csvstudio/`. Comprende PHP, interfaccia compilata,
risorse e dipendenze: sul server non servono Python, Node, Composer, SSH o
Codespaces. L'installazione sul hosting richiede i passaggi seguenti e il
controllo dei requisiti effettivi; il pacchetto da solo non pubblica il sito.

## Caricamento dei file

1. Estrai lo ZIP sul PC. Carica **soltanto `csvstudio/`** nella cartella
   pubblica del dominio, normalmente `public_html/`. L'ingresso sarà
   `public_html/csvstudio/index.php`. Non caricare lo ZIP nel sito pubblico.
   Includi anche i file nascosti `.htaccess` e `.user.ini` presenti nel
   pacchetto; nel programma FTP abilita la visualizzazione dei file nascosti.
   La collocazione prevista è nel webroot del dominio principale
   `motorworld.it`, nella sottocartella `csvstudio/`.
2. Carica la cartella vuota **`csvstudio-data/` accanto al webroot**, fuori
   dalle cartelle pubbliche reali: se il webroot è `public_html`, le due
   cartelle sono `public_html/csvstudio/` e `csvstudio-data/` sullo stesso
   livello superiore. `private_html` può essere la cartella pubblica HTTPS
   e non va considerata automaticamente un archivio privato.
3. La cartella dati deve essere scrivibile dal PHP del tuo account. Mantieni
   accesso riservato al proprietario; non impostare permessi `777`. Se l'FTP
   non permette di raggiungere il livello esterno o PHP non può scriverci,
   risolvi quel limite nel pannello prima di caricare dati personali.

Per aggiornare il programma sostituisci soltanto `csvstudio/`, conservando
`csvstudio-data/` e un backup completo. Il pacchetto pubblico non contiene
archivi, listini, password o chiavi di installazione.

## Prima installazione e accesso

1. Apri `https://motorworld.it/csvstudio/setup.php`. Il controllo prova PHP
   8.1+ a 64 bit, SQLite/PDO con funzioni JSON, mbstring/iconv, HTTPS e la
   cartella dati. Intl e ZipArchive non sono obbligatori.
2. La prima visita genera `csvstudio-data/installation-key.txt`, fuori dal
   sito pubblico. Leggi il codice dal tuo FTP o File Manager e inseriscilo
   nel modulo insieme al nome utente e alla password che scegli. Non
   pubblicare il codice e non inserirlo nel repository.
3. Terminata la configurazione, il codice iniziale viene eliminato e il
   primo installatore viene disabilitato. Apri
   `https://motorworld.it/csvstudio/` e accedi con le tue credenziali. **Esci**
   chiude la sessione; non occorre un account eBay, PrestaShop o GitHub.

La sola apertura della pagina di installazione non permette a un visitatore
di creare il tuo account: è necessario leggere la chiave privata via FTP.
Se un controllo non passa, conserva il messaggio del controllo e correggi
il requisito indicato; non spostare l'archivio nella cartella pubblica.

## Uso quotidiano e archivio

Apri il link dell'app dal PC o dal telefono. Profili, file originali,
compatibilità, modifiche e CSV esportati restano sul hosting. Puoi riprendere
la lavorazione da un altro dispositivo dopo il login.

Durante importazione, controllo ed esportazione lascia aperta la pagina:
il browser avanza il lavoro mediante richieste brevi. Chiudendola il
checkpoint resta salvato; riaprendola il lavoro attivo continua dal punto
confermato, anche su un altro dispositivo. Attendi il completamento prima
di avviare un lavoro diverso o modificare dati e impostazioni: il programma
blocca queste operazioni mentre una lavorazione è attiva. Non serve un
processo sempre acceso e non è prevista elaborazione a browser chiuso.

Se un controllo o un'esportazione termina con errore, correggi la causa e
premi **Riprendi**: questa azione esplicita riavvia quel lavoro dall'inizio
con i dati e le impostazioni correnti. La semplice chiusura del browser
durante un lavoro attivo, invece, conserva il checkpoint senza riavviarlo.

Il limite di **15.000.000 byte riguarda ogni CSV esportato**, comprese
intestazione e BOM. Oltre soglia vengono prodotte esattamente due parti:
se una parte è ancora troppo grande, seleziona un lotto minore. Il file ZIP
raccoglie i CSV e il rapporto; non cambia i limiti di caricamento su eBay.
La [guida alle importazioni](import-platforms.md) spiega il caricamento
manuale su eBay.it e PrestaShop 9.

Il limite upload del hosting è distinto. Il pacchetto richiede **64 MiB per
file**, **192 MiB per richiesta** e **256 MiB di memoria PHP** tramite
`.user.ini` e, per Apache con PHP come modulo, `.htaccess`. Il hosting può
ignorare o limitare questi valori; il controllo iniziale mostra quelli
effettivi. Contano anche lo spazio temporaneo PHP e il limite del numero di
file nella stessa richiesta. Se i valori sono inferiori, correggili nel
pannello quando consentito o carica file/lotti più piccoli. Non eliminare
gli originali o gli export se vuoi riscaricarli dallo storico.

L'anteprima di un file mostra fino alle prime 100 avvertenze. Anche l'elenco
delle segnalazioni dopo l'importazione può essere abbreviato per mantenere
la pagina e il servizio leggeri; i conteggi restano completi. Il rapporto
CSV scaricabile dell'importazione contiene tutte le segnalazioni, con file,
riga e correzione suggerita.

## Backup e conservazione

Conserva una copia dell'**intera `csvstudio-data/`**, inclusi database,
originali ed esportazioni. Attendi il completamento dei lavori ed evita
scritture durante il backup. SQLite può usare anche file `-wal` e `-shm`:
la sola copia di `archive.sqlite` mentre l'app scrive non garantisce un
backup consistente. Preferisci un backup completo/coerente del pannello;
per una copia manuale arresta l'uso dell'app da tutti i dispositivi e
conserva l'intera cartella. Proteggi il backup perché contiene anche
informazioni di accesso e dati personali.

Un archivio proveniente da Codespaces/Python richiede tutti gli originali e
gli export, oltre a SQLite. Non sovrascrivere l'archivio del hosting già
inizializzato senza una copia e una migrazione verificata.

## Preparazione del pacchetto da parte dello sviluppatore

```bash
python scripts/package_php_hosting.py
```

Il comando compila un frontend separato con asset relativi, conserva il
normale `frontend/dist` e crea
`var/deployment/motorworld-csvstudio-ftp.zip`, con manifest e checksum SHA-256.
Include soltanto il runtime PHP, le librerie con le licenze, le risorse del
builder e gli asset compilati. Non contiene sorgenti Python, dipendenze di
sviluppo, cartelle dati o credenziali.
