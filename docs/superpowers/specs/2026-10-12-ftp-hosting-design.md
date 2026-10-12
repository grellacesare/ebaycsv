# CSV Studio sul hosting FTP di motorworld.it

## Obiettivo e autorizzazione

L'utente vuole sostituire l'uso quotidiano di GitHub Codespaces con il suo
hosting DirectAdmin di Ryvex, dominio `motorworld.it`. Il risultato è
`https://motorworld.it/csvstudio/`, apribile da più dispositivi, con accesso
riservato e archivio sul server. Ha autorizzato tutti i passaggi necessari
al progetto. L'FTP distribuisce i file; il servizio applicativo usa PHP.

## Distribuzione

Il pacchetto comprende `csvstudio/`, da caricare sotto il webroot del dominio,
e una cartella dati `csvstudio-data/` accanto al webroot, fuori dalle directory
pubbliche reali. Non si considera `private_html` automaticamente privato.
Il programma rileva il prefisso URL dal proprio punto d'ingresso; funziona
sia sotto `/csvstudio/` sia sotto un sottodominio. Il pacchetto contiene il
frontend compilato, risorse del builder e dipendenze PHP già incluse.
Sul server non occorrono Node, Composer, Python, SSH o un daemon.

Requisiti: PHP 8.1 o successivo a 64 bit, PDO SQLite con funzioni JSON,
mbstring/iconv, HTTPS e cartella dati persistente scrivibile. Il controllo
iniziale prova i requisiti effettivi e mostra come correggere i limiti upload.
Intl e ZipArchive non sono obbligatori: normalizzazione Unicode e ZIP sono
distribuiti nel pacchetto. Non si afferma che il hosting sia compatibile
finché questo controllo non è stato eseguito su motorworld.it.

## Accesso e prima installazione

L'installatore genera un codice casuale in `csvstudio-data/installation-key.txt`,
fuori dal webroot. Chi installa lo legge tramite FTP/File Manager e lo inserisce
nel modulo insieme a nome utente e password. La sola visita alla pagina non
permette di creare l'amministratore. Il completamento è atomico, elimina il
codice iniziale e disabilita definitivamente la prima configurazione.
Il pacchetto pubblico non contiene codici di installazione, password o CSV.

Un account personale usa password_hash/password_verify, sessioni con cookie
Secure/HttpOnly/SameSite, rigenerazione dell'ID e protezione CSRF per scritture,
upload, avanzamento lavori e logout. Tentativi di login e installazione sono
limitati. Tutte le API, gli originali e gli export richiedono autenticazione.
La sessione si chiude prima delle elaborazioni per evitare richieste bloccate.
Errori pubblici non espongono credenziali, percorsi o trace PHP.

## Interfaccia e contratto

La stessa interfaccia React conserva le sei pagine e i tooltip `?`. Un piccolo
adattatore `window.MW_HOSTING` indica endpoint PHP, prefisso URL, CSRF e modalità
lavori. In sua assenza rimangono gli endpoint FastAPI esistenti. API, logo,
report e download usano lo stesso generatore URL. L'API PHP è
`api.php?path=/percorso`, senza dipendere da mod_rewrite. Il multipart PHP usa
`files[]`; l'API Python continua a usare `files`.

Le risposte mantengono i modelli TypeScript e i codici diagnostici esistenti.
Le scritture validano campi, destinazioni, decimali, intervalli e identità.
Il login è reso dal servizio PHP e non richiede un account GitHub.

## Lavori e persistenza

`POST /jobs/{id}/step` avanza un lavoro per un lotto limitato e restituisce il
modello Job corrente. `GET` resta sola lettura. Un lock esclusivo non bloccante
impedisce due avanzamenti simultanei; request_key univoca rende idempotenti le
richieste ripetute e rifiuta un intento differente con la stessa chiave.
Checkpoint, avanzamento e pubblicazione completata sono persistenti. I file
spool vengono troncati all'offset confermato prima di riprendere un append.
Gli esiti diventano visibili solo dopo la pubblicazione finale atomica.

Il browser invia richieste successive; chiudendolo il lavoro conserva il
checkpoint e riprende aprendo l'app, anche su un altro PC. L'interfaccia legge
anche i lavori attivi del server, senza dipendere soltanto dal localStorage.
Non sono necessari cron e non si promette elaborazione a browser chiuso.

SQLite conserva schema v2 e JSON dei record esistenti, con tabelle additive
per staging e checkpoint. Importazione, controllo ed esportazione usano
lettura progressiva e staging su disco/SQL invece di caricare interi cataloghi
in memoria PHP. Le correzioni manuali e le compatibilità rimangono legate alle
identità fornitore/codice. I percorsi degli export ripristinati si risolvono
nella cartella corrente, senza dipendere dal vecchio path del Codespace.

## Invarianti funzionali

Si preservano scelta esplicita del costo netto, join dei file per codice,
zeri iniziali, conflitti e provenienza, confezioni multiple, profili per
fornitore e correzioni successive. Prezzi e percentuali sono decimali esatti
con precisione significativa 28, mai float; commissioni sul lordo, margine sul
netto IVA, terminazione `floor(prezzo)+0,90`, avviso sotto 20% senza aumento
automatico. Compatibilità inserite dall'operatore, eBay per modello e PrestaShop
per prodotto. Titoli NFC di massimo 80 caratteri, nessun taglio automatico.

Le categorie riconosciute seguono il repertorio fornito e le precedenze
esistenti, comprese distinzioni Moto/Scooter e casi ambigui. Il builder rimane
HTML statico con testi escapati e URL pubblici HTTPS verificati. Sono preservate
le 55 intestazioni eBay e le 15 PrestaShop, esportazione parziale valida e
storico immutabile. CSV UTF-8 BOM, `;`, CRLF e quoting minimo equivalente a
Python. Soglia 15.000.000 byte con header/BOM; sopra soglia esattamente due
parti non vuote bilanciate sul confine dei record, prima posizione a parità.
Una riga enorme è segnalata e le parti ancora troppo grandi richiedono un
lotto minore. Nessuna pubblicazione automatica sulle piattaforme.

## Verifica e consegna

Test differenziali eseguono gli stessi casi su PHP e Python: prezzi, Unicode,
identità, categorie, importazione, bytes CSV e split. Test HTTP provano setup,
login, CSRF, isolamento dei file, upload, checkpoint, ripartenza, correzioni,
export parziale e download storico sotto il prefisso `/csvstudio/`.
Si prova il pacchetto finale in Apache/PHP isolato senza Python/Node sul server.
Le suite Python/React rimangono verdi. Il pacchetto pubblico è scaricabile
direttamente e non contiene dati personali; gli originali si caricano dopo il
login. La consegna distingue il pacchetto verificato dal sito effettivamente
installato sul hosting, che richiede upload FTP e controllo reale dei requisiti.
