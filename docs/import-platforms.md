# Caricare i CSV sul negozio

## eBay.it

Il file conserva le 55 colonne del builder allegato, incluse le intestazioni richieste da eBay Italia/EUR. Le righe sono `Add`, prezzo fisso, durata GTC. Le compatibilità inserite da te generano inserzioni separate con SKU stabili e descrizioni distinte. I titoli eccedenti 80 caratteri vengono esclusi finché corretti.

Verifica che i nomi delle policy pagamento/spedizione/resi corrispondano esattamente a quelli presenti nel tuo account e che i giorni di reso coincidano con il testo commerciale. La policy spedizione deve essere gratuita per il cliente; il suo costo è già compreso nel prezzo calcolato. Gli URL delle immagini e dell’eventuale logo esportato devono essere pubblici HTTPS. Il logo del builder è conservato nell’interfaccia, ma non può diventare un indirizzo pubblico solo perché si trova nell’archivio locale.

La categoria merceologica viene controllata per ciascuna variante Moto/Scooter; la categoria del negozio eBay è un’impostazione distinta. Il repertorio è quello del builder ricevuto, non una tassonomia ufficiale verificata in tempo reale. Per famiglie non riconosciute o ambigue scegli una categoria e, se opportuno, salva l’associazione per fornitore/famiglia/contesto.

Carica il CSV nel caricamento file di Seller Hub, poi leggi il report eBay. L’app non pubblica inserzioni e non riceve l’esito dell’importazione. Non caricare lo stesso lotto due volte.

## PrestaShop 9.x

Apri l’importazione **Prodotti** del back office. Seleziona UTF-8, separatore `;`, separatore valori multipli `,` e **salta 1 riga**. Associa le colonne tramite intestazione:

| Colonna CSV | Campo PrestaShop |
| --- | --- |
| Active (0/1) | Attivo |
| Name | Nome |
| Categories (x,y,z...) | Categorie |
| Price tax included | Prezzo IVA inclusa |
| Tax rule ID | ID gruppo regole imposte |
| Cost price | Prezzo di acquisto / costo netto |
| Reference # | Riferimento |
| Supplier reference # | Riferimento fornitore |
| Supplier | Fornitore |
| Brand | Marca |
| Quantity | Quantità |
| EAN13 | EAN13 |
| Summary | Riepilogo |
| Description | Descrizione |
| Image URLs (x,y,z...) | URL immagini |

Il CSV contiene **solo il prezzo IVA inclusa**, insieme al costo di acquisto netto. `Tax rule ID` deve essere l’ID reale di un gruppo regole imposte del negozio che corrisponde all’aliquota impostata nei Prezzi: non è la percentuale IVA. I numeri di categoria sono gli ID già presenti nel tuo negozio; non sostituirli con nuove categorie. Le categorie nascoste generano un avviso.

Questa modalità crea nuovi prodotti: il file omette l’ID prodotto. **Disattiva «Forza tutti gli ID» e la corrispondenza/aggiornamento dei prodotti tramite riferimento.** Importa ogni lotto una sola volta. L’app non aggiorna prodotti già presenti e non registra automaticamente l’esito del back office.

Configura realmente i corrieri/regole del negozio per non addebitare spedizione al cliente. La conferma nell’app attesta questa scelta; non modifica i corrieri di PrestaShop. Il prezzo contiene già il costo di spedizione manuale.

Il contratto CSV è stato verificato contro i campi dell’importatore PrestaShop 9.0.0. Le opzioni effettive del back office e l’esito dei caricamenti restano da verificare nel tuo negozio.

## Parti, errori e storico

I CSV sono UTF-8 con BOM, `;` e righe CRLF; delimitatori e righe a capo nelle descrizioni sono racchiusi correttamente. La soglia include BOM e intestazioni. Oltre 15 MB vengono generati esattamente due CSV con record integri; ciascun file ha un’intestazione. Se una parte resta sopra soglia, riduci la selezione; se un solo record supera la soglia, riduci quella descrizione.

Lo ZIP comprende CSV e rapporto. Le righe errate escluse restano nell’app: correggile, ricontrolla ed esporta le nuove valide. I file dello storico non vengono rigenerati quando modifichi prodotti o impostazioni. «Esportato» significa incluso in un file preparato: non significa pubblicato sul negozio.
