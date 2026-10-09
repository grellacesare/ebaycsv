# Motor World — conversione CSV per eBay Italia e PrestaShop 9

Data: 9 ottobre 2026. Documento per la revisione dell'utente prima del piano di implementazione.

## Obiettivo e uso

Applicazione web personale per trasformare i file dei fornitori in CSV di nuovi prodotti o nuove inserzioni. L'utente carica manualmente i CSV risultanti su eBay.it o PrestaShop 9.x. Le impostazioni, le associazioni, le compatibilità e le lavorazioni restano salvate nell'archivio dell'applicazione.

Su eBay viene generata un'inserzione distinta per ciascun modello compatibile inserito dall'utente. Senza compatibilità inserite, rimane una singola inserzione del prodotto, senza aggiungere dichiarazioni di compatibilità. Su PrestaShop viene generato un solo prodotto per codice, con l'elenco completo delle compatibilità nella descrizione.

La prima versione è per un operatore nell'ambiente privato: non comprende account per collaboratori, pubblicazione diretta sulle piattaforme o aggiornamenti di prodotti già pubblicati.

## Dati forniti e riferimenti

I campioni del fornitore sono UTF-8, separati da punto e virgola:

| File | Righe di dati | Ruolo |
| --- | ---: | --- |
| M_AnagraficaPlus_002761.csv | 17.184 | Prodotti, marca, descrizioni, categorie, EAN, immagini e prezzi |
| M_PrezziQta_002761.csv | 17.603 | Prezzi e quantità, da interpretare attraverso il profilo del fornitore |
| M_Pesi_002761.csv | 23.455 | Pesi e confezioni, con più confezioni per codice |

La chiave comune è CodiceProdotto, scritto codiceProdotto nel file dei pesi. L'anagrafica contiene 17.173 codici distinti: 11 codici hanno EAN discordanti. Tutti i codici dell'anagrafica hanno una riga nel listino prezzi; 430 codici del listino non hanno anagrafica. Non si creano prodotti privi di anagrafica solo perché compaiono nel listino.

Il builder allegato contiene il template Motor World, le colonne CSV eBay e una tabella di 714 categorie con ID e percorso. Quaranta percorsi hanno due ID. La tabella deriva da Excel secondo il commento nel builder; non contiene data, versione ufficiale o metadati che attestino l'attualità degli ID.

Il file category_2026-10-09_120457.csv contiene 21 categorie PrestaShop, senza gerarchia dei figli. Si utilizzano queste categorie esistenti con i loro ID. Gadget e Merchandising, Prodotti generici e Ricambi Monopattino risultano nascoste: la loro selezione genera un avviso.

Il contratto del CSV PrestaShop è verificato sul controller ufficiale 9.0.0. L'esatta versione minore del negozio non è fornita; eventuali personalizzazioni del suo importatore non sono verificate.

## Interfaccia e archivio

Interfaccia in italiano con identità Motor World, menu laterale e schermate Importazione, Prodotti e compatibilità, Prezzi, Controllo, Esportazione e Impostazioni. La tabella dei prodotti è paginata e ricercabile per codice, titolo, stato e categoria.

Ogni campo personalizzabile ha un pulsante «?» con spiegazione del funzionamento, effetto sul risultato ed esempio quando utile. L'aiuto si apre al passaggio del mouse e al focus; è utilizzabile anche con clic, tastiera e dispositivi touch. Gli stati hanno testo e icona, oltre al colore.

Le barre riportano l'avanzamento effettivo di caricamento, lettura, collegamento dei dati, associazione categorie, calcolo e generazione CSV. L'interfaccia distingue elaborazione completata, prodotti pronti, errori da correggere, avvisi economici e prodotti inclusi in un'esportazione. «Esportato» non significa «pubblicato su eBay/PrestaShop».

L'archivio conserva profili, caricamenti, prodotti, compatibilità manuali, modifiche, diagnostica ed esportazioni. Si riprende una lavorazione dopo un riavvio dell'app nello stesso ambiente. I file originali sono conservati senza modificarli. Il ripristino dell'ambiente cloud segue il suo contratto di pubblicazione; i processi devono essere riavviati.

## Profili e importazione di fornitori diversi

Un profilo descrive codifica, delimitatore, associazione delle colonne, chiave di collegamento, ruolo dei file, campo del costo, disponibilità ed eventuali dati delle confezioni. Il sistema propone riconoscimenti ragionevoli e mostra anteprima e associazioni; l'utente corregge e salva il profilo. Un formato nuovo non viene interpretato silenziosamente con un profilo incompatibile.

Codici, EAN e riferimenti sono stringhe, con zeri iniziali preservati. Le descrizioni possono contenere delimitatori, virgolette e ritorni a capo. Codifiche e separatori decimali ambigui richiedono una scelta nell'importazione. Gli errori indicano file, riga, campo, valore interessato e correzione proposta.

Il campo del costo d'acquisto è scelto dall'utente ed è IVA esclusa. Non si assume automaticamente che PrezzoBase, Prezzo1 o PrezzoMigliore siano equivalenti. Non si assume che Disponibile, QtaBase o Qta1 siano la quantità vendibile: Disponibile nel campione è un indicatore 0/1. Quantità e prezzi legati a confezioni o minimi di vendita sono visibili e richiedono verifica quando il loro significato non è definito dal profilo.

Duplicati identici possono essere accorpati con diagnostica. Valori discordanti per lo stesso codice, come gli 11 EAN del campione, richiedono risoluzione. I pesi con differenti quantitaConfezione sono dati distinti della stessa famiglia di prodotto, non duplicati da scartare. Non si inventano unità di misura assenti nel profilo.

## Compatibilità, riferimenti e titoli

La fonte delle compatibilità è l'utente. Nella tabella dell'app si aggiungono marca del veicolo, modello, cilindrata/motorizzazione, anno iniziale e finale; i dettagli non pertinenti rimangono facoltativi. Un campo facoltativo Tipo veicolo (Moto, Scooter, Altro, Non specificato) consente di risolvere i contesti di categoria; il tipo non viene dedotto silenziosamente dal solo nome libero del modello. Le compatibilità sono associate alla coppia fornitore/codice prodotto e riutilizzate nelle lavorazioni successive. I titoli del fornitore non autorizzano nuove compatibilità non confermate.

Ogni inserzione eBay ha un riferimento univoco e stabile, collegato al prodotto e alla compatibilità. Riordinare i modelli non cambia i riferimenti delle inserzioni già definite. Su PrestaShop il prodotto ha un solo riferimento per codice nel profilo del fornitore; collisioni tra fornitori richiedono prefisso o riferimento esplicito. Il codice originale rimane disponibile come riferimento fornitore.

I titoli eBay rispettano il limite rigoroso di 80 caratteri, con contatore coerente tra anteprima e validazione. Le proposte combinano tipo di prodotto, marca del prodotto e modello compatibile. Si normalizzano spazi e Unicode, senza tagliare ciecamente codici, modello o anni. Un titolo ancora troppo lungo è modificabile ed è un errore bloccante della sola inserzione. Si può proporre un titolo abbreviato da controllare.

## Categorie

Per eBay l'assegnazione automatica usa il repertorio Moto/Scooter della tabella fornita. La priorità è: scelta esplicita sul prodotto, associazione salvata del fornitore, riconoscimento univoco attraverso comparto/categoria/descrizione e contesto moto o scooter. Sinonimi e normalizzazioni aiutano il confronto; una similarità testuale da sola non dimostra una corrispondenza esatta.

La categoria viene verificata per singola inserzione. Una scelta comune sul prodotto si riutilizza quando il contesto è compatibile; se lo stesso ricambio ha modelli Moto e Scooter, sono possibili categorie diverse per i due gruppi. Un ID valido solo per un contesto non viene propagato automaticamente all'altro. I contesti non specificati richiedono intervento soltanto quando impediscono una scelta univoca.

Percorsi con più ID, contesti contrastanti, prodotti fuori repertorio e riconoscimenti insufficienti restano da correggere. Non si assegna automaticamente una categoria generica per nascondere un problema. Le correzioni sono salvabili per fornitore, famiglia e contesto veicolo. La tabella delle categorie è sostituibile/aggiornabile; il riferimento iniziale è l'allegato, non una tassonomia live verificata.

Le categorie del negozio eBay, presenti separatamente nel builder, sono distinte dalla categoria merceologica eBay.

Per PrestaShop si associano i comparti/categorie del fornitore ai 21 ID del negozio importati. Si esportano solo ID presenti nel repertorio del negozio; nomi simili non autorizzano un ID inventato. Le scelte nascoste producono avvisi, non nuovi ID. Il primo ID esportato è la categoria predefinita del prodotto.

## Prezzi, margine e terminazione ,90

Impostazioni modificabili: ricarico prodotto, ricarico spedizione, commissione percentuale, eventuale commissione fissa, IVA, costo di spedizione in euro e quantità per inserzione. Costo di spedizione e quantità sono unici e applicati a tutte le inserzioni della lavorazione. La spedizione è inclusa nel prezzo del prodotto e gratuita per il cliente.

Il costo del prodotto e quello economico della spedizione sono inseriti al netto dell'IVA. La percentuale di commissione rappresenta il costo configurato sul prezzo finale IVA inclusa. I ricarichi rappresentano il guadagno desiderato sui rispettivi costi dopo la copertura delle commissioni; sono distinti dal margine sulla vendita.

Indicando con C il costo prodotto, S il costo spedizione, r il ricarico prodotto, s quello spedizione, f la commissione percentuale, d la quota fissa e v l'IVA, con percentuali espresse come frazioni:

```text
D = 1 - f × (1 + v)
Prezzo netto calcolato = [C × (1 + r) + S × (1 + s) + d] / D
Prezzo lordo calcolato = Prezzo netto calcolato × (1 + v)
```

Si usa aritmetica decimale. Si applica direttamente la terminazione ,90 al prezzo lordo calcolato, mantenendone la parte intera, senza un arrotondamento intermedio che possa cambiare gli euro. Esempi: 188,94 → 188,90 e 188,999 → 188,90. Questa terminazione può aumentare o diminuire il prezzo: si ricalcola sempre il margine dal prezzo finale.

```text
Ricavo netto = Prezzo lordo finale / (1 + v)
Commissione = Prezzo lordo finale × f + d
Guadagno = Ricavo netto - C - S - Commissione
Margine = Guadagno / Ricavo netto
```

La soglia è 20% sul ricavo al netto dell'IVA. Un margine inferiore è un avviso: il prezzo scelto non viene aumentato automaticamente. Un pulsante «Usa prezzo minimo» applica il minimo suggerito.

```text
Minimo lordo teorico = (C + S + d) × (1 + v) / (D - 0,20)
```

Il minimo suggerito è il più piccolo prezzo con terminazione ,90 che raggiunge la soglia; se la terminazione nell'euro corrente è insufficiente, si passa all'euro successivo. Si rivaluta il risultato prima di proporlo. Valori che rendono D non positivo impediscono il calcolo; se D - 0,20 non è positivo, si segnala che la soglia è irraggiungibile con quei parametri.

I calcoli mantengono precisione decimale, senza arrotondare anticipatamente IVA o commissioni. Gli importi sono mostrati in centesimi; il confronto con il 20% usa il valore non troncato. Un margine appena sotto soglia resta esplicitamente «sotto 20%» anche se la visualizzazione arrotondata è vicina al 20%.

Esempio verificato: C=100 €, S=5 €, IVA=22%, commissione=10%, quota fissa=0. A 188,90 € il margine è circa 19,9863% e compare l'avviso. Il minimo suggerito con terminazione ,90 è 189,90 €, con margine circa 20,3434%.

## Descrizioni e impostazioni delle piattaforme

Per eBay si riutilizzano CSS e struttura del builder Motor World, generando HTML statico per ogni prodotto e ogni modello. L'attuale builder riusa una descrizione comune: nel nuovo sistema il veicolo mostrato deve corrispondere a quello dell'inserzione. Il testo è codificato in sicurezza e le immagini usano URL pubblici HTTPS. Il logo per l'anteprima dell'app può essere locale; un logo nella descrizione esportata usa un URL pubblico configurato oppure la rappresentazione testuale del marchio.

Titoli, marca del ricambio e marca del veicolo sono campi distinti. Non si inventano OEM, MPN, caratteristiche, certificazioni o dati del produttore. Le informazioni aggiuntive presenti nel builder sono configurabili e riutilizzabili.

Un profilo eBay contiene condizioni, ubicazione, impostazioni fiscali, policy di pagamento/spedizione/resi, categoria negozio e dati del produttore. Si mostrano i valori del builder come riferimenti modificabili. Le condizioni commerciali della descrizione e quelle del CSV devono essere coerenti: il builder contiene testo sui resi a 30 giorni e impostazioni CSV a 60 giorni, quindi la scelta diventa esplicita nel profilo.

Per PrestaShop la descrizione contiene testo prodotto e tutte le compatibilità, senza imporre il layout eBay al negozio. Il profilo contiene l'ID reale del gruppo regole IVA: è distinto dalla percentuale IVA e deve essere impostato per un'esportazione con IVA. La spedizione gratuita deve corrispondere alla configurazione del negozio/corriere; il CSV prodotti incorpora il costo nel prezzo, ma non modifica da solo le tariffe del negozio.

## Controllo, esportazioni e correzioni

Errori e avvisi sono distinti. Un errore esclude la sola riga interessata: titolo oltre 80 caratteri, costo non interpretabile, riferimento duplicato/invalidabile, categoria irrisolta, conflitto dati o campo necessario del formato scelto assente. Il margine sotto 20%, una categoria nascosta e altre verifiche non bloccanti restano avvisi visibili. Si offre filtro per disponibili senza sostituire l'indicatore del fornitore alla quantità fissa scelta dall'utente.

Si esportano le righe valide selezionate; le altre rimangono editabili. Dopo le correzioni si può esportare il gruppo dei prodotti corretti, distinguendolo dalle righe già incluse in precedenti file. Lo storico consente di riscaricare un'esportazione senza ricrearla. I rapporti degli errori sono scaricabili e riportano origine, codice e azione richiesta.

Formato CSV: UTF-8, quoting corretto per delimitatori/virgolette/HTML multilinea, riferimenti preservati. eBay usa il contratto delle colonne del builder per Italia/EUR/Add, correggendo rappresentazioni non canoniche come etichetta della condizione al posto dell'ID. PrestaShop usa un sottoinsieme dei campi supportati da 9.0.0, con delimitatore ; e multivalore ,: nome, riferimenti, categoria esistente, prezzo, gruppo IVA, costo, quantità, marca, immagini e descrizioni. Il prezzo finale con terminazione ,90 viene esportato nel solo campo Price tax included, associato al Tax rule ID reale configurato dall'utente; Price tax excluded non viene esportato contemporaneamente. Cost price contiene invece il costo d'acquisto netto. L'ID prodotto viene omesso per la creazione. Le istruzioni indicano associazione colonne, salto dell'intestazione e opzioni per creare nuovi prodotti, oltre a caricare ogni lotto una sola volta per evitare prodotti duplicati nel negozio.

Si misura la dimensione effettiva del CSV in byte UTF-8, intestazione inclusa. La soglia concordata è 15 MB = 15.000.000 byte. Se il file supera la soglia, si creano esattamente due CSV bilanciati per dimensione e separati tra record completi. Entrambi hanno intestazione e sono leggibili indipendentemente. Non si taglia una riga HTML o una sequenza UTF-8. Le dimensioni sono mostrate prima del download.

Se due parti non bastano a stare sotto la soglia, compare un avviso con possibilità di selezionare meno righe; non si producono ulteriori parti senza una scelta diversa dell'utente. La soglia è una preferenza dell'app, non una dichiarazione dei limiti attuali delle piattaforme. Si forniscono i due file anche in un download ZIP per comodità.

## Struttura tecnica e verifiche

Frontend React/TypeScript, servizio Python FastAPI e archivio SQLite locale. I lavori sono eseguiti in background con stato persistito; le esportazioni sono scritte progressivamente per gestire cataloghi grandi. I dati di lavoro e i file generati sono separati dai sorgenti e ignorati da Git. Non si versionano integralmente i listini commerciali allegati. Template e repertori di categoria forniti dall'utente sono risorse dell'app.

L'ambiente cloud deve conservare istruzioni di installazione e avvio effettivamente provate. I servizi vengono riavviati nei nuovi task; si usa il checkout esistente senza creare worktree. Verifiche locali dimostrano l'avvio e una lavorazione rappresentativa. Nessun test locale viene descritto come conferma di un caricamento reale sulle piattaforme.

Criteri di accettazione:

- Profili con schemi diversi, delimitatori, codifiche e zeri iniziali vengono gestiti senza correzioni silenziose.
- I tre campioni reali sono collegati con i conteggi e i conflitti noti, e le confezioni multiple rimangono distinguibili.
- Compatibilità riordinate mantengono riferimenti stabili; eBay genera righe distinte, PrestaShop una sola per codice.
- Categoria ambigua resta irrisolta; una correzione salvata viene riutilizzata; PrestaShop esporta solo ID del repertorio importato.
- Un titolo di 81 caratteri viene escluso; un titolo valido di 80 passa; testo/HTML speciale rimane correttamente codificato.
- Prezzi, commissioni, margine e minimo suggerito sono verificati con aritmetica decimale, incluso il caso 188,94 → 188,90 sotto soglia e il minimo 189,90.
- Gli errori rimangono correggibili dopo l'esportazione dei validi; ogni file CSV risulta leggibile e tracciabile.
- Divisione sopra 15 MB, intestazioni, record multilinea e avviso per due parti ancora troppo grandi vengono verificati.
- Build frontend, test della logica e API, avvio dei servizi e un controllo dell'interfaccia verificano il workflow; i pulsanti «?» funzionano con mouse e tastiera.

Riferimenti ufficiali verificati: PrestaShop/PrestaShop, tag 9.0.0, controllers/admin/AdminImportController.php e docs/csv_import/products_import.csv. Il controller è la fonte dei campi supportati; il campione contiene anche colonne storiche e non viene copiato ciecamente.
