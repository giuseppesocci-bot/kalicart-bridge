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
`tools/quote-class-fixture.php` ha confermato 18 condizioni: totale, ripristino
di carrello/sessione/cliente/pacchetti/cookie, nessuna scrittura SQL durante il
calcolo, variante selezionata senza scritture SQL, rifiuto della cache
spedizione fredda e di un metodo di spedizione non core. Gli hook esterni
innocui sono eseguiti, compresi hook che modificano prezzo o aggiungono una
fee riportata nella risposta; mutazioni degli articoli producono `unknown`.
Tentativi di scrittura SQL o HTTP producono `unknown: side_effect_blocked`;
inoltre il POST HTTP opt-in non scrive nel database e il limite locale respinge
la trentunesima richiesta nel minuto. Il fixture
crea e rimuove i suoi prodotti e la sua zona nello schema di prova.

`tools/quote-tax-fixture.php` ha confermato 5 condizioni: totale con imposta,
nessuna scrittura SQL durante il preventivo, destinazione incompleta per
imposta/citta' o zona/stato respinta, base imponibile di fatturazione respinta.
`tools/run-quote-rate-probe.sh` ha lanciato 100 richieste su 20 processi
concorrenti: 30 accettate, le altre respinte come occupate o oltre soglia,
contatore finale 30. Il limite e' per host, non condiviso fra server.

Su project2209.com, due estensioni attive intercettano il carrello: Woo Product
Bundle e Carthub. La regola generale ora esegue questi hook dentro un carrello
e una sessione temporanei, blocca query SQL non di lettura e chiamate tramite
WordPress HTTP API, e restituisce `unknown` se uno di questi effetti e'
tentato. Per il prodotto semplice 1568 diretto a Campobasso, il preventivo
e' 57,80 EUR (48,80 prodotto + 9,00 spedizione), stato ripristinato, zero
scritture SQL. Anche la route REST opt-in simulata per una sola richiesta
WP-CLI restituisce HTTP 200 e lo stesso totale; l'opzione nel database del
merchant rimane falsa e il Bridge installato resta 1.0.150.

La prima prova ha riprodotto due scritture a transient di WooCommerce quando
la cache `wc_shipping_method_count` era fredda. Il calcolatore ora controlla
la presenza e la versione della cache **prima** del calcolo e in quel caso
risponde `unknown: shipping_cache_not_ready`.

## Limiti prima della superficie pubblica

- Il contatore usa `flock` su un file temporaneo locale e non crea transient,
  sessioni o scritture di funnel. In una installazione multi-host il limite e'
  per host; serve un contatore condiviso prima di attivarla li'.
- Dove serve indirizzo di fatturazione, un'estensione richiede SQL/HTTP, o
  manca un metodo WooCommerce verificabile, rispondere `unknown` anziche'
  presentare un totale come definitivo. Il guard intercetta le API WordPress
  per database e HTTP; non e' una sandbox per codice PHP arbitrario che scrive
  direttamente su filesystem, socket o database. L'opzione per merchant resta
  esplicita anche dopo il rilascio.
- La route `POST /checkout/quote` e' ora registrata **solo** con l'opzione
  `kalicart_bridge_quote_enabled`, che resta falsa per impostazione predefinita.
  Global potra' invocarla una sola volta per
  merchant e carrello su richiesta dell'agente; `verify_basket` mantiene la
  stima attuale se il preventivo non e' disponibile.

Questo branch e' un candidato isolato, **non** il rilascio della 1.0.151.
