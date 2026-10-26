# Debito 17 — primo gate del preventivo WooCommerce

26 ottobre 2026. Branch isolato `chatgpt/bridge-151-quote`. La route e'
registrata solo se il merchant attiva esplicitamente l'opzione, che resta
spenta per impostazione predefinita. Nessuna chiamata HTTP al merchant e
nessuna modifica al plugin pubblicato.

## Prova concreta

`KaliCart_Bridge_Quote::calculate()` costruisce un carrello e una sessione
temporanei in memoria, chiede a WooCommerce di calcolare i totali e ripristina
sempre carrello, sessione, cliente e pacchetti di spedizione originali. Accetta
solo prodotti semplici o varianti selezionate e metodi core di spedizione.
Quando manca una condizione verificabile restituisce `unknown`.

Nel WordPress isolato con WooCommerce 11.1.2, prodotto da 20,00, quantita' 2,
tariffa fissa `10 + [qty]*2`, il totale WooCommerce e' 54,00. Il fixture
`tools/quote-class-fixture.php` ha confermato 13 condizioni: totale, ripristino
di carrello/sessione/cliente/pacchetti/cookie, nessuna scrittura SQL durante il
calcolo, variante selezionata senza scritture SQL, rifiuto della cache
spedizione fredda, di un hook esterno e di un metodo di spedizione non core;
inoltre il POST HTTP opt-in non scrive nel database e il limite locale respinge
la trentunesima richiesta nel minuto. Il fixture
crea e rimuove i suoi prodotti e la sua zona nello schema di prova.

`tools/quote-tax-fixture.php` ha confermato 5 condizioni: totale con imposta,
nessuna scrittura SQL durante il preventivo, destinazione incompleta per
imposta/citta' o zona/stato respinta, base imponibile di fatturazione respinta.
`tools/run-quote-rate-probe.sh` ha lanciato 100 richieste su 20 processi
concorrenti: 30 accettate, le altre respinte come occupate o oltre soglia,
contatore finale 30. Il limite e' per host, non condiviso fra server.

Una lettura controllata su project2209.com, con prodotto 1568 e destinazione
Campobasso, ha restituito `unknown: external_calculation_hook`; il carrello e'
stato ripristinato e sono state osservate zero scritture SQL. Due estensioni
attive intercettano il calcolo del carrello: Woo Product Bundle e Carthub.
Il guardrail non esegue questi hook nel preventivo. L'opzione resta spenta su
project2209; la route non va attivata qui finche' non esiste una verifica
generale, riutilizzabile anche per altri merchant, delle estensioni del carrello.
Un diagnostico separato ha eseguito gli hook per il solo prodotto semplice 1568
in un carrello temporaneo, intercettando e bloccando SQL e HTTP: totale 57,80
EUR (48,80 prodotto + 9,00 spedizione), stato ripristinato, zero tentativi di
scrittura e zero chiamate HTTP. Questa singola prova non certifica tutti i
carrelli o gli aggiornamenti futuri delle estensioni; il guardrail del
prodotto rimane invariato.

La prima prova ha riprodotto due scritture a transient di WooCommerce quando
la cache `wc_shipping_method_count` era fredda. Il calcolatore ora controlla
la presenza e la versione della cache **prima** del calcolo e in quel caso
risponde `unknown: shipping_cache_not_ready`.

## Limiti prima della superficie pubblica

- Il contatore usa `flock` su un file temporaneo locale e non crea transient,
  sessioni o scritture di funnel. In una installazione multi-host il limite e'
  per host; serve un contatore condiviso prima di attivarla li'.
- Dove serve indirizzo di fatturazione o un'estensione WooCommerce non
  verificabile, rispondere `unknown` anziche' presentare un totale come
  definitivo. Project2209 ricade in questo secondo caso.
- La route `POST /checkout/quote` e' ora registrata **solo** con l'opzione
  `kalicart_bridge_quote_enabled`, che resta falsa per impostazione predefinita.
  Global potra' invocarla una sola volta per
  merchant e carrello su richiesta dell'agente; `verify_basket` mantiene la
  stima attuale se il preventivo non e' disponibile.

Questo branch e' un candidato isolato, **non** il rilascio della 1.0.151.
