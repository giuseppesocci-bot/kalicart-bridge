# Bridge 1.0.142 — lifecycle sicuro

Data: 2026-10-13  
Base: tag pubblico `v1.0.141`, commit `23b0fc3bc4db756d2b592f4ad2f901b66da662ce`  
Perimetro: disattivazione, cancellazione e riattivazione del plugin. Le modifiche
snapshot/fallback del canary restano fuori da questa release e appartengono alla
1.0.143.

## Problema dimostrato

Su installazioni SiteGround, il percorso WordPress che verifica o riscrive le
regole Apache puo' terminare con HTTP 500. La disattivazione 1.0.141 esegue nello
stesso request `flush_rewrite_rules()` e altre operazioni locali e di rete prima
che WordPress abbia registrato il plugin come inattivo. Una singola eccezione o
fatal in quel percorso puo' quindi impedire la disattivazione.

Il difetto del provider e' indipendente da KaliCart: sul sito di prova il 500 dei
permalink e' rimasto anche con il plugin inattivo. Il plugin deve comunque fare
in modo che un difetto del provider non possa bloccare il proprio lifecycle.

## Contratto 1.0.142

### Disattivazione

1. Il callback registrato con `register_deactivation_hook` non esegue scritture,
   rete, filesystem, cron o rewrite. Registra soltanto una funzione di shutdown
   e ritorna, consentendo a WordPress di salvare prima lo stato inattivo.
2. Lo shutdown e' best-effort: ogni blocco e' isolato da `Throwable`; il guasto
   di un blocco non impedisce i successivi e non puo' retroagire sulla
   disattivazione gia' registrata.
3. Non esegue rete. In particolare non chiama
   `KaliCart_Bridge_Identity::leaving()`.
4. Cancella soltanto i pochi hook WP-Cron noti del plugin. Non esegue scansioni
   generiche e non cancella in massa azioni Action Scheduler.
5. Revoca prima i punti pubblici finiti e validati: manifest snapshot e mirror
   `/.well-known/` di proprieta' KaliCart. I path devono coincidere con una delle
   directory canoniche del plugin, non essere symlink e i nomi variabili devono
   superare una allowlist stretta. Nessun `glob()` o walk non limitato e nessuna
   cancellazione ricorsiva nel percorso critico.
6. Lo stato snapshot locale viene ridotto a `disabled`, preservando il contatore
   `seq`; il riarmo successivo deve generare una nuova sequenza.

### Attivazione e upgrade

1. Le rewrite vengono invalidate soltanto in modalita' soft con
   `flush_rewrite_rules( false )`; non si scrive `.htaccess`.
2. Attivazione e migrazione versionata riconciliano gli artefatti locali in un
   contesto non critico. Se trovano lo stato snapshot `disabled`, richiedono una
   ricostruzione completa e ripristinano la pianificazione naturale.
3. La riconciliazione e' idempotente e non distrugge uno snapshot valido durante
   un normale upgrade.

### Uninstall

1. Nessuna chiamata di rete e nessun `leaving('uninstalled')` sincrono.
2. Pulizia difensiva, idempotente, non ricorsiva e limitata agli artefatti e ai
   record di proprieta' del plugin.
3. Cron, file, transients, righe dinamiche e tabella consenso vengono rimossi
   prima delle opzioni. Le opzioni, inclusa l'identita', vengono eliminate per
   ultime.
4. Cancellando l'opzione identita', una successiva reinstallazione genera una
   nuova installation identity e ripete la prova di dominio.

## Semantica Global e rischio residuo accettato

- Le installazioni <= 1.0.141 continuano a inviare il segnale firmato `leaving`.
  Il Global deve supportare il traffico misto.
- In 1.0.141 `deactivated` e' reversibile: il primo heartbeat firmato della
  stessa installazione produce il rientro. Soltanto `uninstalled` e' definitivo.
- Dalla 1.0.142 disattivazione temporanea e cancellazione non sono distinguibili
  nell'immediato dal Global. L'assenza persistente del Bridge e la scadenza della
  freschezza chiudono il serving.
- Giuseppe ha accettato come male minore che lo snapshot indicizzato possa
  restare ricercabile per la finestra gia' imposta dal Global (24-48 ore). Il
  negozio resta autorevole per commercio live e, senza Bridge, l'agente non puo'
  proseguire operazioni sul merchant.

## Matrice di verifica

1. Disattivazione su installazione normale e su host in cui il rewrite hard
   fallisce: WordPress salva comunque il plugin come inattivo.
2. Il callback non produce scritture prima del commit; lo shutdown non effettua
   rete, hard flush, glob, ricorsione o cancellazioni Action Scheduler di massa.
3. Artefatti merchant-managed, path manipolati e symlink restano intatti.
4. Doppia esecuzione di cleanup e uninstall: nessun fatal, stesso esito.
5. Riattivazione: soft flush, opzioni/consensi preservati, snapshot riarmato con
   nuova sequenza.
6. Uninstall: opzioni eliminate per ultime, identita' rimossa; reinstallazione
   crea una nuova identita'.
7. Installazioni 1.0.141 e 1.0.142 convivono senza cambiare il contratto del
   Global.

Nessun deploy o pubblicazione fa parte di questo lavoro: il candidato richiede
test completi, revisione indipendente e approvazione esplicita.
