# Pacchetti FTP pronti

I pacchetti versionati contengono esclusivamente codice PHP, interfaccia
compilata, risorse pubbliche e istruzioni. Non includono archivi personali,
listini dei fornitori, credenziali o chiavi di installazione.

Il pacchetto `ftp-v1.0.0/motorworld-csvstudio-ftp.zip` è stato installato e
verificato via HTTPS su Apache/PHP 8.3, anche nel browser Chromium. Il file
`.sha256` consente di verificarne l'integrità. La guida per il caricamento
su motorworld.it si trova in [docs/ftp-hosting.md](../docs/ftp-hosting.md).

Per produrre una nuova versione usare `scripts/package_php_hosting.py`,
verificare lo stesso ZIP con `hosting/php/tests/http_test.py --browser` e
creare una nuova directory di versione. Conservare intatti i pacchetti
già rilasciati; la cartella dati reale del hosting non va inclusa.
