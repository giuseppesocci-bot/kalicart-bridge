# Debito 17 — primo gate del preventivo WooCommerce

26 ottobre 2026. Branch isolato `chatgpt/bridge-151-quote`. Nessun endpoint
registrato, nessun merchant contattato e nessuna modifica al plugin pubblicato.

## Prova concreta

`KaliCart_Bridge_Quote::calculate()` costruisce un carrello e una sessione
temporanei in memoria, chiede a WooCommerce di calcolare i totali e ripristina
sempre carrello, sessione, cliente e pacchetti di spedizione originali. Accetta
solo prodotti semplici o varianti selezionate e metodi core di spedizione.
Quando manca una condizione verificabile restituisce `unknown`.

Nel WordPress isolato con WooCommerce 11.1.2, prodotto da 20,00, quantita' 2,
tariffa fissa `10 + [qty]*2`, il totale WooCommerce e' 54,00. Il fixture
`tools/quote-class-fixture.php` ha confermato 9 condizioni: totale, ripristino
di carrello/sessione/cliente/pacchetti/cookie, nessuna scrittura SQL durante il
calcolo, rifiuto della cache spedizione fredda e di un hook esterno. Il fixture
crea e rimuove i suoi prodotti e la sua zona nello schema di prova.

La prima prova ha riprodotto due scritture a transient di WooCommerce quando
la cache `wc_shipping_method_count` era fredda. Il calcolatore ora controlla
la presenza e la versione della cache **prima** del calcolo e in quel caso
risponde `unknown: shipping_cache_not_ready`.

## Limiti prima della superficie pubblica

- Dimostrare il caso variante e il caso di metodo di spedizione non core nel
  fixture, oltre alla tariffa semplice gia' coperta.
- Definire una protezione delle richieste pubbliche che non crei sessioni o
  transient del preventivo e abbia un limite effettivo tra processi.
- Verificare imposte e completezza della destinazione: dove serve indirizzo di
  fatturazione o un'estensione WooCommerce non verificabile, rispondere
  `unknown` anziche' presentare un totale come definitivo.
- Solo dopo questi gate registrare `POST /checkout/quote` nel Bridge, con
  attivazione esplicita. Global potra' allora invocarlo una sola volta per
  merchant e carrello su richiesta dell'agente; `verify_basket` mantiene la
  stima attuale se il preventivo non e' disponibile.

Questo commit e' un calcolatore isolato, **non** il rilascio della 1.0.151.
