# Debito 17 — primo gate del preventivo WooCommerce

26 ottobre 2026. Branch isolato `chatgpt/bridge-151-quote`. La route e'
registrata solo se il merchant attiva esplicitamente l'opzione, che resta
spenta per impostazione predefinita. Nessun merchant contattato e nessuna
modifica al plugin pubblicato.

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

La prima prova ha riprodotto due scritture a transient di WooCommerce quando
la cache `wc_shipping_method_count` era fredda. Il calcolatore ora controlla
la presenza e la versione della cache **prima** del calcolo e in quel caso
risponde `unknown: shipping_cache_not_ready`.

## Limiti prima della superficie pubblica

- Verificare su un server con piu' processi la protezione delle richieste
  pubbliche: il contatore usa `flock` su un file temporaneo locale e non crea
  transient, sessioni o scritture di funnel. In una installazione multi-host il
  limite e' per host; serve un contatore condiviso prima di attivarla li'.
- Verificare imposte e completezza della destinazione: dove serve indirizzo di
  fatturazione o un'estensione WooCommerce non verificabile, rispondere
  `unknown` anziche' presentare un totale come definitivo.
- La route `POST /checkout/quote` e' ora registrata **solo** con l'opzione
  `kalicart_bridge_quote_enabled`, che resta falsa per impostazione predefinita.
  Global potra' invocarla una sola volta per
  merchant e carrello su richiesta dell'agente; `verify_basket` mantiene la
  stima attuale se il preventivo non e' disponibile.

Questo branch e' un candidato isolato, **non** il rilascio della 1.0.151.
