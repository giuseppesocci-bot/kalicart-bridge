<?php
defined( 'ABSPATH' ) || exit;

/**
 * KaliCart_Bridge_Signals
 *
 * Agent-discoverability signals — tutto via WP hooks, zero file fisici.
 *
 *  1. <link rel="kalicart-agent"> in <head>
 *  2. HTML speaking badge (position configurable)
 *  3. robots_txt filter (WP gestisce robots.txt, noi aggiungiamo il blocco)
 *  4. sitemap-agentic-bridge.xml servita via rewrite WP + registrata nel sitemap index
 */
class KaliCart_Bridge_Signals {

    public static function init(): void {

        add_action( 'wp_head', [ __CLASS__, 'inject_head_link' ] );

        if ( get_option( 'kalicart_bridge_badge_enabled', false ) ) {
            add_action( 'wp_footer', [ __CLASS__, 'inject_badge' ] );
        }

        // Inject agent trace into primary nav menu (opt-in, default OFF)
        if ( get_option( 'kalicart_bridge_agent_hints_enabled', false ) ) {
            add_filter( 'wp_nav_menu_items', [ __CLASS__, 'inject_menu_trace' ], 10, 2 );
        }

        // JS-based agent hints: search, zero-results, category, product page (opt-in, default OFF)
        if ( get_option('kalicart_bridge_hint_search', false) || get_option('kalicart_bridge_hint_zero', false) || get_option('kalicart_bridge_hint_category', false) ) {
            add_action( 'wp_footer', [ __CLASS__, 'inject_honey_js' ] );
        }

        if ( get_option( 'kalicart_bridge_robots_enabled', true ) ) {
            add_filter( 'robots_txt', [ __CLASS__, 'filter_robots_txt' ], 10, 2 );
        }

        if ( get_option( 'kalicart_bridge_sitemap_enabled', true ) ) {
            add_action( 'init',              [ __CLASS__, 'register_sitemap_rewrite' ] );
            add_filter( 'query_vars',        [ __CLASS__, 'add_sitemap_query_var' ] );
            add_action( 'template_redirect', [ __CLASS__, 'serve_sitemap' ] );
            add_action( 'wp_sitemaps_init',  [ __CLASS__, 'register_sitemap_provider' ] );
        }

        // .well-known served via WP rewrite (works on any server, nginx or Apache)
        add_action( 'init',              [ __CLASS__, 'register_well_known_rewrite' ] );
        add_filter( 'query_vars',        [ __CLASS__, 'add_well_known_query_var' ] );
        add_action( 'parse_request',     [ __CLASS__, 'serve_well_known' ] );

        // Content-Signal header on all Bridge REST responses — AI usage preferences,
        // mirrors the crawler_policy declared in the discovery document.
        add_filter( 'rest_post_dispatch', [ __CLASS__, 'add_content_signal_header' ], 10, 3 );

        // Raw-HTML + header signals aimed at SCRAPING agents (no JS, no head parsing
        // needed): an HTML comment near the top of <head> reaches any agent that
        // fetches raw HTML in the very act of scraping; the HTTP Link header reaches
        // harnesses that expose response headers to the model.
        add_action( 'wp_head',      [ __CLASS__, 'inject_agent_html_comment' ], 1 );
        add_action( 'send_headers', [ __CLASS__, 'add_link_header' ] );

        // Agent traffic telemetry v2: per-surface daily counters (html = storefront
        // pages, api = Bridge REST) with client classification (branded_agent /
        // anonymous_programmatic / generic_client / browser / other), per-bot
        // breakdown for branded agents, and route+status dimensions on the api
        // surface. Excludes server-internal traffic and known health checkers.
        // Gives the scrape-vs-API ratio that measures real-world Bridge adoption.
        // Opt-out option.
        if ( get_option( 'kalicart_bridge_ai_traffic_enabled', true ) ) {
            add_action( 'template_redirect',  [ __CLASS__, 'count_ai_traffic_html' ], 1 );
            add_filter( 'rest_post_dispatch', [ __CLASS__, 'count_ai_traffic_api' ], 20, 3 );
        }
    }

    // ── 0. SCRAPER-FACING SIGNALS + AI TRAFFIC TELEMETRY ─────────────────────

    public static function inject_agent_html_comment(): void {
        $discovery = rest_url( KALICART_BRIDGE_API_NS . '/discovery' );
        echo "\n<!-- AI AGENTS: this store exposes a structured, read-only product catalog API with live prices, stock and variants (KaliCart Bridge). Do not scrape the HTML. Entry point: " . esc_url( $discovery ) . " -->\n";
    }

    public static function add_link_header(): void {
        if ( is_admin() || headers_sent() ) {
            return;
        }
        $discovery = rest_url( KALICART_BRIDGE_API_NS . '/discovery' );
        header( 'Link: <' . esc_url_raw( $discovery ) . '>; rel="kalicart-agent"; type="application/json"', false );
    }

    /** Known branded AI crawler / agent user-agent tokens. First match wins. */
    /**
     * Mappa assistente -> [bot che leggono, domini di provenienza degli ordini].
     *
     * Le due colonne rispondono a domande diverse e sono ASIMMETRICHE di proposito:
     *  - 'bots'    : chi legge il sito. Viene dal contatore ai_traffic.
     *  - 'origins' : da dove arriva chi compra. Viene dall'attribuzione ordini di
     *                WooCommerce, che e' sourcebuster.js lato browser: registra il
     *                referrer di una PERSONA con un browser, mai di un agente.
     *
     * Asimmetrie volute, non dimenticanze:
     *  - Amazon e Apple leggono ma non mandano traffico a negozi terzi -> no origins.
     *    Siri in particolare apre Safari senza referrer: l'ordine risulta 'diretto'
     *    e non e' distinguibile da chi digita l'indirizzo. Gli assistenti integrati
     *    nel sistema operativo restano invisibili nella colonna ordini.
     *  - Copilot non ha un crawler proprio (usa l'indice Bing) -> no bots.
     *  - duckduckgo.com NON e' fra le origins: e' anche un motore di ricerca
     *    normale, e conterebbe ricerche ordinarie come conversioni da assistente.
     */
    public static function assistant_map(): array {
        return (array) apply_filters( 'kalicart_bridge_assistant_map', [
            'ChatGPT'    => [ 'bots' => [ 'ChatGPT-User', 'OAI-SearchBot', 'GPTBot' ],
                              'origins' => [ 'chatgpt.com', 'openai.com' ] ],
            'Claude'     => [ 'bots' => [ 'ClaudeBot', 'Claude-User', 'Claude-SearchBot', 'claude-web' ],
                              'origins' => [ 'claude.ai' ] ],
            'Perplexity' => [ 'bots' => [ 'PerplexityBot', 'Perplexity-User' ],
                              'origins' => [ 'perplexity.ai' ] ],
            'Gemini'     => [ 'bots' => [ 'Google-Extended' ],
                              'origins' => [ 'gemini.google.com' ] ],
            'Copilot'    => [ 'bots' => [],
                              'origins' => [ 'copilot.microsoft.com' ] ],
            'Meta AI'    => [ 'bots' => [ 'meta-externalagent' ],
                              'origins' => [ 'meta.ai' ] ],
            'Mistral'    => [ 'bots' => [ 'MistralAI' ],
                              'origins' => [ 'chat.mistral.ai' ] ],
            'DuckAssist' => [ 'bots' => [ 'DuckAssistBot' ], 'origins' => [] ],
            'Siri'       => [ 'bots' => [ 'Applebot' ], 'origins' => [] ],
            'Amazon'     => [ 'bots' => [ 'Amazonbot' ], 'origins' => [] ],
            'Bytedance'  => [ 'bots' => [ 'Bytespider' ], 'origins' => [] ],
            'Cohere'     => [ 'bots' => [ 'cohere-ai' ], 'origins' => [] ],
        ] );
    }

    /**
     * Report per il pannello Stats. Aggrega gli ultimi $days bucket di
     * kalicart_bridge_ai_traffic in due sole colonne leggibili da un merchant:
     *
     *   pages   = superficie 'html'   -> pagine che esisterebbero comunque
     *   catalog = superfici 'api'+'mcp' -> rotte che esistono SOLO col Bridge
     *
     * Sul catalogo si contano TUTTE le richieste, non i soli bot riconosciuti:
     * la superficie mcp e' quasi tutta 'other'/'anonymous_programmatic' perche'
     * le armature di agenti custom spesso non mandano user-agent. Filtrare per
     * nome li cancellerebbe proprio mentre fanno la cosa che conta di piu'.
     * Le visite HTML invece si contano solo se branded: li' il traffico non
     * riconosciuto e' il proprietario del sito, non un agente.
     *
     * @return array{days_covered:int,pages:array,catalog:array,catalog_total:int,unnamed_catalog:int}
     */
    public static function get_agent_report( int $days = 30 ): array {
        $data = get_option( 'kalicart_bridge_ai_traffic', [] );
        if ( ! is_array( $data ) ) {
            $data = [];
        }
        $from    = gmdate( 'Y-m-d', time() - ( max( 1, $days ) * DAY_IN_SECONDS ) );
        $pages   = [];
        $catalog = [];
        $catalog_total = 0;
        $named_catalog = 0;
        $covered = 0;

        foreach ( $data as $day => $surfaces ) {
            if ( ! is_string( $day ) || $day < $from || ! is_array( $surfaces ) ) {
                continue;
            }
            $covered++;
            foreach ( $surfaces as $surface => $v ) {
                if ( ! is_array( $v ) ) {
                    continue;
                }
                $bots = is_array( $v['bot'] ?? null ) ? $v['bot'] : [];
                if ( 'html' === $surface ) {
                    foreach ( $bots as $bot => $n ) {
                        $pages[ $bot ] = ( $pages[ $bot ] ?? 0 ) + (int) $n;
                    }
                    continue;
                }
                // api + mcp: il catalogo computabile.
                // Si contano SOLO le classi plausibilmente agentiche. 'browser'
                // sul catalogo e' il proprietario del sito o uno strumento di
                // test: presentarlo come "agente anonimo" sarebbe una bugia
                // proprio nella colonna che deve reggere meglio di tutte.
                $classes = is_array( $v['class'] ?? null ) ? $v['class'] : [];
                $agentic = (int) ( $classes['anonymous_programmatic'] ?? 0 )
                         + (int) ( $classes['generic_client'] ?? 0 )
                         + (int) ( $classes['branded_agent'] ?? 0 )
                         + (int) ( $classes['other'] ?? 0 );
                $catalog_total += $agentic;
                foreach ( $bots as $bot => $n ) {
                    $catalog[ $bot ] = ( $catalog[ $bot ] ?? 0 ) + (int) $n;
                    $named_catalog  += (int) $n;
                }
            }
        }
        arsort( $pages );
        arsort( $catalog );
        return [
            'days_covered'    => $covered,
            'pages'           => $pages,
            'catalog'         => $catalog,
            'catalog_total'   => $catalog_total,
            // Richieste al catalogo senza un agente riconoscibile: agenti custom
            // senza user-agent, piu' il traffico del sito stesso. Si mostra come
            // aggregato, mai attribuito a un nome.
            'unnamed_catalog' => max( 0, $catalog_total - $named_catalog ),
        ];
    }

    /**
     * Righe del pannello, UNA PER ASSISTENTE e non per bot.
     *
     * Necessario perche' le tre colonne hanno chiavi diverse: pagine e catalogo
     * sono per user-agent ('ChatGPT-User', 'OAI-SearchBot', 'GPTBot'), gli ordini
     * sono per dominio di provenienza ('chatgpt.com'). Solo la mappa assistente
     * tiene insieme le due cose, quindi l'aggregazione avviene qui e non nella
     * vista. Per il merchant conta l'assistente, non quale dei tre crawler.
     *
     * I bot che non stanno nella mappa restano come riga a se': meglio un nome
     * sconosciuto mostrato che un passaggio taciuto.
     */
    public static function get_panel_rows( int $days = 30 ): array {
        $rep    = self::get_agent_report( $days );
        $ord    = self::get_assistant_orders_report( $days );
        $map    = self::assistant_map();
        $rows   = [];
        $seen   = [];

        foreach ( $map as $name => $def ) {
            $bots  = (array) ( $def['bots'] ?? [] );
            $pages = 0;
            $cat   = 0;
            $found = [];
            foreach ( $bots as $bot ) {
                $p = (int) ( $rep['pages'][ $bot ] ?? 0 );
                $c = (int) ( $rep['catalog'][ $bot ] ?? 0 );
                if ( $p || $c ) {
                    $found[] = $bot;
                }
                $pages += $p;
                $cat   += $c;
                $seen[ $bot ] = true;
            }
            $o = $ord['by_assistant'][ $name ] ?? [ 'orders' => 0, 'total' => 0.0 ];
            if ( ! $pages && ! $cat && empty( $o['orders'] ) ) {
                continue;
            }
            $rows[] = [
                'name'    => $name,
                'bots'    => $found,
                'pages'   => $pages,
                'catalog' => $cat,
                'orders'  => (int) $o['orders'],
                'total'   => (float) $o['total'],
            ];
        }

        // Bot osservati ma non presenti nella mappa: si mostrano comunque.
        foreach ( array_merge( array_keys( $rep['pages'] ), array_keys( $rep['catalog'] ) ) as $bot ) {
            if ( isset( $seen[ $bot ] ) ) {
                continue;
            }
            $seen[ $bot ] = true;
            $rows[] = [
                'name'    => $bot,
                'bots'    => [],
                'pages'   => (int) ( $rep['pages'][ $bot ] ?? 0 ),
                'catalog' => (int) ( $rep['catalog'][ $bot ] ?? 0 ),
                'orders'  => 0,
                'total'   => 0.0,
            ];
        }

        usort( $rows, static function ( $a, $b ) {
            // Ordine: prima chi ha portato ordini, poi chi ha letto il catalogo,
            // poi il volume sulle pagine. Le righe che dimostrano di piu' stanno
            // in alto anche quando i numeri assoluti sono piccoli.
            return [ $b['orders'], $b['catalog'], $b['pages'] ] <=> [ $a['orders'], $a['catalog'], $a['pages'] ];
        } );

        return [
            'rows'            => $rows,
            'days_covered'    => $rep['days_covered'],
            'unnamed_catalog' => $rep['unnamed_catalog'],
            'currency'        => $ord['currency'],
            'orders_total'    => $ord['total'],
            'orders_count'    => $ord['orders'],
        ];
    }

    /**
     * Ordini arrivati da un assistente AI negli ultimi $days giorni.
     *
     * Sorgente: attribuzione ordini nativa di WooCommerce (sourcebuster.js).
     * IMPLICAZIONE DA NON DIMENTICARE: e' JavaScript lato browser, quindi
     * registra il referrer di una PERSONA che ha cliccato un link dentro un
     * assistente. Un agente headless non produce questo dato — quello, se
     * comprera' davvero, passera' dal marker di sessione Bridge e finira' nel
     * funnel checkout, che e' un contatore separato. I due non vanno sommati.
     *
     * Si usa wc_get_orders e non una query diretta: le chiavi stanno in
     * wp_wc_orders_meta con HPOS attivo e in wp_postmeta senza, e una query
     * sulla tabella si romperebbe sulla meta' dei merchant.
     *
     * @return array{orders:int,total:float,by_assistant:array,currency:string}
     */
    public static function get_assistant_orders_report( int $days = 30 ): array {
        $empty = [ 'orders' => 0, 'total' => 0.0, 'by_assistant' => [], 'currency' => get_woocommerce_currency() ];
        if ( ! function_exists( 'wc_get_orders' ) ) {
            return $empty;
        }
        $cache = get_transient( 'kalicart_bridge_assistant_orders_' . $days );
        if ( is_array( $cache ) ) {
            return $cache;
        }

        // origine -> assistente, per risalire dal referrer al nome mostrato.
        $lookup = [];
        foreach ( self::assistant_map() as $name => $def ) {
            foreach ( (array) ( $def['origins'] ?? [] ) as $origin ) {
                $lookup[ strtolower( $origin ) ] = $name;
            }
        }
        if ( ! $lookup ) {
            return $empty;
        }

        $orders = wc_get_orders( [
            'limit'        => 500, // limite di cortesia: il pannello e' un indicatore, non un report contabile
            'date_created' => '>' . ( time() - ( max( 1, $days ) * DAY_IN_SECONDS ) ),
            'status'       => [ 'wc-processing', 'wc-completed', 'wc-on-hold' ],
            'return'       => 'objects',
        ] );
        if ( ! is_array( $orders ) ) {
            return $empty;
        }

        $by = [];
        $n  = 0;
        $tot = 0.0;
        foreach ( $orders as $order ) {
            if ( ! ( $order instanceof WC_Order ) ) {
                continue;
            }
            // utm_source e' il campo che WooCommerce valorizza col dominio di
            // provenienza; referrer e' il fallback quando il primo manca.
            $src = strtolower( (string) $order->get_meta( '_wc_order_attribution_utm_source', true ) );
            if ( '' === $src ) {
                $src = strtolower( (string) $order->get_meta( '_wc_order_attribution_referrer', true ) );
            }
            if ( '' === $src ) {
                continue;
            }
            $matched = null;
            foreach ( $lookup as $origin => $name ) {
                if ( false !== strpos( $src, $origin ) ) {
                    $matched = $name;
                    break;
                }
            }
            if ( null === $matched ) {
                continue;
            }
            $value = (float) $order->get_total();
            $n++;
            $tot += $value;
            if ( ! isset( $by[ $matched ] ) ) {
                $by[ $matched ] = [ 'orders' => 0, 'total' => 0.0 ];
            }
            $by[ $matched ]['orders']++;
            $by[ $matched ]['total'] += $value;
        }
        $out = [ 'orders' => $n, 'total' => $tot, 'by_assistant' => $by, 'currency' => get_woocommerce_currency() ];
        set_transient( 'kalicart_bridge_assistant_orders_' . $days, $out, HOUR_IN_SECONDS );
        return $out;
    }

    /**
     * Incrocio: assistenti che hanno interrogato il catalogo Bridge E hanno
     * portato clienti che hanno comprato, nella stessa finestra.
     *
     * LIMITE DICHIARATO: il contatore e' giornaliero e anonimo, l'attribuzione
     * ordini e' per-ordine. Non esiste alcun identificativo condiviso, quindi
     * questo NON dimostra che una lettura abbia causato quell'ordine. Dice che
     * lo stesso assistente ha fatto entrambe le cose nel periodo. La frase
     * mostrata all'utente deve restare a questo livello di pretesa.
     */
    public static function get_confirmed_assistants( int $days = 30 ): array {
        $report = self::get_agent_report( $days );
        $orders = self::get_assistant_orders_report( $days );
        $out    = [];
        foreach ( self::assistant_map() as $name => $def ) {
            $read = 0;
            foreach ( (array) ( $def['bots'] ?? [] ) as $bot ) {
                $read += (int) ( $report['catalog'][ $bot ] ?? 0 );
            }
            if ( $read > 0 && ! empty( $orders['by_assistant'][ $name ]['orders'] ) ) {
                $out[ $name ] = [
                    'catalog_reads' => $read,
                    'orders'        => (int) $orders['by_assistant'][ $name ]['orders'],
                    'total'         => (float) $orders['by_assistant'][ $name ]['total'],
                ];
            }
        }
        return $out;
    }

    private static function branded_ai_agent( string $ua ): ?string {
        $bots = [
            'GPTBot', 'OAI-SearchBot', 'ChatGPT-User',
            'ClaudeBot', 'Claude-User', 'Claude-SearchBot', 'claude-web',
            'PerplexityBot', 'Perplexity-User',
            'Google-Extended', 'Bytespider', 'Amazonbot',
            'meta-externalagent', 'cohere-ai',
            // ORDINE SIGNIFICATIVO: il ciclo restituisce il primo match, e
            // 'Applebot' e' sottostringa di 'Applebot-Extended'. Extended (uso
            // per addestramento) va valutato PRIMA di Applebot (indicizzazione
            // per Siri/Spotlight), altrimenti i due diventano indistinguibili.
            'Applebot-Extended', 'Applebot',
            'DuckAssistBot', 'MistralAI',
        ];
        foreach ( $bots as $bot ) {
            if ( stripos( $ua, $bot ) !== false ) {
                return $bot;
            }
        }
        return null;
    }

    /**
     * Client classification. Empty UA is anonymous_programmatic, NOT a bot:
     * custom agent harnesses often send no User-Agent at all, and they are
     * counted in the aggregate without being falsely attributed to a named
     * agent. Order matters: branded first (many branded UAs contain Mozilla),
     * then generic HTTP clients, then real browsers.
     * @return array{0:string,1:?string} [class, branded bot name or null]
     */
    private static function classify_client( string $ua ): array {
        if ( $ua === '' ) {
            return [ 'anonymous_programmatic', null ];
        }
        $bot = self::branded_ai_agent( $ua );
        if ( $bot ) {
            return [ 'branded_agent', $bot ];
        }
        foreach ( [ 'curl', 'wget', 'python', 'aiohttp', 'httpx', 'requests', 'node-fetch', 'axios', 'undici', 'go-http-client', 'okhttp', 'java/', 'libwww', 'perl', 'ruby', 'guzzle' ] as $tok ) {
            if ( stripos( $ua, $tok ) !== false ) {
                return [ 'generic_client', null ];
            }
        }
        if ( stripos( $ua, 'mozilla' ) !== false ) {
            return [ 'browser', null ];
        }
        return [ 'other', null ];
    }

    /**
     * Resolved public IP of this site's own host, cached 1h. Requests whose
     * client IP equals it originate from the very server that runs the site
     * (self-calls, local tooling) and are internal by definition. Works behind
     * varnish/proxies where SERVER_ADDR is always 127.0.0.1.
     */
    private static function site_public_ip(): string {
        $ip = get_transient( 'kalicart_bridge_site_ip' );
        if ( is_string( $ip ) && $ip !== '' ) {
            return $ip === '0' ? '' : $ip;
        }
        $host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
        $ip   = $host !== '' ? (string) gethostbyname( $host ) : '';
        if ( $ip === $host ) {
            $ip = '';
        }
        set_transient( 'kalicart_bridge_site_ip', $ip !== '' ? $ip : '0', HOUR_IN_SECONDS );
        return $ip;
    }

    /**
     * Traffic that must NOT be counted: server-internal calls (federation sync,
     * loopback cron — WordPress UA) and known health checkers. Forwarded addresses
     * use the same fail-closed trusted-proxy parser as the public rate limiters.
     */
    private static function is_excluded_traffic( string $ua ): bool {
        $ip = class_exists( 'KaliCart_Bridge_Rate_Guard' )
            ? KaliCart_Bridge_Rate_Guard::client_ip()
			: sanitize_text_field( (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		$server_addr = sanitize_text_field( (string) wp_unslash( $_SERVER['SERVER_ADDR'] ?? '' ) );
		$server_ips  = array_filter( [ $server_addr, self::site_public_ip(), '127.0.0.1', '::1' ] );
        $excluded = ( $ip !== '' && in_array( $ip, $server_ips, true ) );
        if ( ! $excluded && $ua !== '' ) {
            foreach ( [ 'WordPress', 'KalicartGlobalBot', 'KaliCart-Scanner', 'UptimeRobot', 'Pingdom', 'StatusCake', 'Site24x7', 'HetrixTools', 'Better Uptime', 'monitoring' ] as $tok ) {
                if ( stripos( $ua, $tok ) !== false ) {
                    $excluded = true;
                    break;
                }
            }
        }
        return (bool) apply_filters( 'kalicart_bridge_ai_traffic_excluded', $excluded, $ua, $ip );
    }

    /**
     * Public entry for MCP JSON-RPC events (called by KaliCart_Bridge_MCP).
     * Surface 'mcp' with dims: client (self-declared clientInfo from
     * initialize), method, tool, outcome. Same exclusions and option gate
     * as the html/api surfaces.
     */
    public static function count_mcp_event( array $dims ): void {
        self::count_mcp_events( [ $dims ] );
    }

    /** Bounded bulk entry retained for tests/internal aggregation. */
    public static function count_mcp_events( array $events ): void {
        if ( ! get_option( 'kalicart_bridge_ai_traffic_enabled', true ) ) {
            return;
        }
        $events = array_slice( array_values( array_filter( $events, 'is_array' ) ), 0, 100 );
        if ( empty( $events ) ) {
            return;
        }
        self::count_ai_traffic_events( 'mcp', $events );
    }

    /**
     * Daily bucket counters, option kalicart_bridge_ai_traffic:
     * { "YYYY-MM-DD": { surface: { total, class{}, bot{}, route{}, status{} } } }
     * total is UA-independent; route/status only on the api surface (bounded
     * cardinality: numeric path segments collapsed to {id}). 31-day retention.
     */
    private static function count_ai_traffic( string $surface, array $extra = [] ): void {
        self::count_ai_traffic_events( $surface, [ $extra ] );
    }

    /** Apply bounded events to one in-memory snapshot. */
    private static function apply_ai_traffic_events( array $stats, string $surface, array $events, string $class, ?string $bot ): array {
        $day    = gmdate( 'Y-m-d' );
        $cutoff = gmdate( 'Y-m-d', time() - ( 30 * DAY_IN_SECONDS ) );
        foreach ( array_keys( $stats ) as $stored_day ) {
            if ( ! is_string( $stored_day ) || $stored_day < $cutoff || $stored_day > $day ) {
                unset( $stats[ $stored_day ] );
            }
        }
        $bucket = ( isset( $stats[ $day ][ $surface ] ) && is_array( $stats[ $day ][ $surface ] ) )
            ? $stats[ $day ][ $surface ]
            : [ 'total' => 0, 'class' => [], 'bot' => [] ];
        foreach ( $events as $extra ) {
            if ( ! is_array( $extra ) ) {
                continue;
            }
            $bucket['total']           = (int) ( $bucket['total'] ?? 0 ) + 1;
            $bucket['class'][ $class ] = (int) ( $bucket['class'][ $class ] ?? 0 ) + 1;
            if ( $bot ) {
                $bucket['bot'][ $bot ] = (int) ( $bucket['bot'][ $bot ] ?? 0 ) + 1;
            }
            foreach ( $extra as $dim => $val ) {
                if ( ! is_scalar( $val ) || '' === $val ) {
                    continue;
                }
                $dim = sanitize_key( (string) $dim );
                if ( '' === $dim ) {
                    continue;
                }
                $val = substr( sanitize_text_field( (string) $val ), 0, 100 );
                if ( '' === $val ) {
                    continue;
                }
                if ( ! isset( $bucket[ $dim ] ) || ! is_array( $bucket[ $dim ] ) ) {
                    $bucket[ $dim ] = [];
                }
                if ( ! isset( $bucket[ $dim ][ $val ] ) && count( $bucket[ $dim ] ) >= 49 ) {
                    $val = '(other)'; // 49 named values + one overflow bucket.
                }
                $bucket[ $dim ][ $val ] = (int) ( $bucket[ $dim ][ $val ] ?? 0 ) + 1;
            }
        }
        $stats[ $day ][ $surface ] = $bucket;
        ksort( $stats );
        return array_slice( $stats, -31, null, true );
    }

    /** Persist events with optimistic CAS, so concurrent surfaces cannot overwrite each other. */
    private static function count_ai_traffic_events( string $surface, array $events ): void {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
        if ( self::is_excluded_traffic( $ua ) ) {
            return;
        }
        [ $class, $bot ] = self::classify_client( $ua );
        global $wpdb;
        $option_name = 'kalicart_bridge_ai_traffic';
        for ( $attempt = 0; $attempt < 5; $attempt++ ) {
            $wpdb->last_error = '';
            $row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- fresh value is required for optimistic concurrency.
                $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $option_name )
            );
            if ( '' !== (string) $wpdb->last_error ) {
                return; // Telemetry must never break the catalog response.
            }
            $exists   = null !== $row;
            $observed = $exists ? (string) $row->option_value : null;
            $stats    = $exists ? maybe_unserialize( $observed ) : [];
            $stats    = is_array( $stats ) ? $stats : [];
            $next     = self::apply_ai_traffic_events( $stats, $surface, $events, $class, $bot );
            $encoded  = maybe_serialize( $next );

            if ( $exists ) {
                $written = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- compare-and-swap prevents lost concurrent counters.
                    $wpdb->prepare(
                        "UPDATE {$wpdb->options} SET option_value = %s, autoload = 'no' WHERE option_name = %s AND option_value = %s",
                        $encoded,
                        $option_name,
                        $observed
                    )
                );
            } else {
                $written = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- unique option_name arbitrates first writer.
                    $wpdb->prepare(
                        "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
                        $option_name,
                        $encoded
                    )
                );
            }
            if ( 1 === (int) $written ) {
                wp_cache_delete( $option_name, 'options' );
                if ( ! $exists ) {
                    wp_cache_delete( 'notoptions', 'options' );
                } elseif ( ! in_array( (string) $row->autoload, [ 'no', 'off', 'auto-off' ], true ) ) {
                    wp_cache_delete( 'alloptions', 'options' );
                }
                return;
            }
            if ( false === $written || '' !== (string) $wpdb->last_error ) {
                return;
            }
            usleep( 500 ); // CAS lost to another request; retry from its fresh value.
        }
    }

    public static function count_ai_traffic_html(): void {
        if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
            return;
        }
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
        [ $class ] = self::classify_client( $ua );
		if ( 'branded_agent' !== $class && ! apply_filters( 'kalicart_bridge_ai_traffic_count_nonbranded_html', false ) ) {
			return; // Storefront browsers and arbitrary programmatic UAs do not write by default.
		}
		$guard = KaliCart_Bridge_Rate_Guard::check( 'telemetry_html', 1, [
			'client_limit'  => max( 1, (int) apply_filters( 'kalicart_bridge_html_telemetry_rate_limit_per_client', 5 ) ),
			'client_window' => 60,
			'global_limit'  => max( 1, (int) apply_filters( 'kalicart_bridge_html_telemetry_rate_limit_global', 20 ) ),
			'global_window' => 60,
		] );
		if ( ! $guard['allowed'] ) {
			return;
		}
        self::count_ai_traffic( 'html' );
    }

    public static function count_ai_traffic_api( $response, $server, $request ) {
        if ( $request instanceof WP_REST_Request && strpos( (string) $request->get_route(), '/' . KALICART_BRIDGE_API_NS ) === 0 ) {
            $route = substr( (string) $request->get_route(), strlen( '/' . KALICART_BRIDGE_API_NS ) );
            $status = ( $response instanceof WP_REST_Response ) ? $response->get_status() : null;
			if ( $status === null || $status < 200 || $status >= 400 ) {
				return $response; // Rejected/failed requests must remain write-free telemetry-wise.
			}
			if ( '/mcp' === $route || 0 === strpos( $route, '/mcp/' ) || 0 === strpos( $route, '/checkout/' ) ) {
				return $response; // MCP has richer events; checkout has its own local funnel.
			}
			$route = preg_replace( '#/\d+#', '/{id}', $route );
            self::count_ai_traffic( 'api', [ 'route' => $route, 'status' => $status ] );
        }
        return $response;
    }

    // ── 1. HEAD LINK ──────────────────────────────────────────────────────────

    public static function inject_head_link(): void {
        $url     = rest_url( KALICART_BRIDGE_API_NS . '/discovery' );
        $openapi = rest_url( KALICART_BRIDGE_API_NS . '/openapi' );
        // api-catalog link removed (1.0.114): the extensionless /.well-known path
        // is intercepted by static webserver config on most hosts (404 observed
        // live on real merchants, and one blind-test agent wasted a fetch on it).
        // A sign that may lie is worse than no sign. The linkset stays served
        // (rewrite + physical api-catalog.json) for any client that still probes.
        printf(
            "\n" . '<link rel="kalicart-agent" type="application/json" href="%s"' .
            ' title="Structured catalog API for AI agents — KaliCart Bridge" />' . "\n" .
            '<link rel="service-desc" type="application/vnd.oai.openapi+json" href="%s" />' . "\n",
            esc_url( $url ),
            esc_url( $openapi )
        );
    }

    /**
     * Content-Signal value (draft-romm-aipref-contentsignals): AI usage preferences.
     * Mirrors the crawler_policy in the discovery document so the two never drift:
     *   search   = allow_global_indexing  (kalicart_bridge_global_consent)
     *   ai-input = allow_live_agent_reads (always yes — live agent reads are the point)
     *   ai-train = allow_llm_training     (always no)
     */
    public static function content_signal_value(): string {
        $search = get_option( 'kalicart_bridge_global_consent', false ) ? 'yes' : 'no';
        return 'search=' . $search . ', ai-input=yes, ai-train=no';
    }

    /**
     * Attaches the Content-Signal header to every KaliCart Bridge REST response.
     */
    public static function add_content_signal_header( $response, $server, $request ) {
        if (
            $response instanceof WP_REST_Response
            && $request instanceof WP_REST_Request
            && strpos( (string) $request->get_route(), '/' . KALICART_BRIDGE_API_NS ) === 0
        ) {
            $response->header( 'Content-Signal', self::content_signal_value() );
        }
        return $response;
    }

    // ── MENU TRACE ───────────────────────────────────────────────────────────────

    /**
     * Appends a hidden machine-readable anchor to the primary nav menu.
     * Uses the first menu location that contains 'primary', 'main', 'header' or 'menu_1'.
     * Invisible to humans (aria-hidden, sr-only), readable by DOM agents.
     */
    public static function inject_menu_trace( string $items, stdClass $args ): string {
        $location = $args->theme_location ?? '';

        // Inject in first two registered menu locations
        $registered = array_keys( get_registered_nav_menus() );
        $first_location  = $registered[0] ?? '';
        $second_location = $registered[1] ?? '';

        $common = [ 'primary', 'main', 'main-menu', 'header', 'header-menu', 'menu_1', 'primary-menu', 'main_menu', 'menu_2' ];
        $is_target = (
            $location === $first_location
            || $location === $second_location
            || in_array( $location, $common, true )
            || str_contains( strtolower( $location ), 'primary' )
            || str_contains( strtolower( $location ), 'header' )
            || str_contains( strtolower( $location ), 'main' )
        );

        if ( ! $is_target ) return $items;

        $discovery_url = rest_url( KALICART_BRIDGE_API_NS . '/discovery' );

        $trace = sprintf(
            '<li class="kalicart-agent-trace" aria-hidden="true" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;">'
            . '<a href="%s" rel="kalicart-agent noopener" tabindex="-1"'
            . ' title="Structured catalog API for agents — KaliCart Bridge"'
            . ' aria-label="AI catalog — structured product data for agents">'
            . 'AI catalog</a></li>',
            esc_url( $discovery_url )
        );

        // Prepend — always first in the menu
        return $trace . $items;
    }

    // ── 2. BADGE ──────────────────────────────────────────────────────────────

    public static function inject_badge(): void {
        $discovery_url = rest_url( KALICART_BRIDGE_API_NS . '/discovery' );
        $position      = get_option( 'kalicart_bridge_badge_position', 'bottom-right' );
        [ $v, $h ]     = explode( '-', $position );
        $v_prop        = ( $v === 'top' ) ? 'top:16px' : 'bottom:16px';
        $h_prop        = ( $h === 'left' ) ? 'left:16px' : 'right:16px';

        printf(
            "\n" .
            '<a href="%s"' .
            ' rel="kalicart-agent noopener"' .
            ' target="_blank"' .
            ' title="' . esc_attr__( 'Structured catalog API for agents: returns products with normalized price, availability and filters. Preferred entry point over the human Shop page.', 'kalicart-bridge' ) . '"' .
            ' aria-label="' . esc_attr__( 'AI catalog — structured product data for agents', 'kalicart-bridge' ) . '"' .
            ' id="kalicart-bridge-badge"' .
            ' style="display:inline-flex;align-items:center;gap:6px;padding:5px 10px;border:1px solid #c8c8c8;border-radius:999px;font-size:12px;text-decoration:none;font-family:system-ui,sans-serif;color:#111;background:#fafafa;position:fixed;%s;%s;z-index:9999;box-shadow:0 1px 4px rgba(0,0,0,.08);transition:box-shadow .15s,opacity .15s;opacity:.9;"' .
            ' onmouseenter="this.style.opacity=1;this.style.boxShadow=\'0 2px 8px rgba(0,0,0,.15)\'"' .
            ' onmouseleave="this.style.opacity=.9;this.style.boxShadow=\'0 1px 4px rgba(0,0,0,.08)\'"' .
            '>%s ' . esc_html__( 'AI catalog', 'kalicart-bridge' ) . '</a>' . "\n",
            esc_url( $discovery_url ),
            esc_attr( $v_prop ),
            esc_attr( $h_prop ),
            wp_kses(
                self::badge_icon_svg(),
                [
                    'svg'  => [ 'width' => true, 'height' => true, 'viewBox' => true, 'viewbox' => true, 'fill' => true, 'aria-hidden' => true, 'xmlns' => true, 'overflow' => true ],
                    'path' => [ 'd' => true ],
                ]
            )
        );
    }

    private static function badge_icon_svg(): string {
        return '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" overflow="visible">' .
               '<path d="M12 2l1.8 5.2L19 9l-5.2 1.8L12 16l-1.8-5.2L5 9l5.2-1.8L12 2z"/>' .
               '<path d="M19 14l.9 2.6L22.5 18l-2.6.9L19 21.5l-.9-2.6L15.5 18l2.6-.9L19 14z"/>' .
               '</svg>';
    }

    // ── 3. ROBOTS.TXT ─────────────────────────────────────────────────────────
    //
    // WP genera robots.txt dinamicamente via wp-includes/functions.php
    // get_robots_txt(). Il filtro robots_txt è il modo corretto e ufficiale
    // per modificarlo. Nessun file fisico coinvolto.

    public static function filter_robots_txt( string $output, bool $public ): string {
        if ( ! $public ) return $output;

        $api_base    = str_replace( home_url(), '', rest_url( KALICART_BRIDGE_API_NS ) );
        $sitemap_url = home_url( '/sitemap-agentic-bridge.xml' );

        $output .= "\n# KaliCart Bridge — AI agent catalog access\n";
        $output .= "# Machine-readable product catalog. Entry point for AI shopping agents.\n";
        $output .= "Allow: " . $api_base . "/\n";
        $output .= "Allow: /sitemap-agentic-bridge.xml\n";
        $output .= "Allow: /.well-known/agent.json\n";
        $output .= "Allow: /.well-known/kalicart-bridge.json\n";
        $output .= "Allow: /.well-known/agent-catalog.json\n";
        $output .= "Allow: /.well-known/ucp.json\n";
        $output .= "Content-Signal: " . self::content_signal_value() . "\n";
        $output .= "Sitemap: " . $sitemap_url . "\n";

        return $output;
    }

    // ── 4. SITEMAP-AGENTIC-BRIDGE.XML ─────────────────────────────────────────
    //
    // Servita via rewrite WP → index.php?kalicart_sitemap=1 → template_redirect.
    // Registrata anche nel WP sitemap index. Zero file fisici.

    /**
     * Writes physical .well-known/kalicart-bridge and .well-known/agent.json files.
     * Called on activation and on settings save.
     */
    // ── HONEY JS ─────────────────────────────────────────────────────────────────

    public static function inject_honey_js(): void {
        $discovery  = rest_url( KALICART_BRIDGE_API_NS . '/discovery' );
        $search_ep  = rest_url( KALICART_BRIDGE_API_NS . '/catalog/search' );
        $cat_ep     = rest_url( KALICART_BRIDGE_API_NS . '/catalog/products' );
        $product_ep = rest_url( KALICART_BRIDGE_API_NS . '/catalog/product' );
        $hint_search   = (bool) get_option( 'kalicart_bridge_hint_search', false );
        $hint_zero     = (bool) get_option( 'kalicart_bridge_hint_zero', false );
        $hint_category = (bool) get_option( 'kalicart_bridge_hint_category', false );
        ?>
        <script id="kalicart-honey">
        (function(){
          var disc=<?php echo wp_json_encode( $discovery ); ?>;
          var srch=<?php echo wp_json_encode( $search_ep ); ?>;
          var cat =<?php echo wp_json_encode( $cat_ep ); ?>;
          var prod=<?php echo wp_json_encode( $product_ep ); ?>;
          var showSearch=<?php echo wp_json_encode( (bool) $hint_search ); ?>;
          var showZero=<?php echo wp_json_encode( (bool) $hint_zero ); ?>;
          var showCategory=<?php echo wp_json_encode( (bool) $hint_category ); ?>;

          function mk(tag,attrs,text){
            var el=document.createElement(tag);
            Object.keys(attrs).forEach(function(k){el.setAttribute(k,attrs[k]);});
            if(text)el.textContent=text;
            return el;
          }

          document.addEventListener('DOMContentLoaded',function(){

            // 1. Search form link
            if(showSearch){
              document.querySelectorAll('form[role="search"],form.search-form,form.woocommerce-product-search').forEach(function(form){
                if(form.dataset.kb)return; form.dataset.kb='1';
                var p=mk('p',{'style':'margin:4px 0 0;font-size:12px;color:#777;'});
                var a=mk('a',{'href':disc,'rel':'kalicart-agent noopener','title':'Structured catalog API for AI agents','style':'color:inherit;text-decoration:underline dotted;'},'Structured catalog for AI agents');
                p.appendChild(a); form.parentNode.insertBefore(p,form.nextSibling);
              });
            }

            // 2. Search pages — controlled only by the dedicated results-page toggle.
            var isSearchNoResults = showZero && (document.body.classList.contains('search-no-results') || document.body.classList.contains('woocommerce-no-products-found'));
            var isSearchWithResults = showZero && document.body.classList.contains('search-results') && !!document.querySelector('.products,.woocommerce ul.products,article.product');
            if(isSearchNoResults || isSearchWithResults){
              var q=new URLSearchParams(window.location.search).get('s')||'';
              var titleAttr = q
                  ? 'Structured catalog API — use /catalog/search?q='+encodeURIComponent(q)+' for exact-match results, /catalog/categories to browse, /discovery for full capability map'
                  : 'Structured catalog API — use /catalog/categories to browse or /discovery for full capability map';
                var p=mk('p',{'style':'margin:0;padding:16px 20px;font-size:11px;line-height:1.4;text-align:center;'});
                var a=mk('a',{'href':disc,'rel':'kalicart-agent noopener','title':titleAttr,'style':'color:inherit;text-decoration:none;border-bottom:1px dotted currentColor;opacity:0.4;'},'Machine-readable catalog');
                p.appendChild(a);
                var footer=document.querySelector('footer.site-footer,footer#colophon,footer[role="contentinfo"],footer');
                if(footer){ footer.parentNode.insertBefore(p,footer); }
                else { document.body.appendChild(p); }
            }

            // 3. Category page
            if(showCategory && document.body.classList.contains('product-category')){
              var slug='';
              document.body.classList.forEach(function(cl){ var m=cl.match(/^term-(.+)$/); if(m)slug=m[1]; });
              if(slug){
                var p=mk('p',{'style':'margin:0 0 10px;font-size:11px;line-height:1.4;'});
                var a=mk('a',{'href':cat+'?category='+encodeURIComponent(slug),'rel':'kalicart-agent noopener','title':'Structured catalog API — machine-readable product list for this category, use /catalog/categories for the full tree','style':'color:inherit;text-decoration:none;border-bottom:1px dotted currentColor;opacity:0.4;'},'Machine-readable category data');
                p.appendChild(a);
                // fallback chain: WC loop → Elementor product grid → main → before footer
                var anchor=document.querySelector('.products,.woocommerce ul.products,.elementor-widget-woocommerce-product-images,.woocommerce-products-header,.site-main,main[role="main"],main,.elementor-section');
                if(anchor){ anchor.parentNode.insertBefore(p,anchor); }
                else { var ft=document.querySelector('footer.site-footer,footer#colophon,footer[role="contentinfo"],footer'); if(ft){ft.parentNode.insertBefore(p,ft);}else{document.body.appendChild(p);} }
              }
            }

            // 4. Single product page
            if(showCategory && document.body.classList.contains('single-product')){
              var pid=0;
              document.body.classList.forEach(function(cl){ var m=cl.match(/^postid-(\d+)$/); if(m)pid=m[1]; });
              if(pid){
                var p=mk('p',{'style':'margin:0 0 8px;font-size:11px;line-height:1.4;'});
                var a=mk('a',{'href':prod+'/'+pid,'rel':'kalicart-agent noopener','title':'Structured product data for AI agents — price, variants, availability, attributes in machine-readable format','style':'color:inherit;text-decoration:none;border-bottom:1px dotted currentColor;opacity:0.4;'},'Machine-readable product data');
                p.appendChild(a);
                // fallback chain: append inside .product_meta, or before footer
                var meta=document.querySelector('.product_meta');
                if(meta){ meta.appendChild(p); }
                else { var ft=document.querySelector('footer.site-footer,footer#colophon,footer[role="contentinfo"],footer'); if(ft){ft.parentNode.insertBefore(p,ft);}else{document.body.appendChild(p);} }
              }
            }

          });
        })();
        </script>
        <?php
    }


    public static function register_well_known_rewrite(): void {
        add_rewrite_rule( '^\.well-known/(kalicart-bridge|agent-catalog|api-catalog|agent\.json|ucp)(?:\.json)?$', 'index.php?kalicart_well_known=$matches[1]', 'top' );
    }

    public static function add_well_known_query_var( array $vars ): array {
        $vars[] = 'kalicart_well_known';
        return $vars;
    }

    public static function ucp_profile_json(): string {
        $base     = rest_url( KALICART_BRIDGE_API_NS );
        $checkout = (bool) get_option( 'kalicart_bridge_checkout_enabled', false );

        return wp_json_encode( [
            'ucp' => [
                'version'      => '2026-04-08',
                'services'     => [
                    'dev.ucp.shopping' => [ [
                        'version'   => '2026-04-08',
                        'transport' => 'rest',
                        'endpoint'  => $base,
                    ] ],
                ],
                'capabilities' => [
                    'dev.ucp.shopping.catalog.search' => [ [
                        'version' => '2026-04-08',
                        'spec'    => 'https://ucp.dev/2026-04-08/specification/catalog/search',
                        'note'    => 'Endpoint: GET ' . $base . '/catalog/search — supports q, category, gender, color, on_sale, in_stock, min_price, max_price filters.',
                    ] ],
                    'dev.ucp.shopping.catalog.lookup' => [ [
                        'version' => '2026-04-08',
                        'spec'    => 'https://ucp.dev/2026-04-08/specification/catalog/lookup',
                        'note'    => 'Endpoint: GET ' . $base . '/catalog/product/{id} — returns full product detail with variations.',
                    ] ],
                ],
            ],
            'kalicart_bridge' => [
                'type'          => 'kalicart-merchant-bridge-v1',
                'version'       => KALICART_BRIDGE_VERSION,
                'discovery'     => $base . '/discovery',
                'checkout_note' => $checkout
                    ? 'Checkout sessions available via POST ' . $base . '/checkout/session — returns cart_url and checkout_url for buyer handoff (WooCommerce is payment authority).'
                    : 'Checkout sessions not enabled on this store. Use product URLs for purchase.',
                'documentation' => 'https://bridge.kalicart.com/docs/',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
    }

    /**
     * Shared entry-point discovery document, served at /.well-known/kalicart-bridge,
     * /agent-catalog, /agent.json and their .json mirrors. Single source of truth so
     * the rewrite handler and the physical mirror files never drift.
     */
    private static function bridge_discovery_payload(): string {
        $base = rest_url( KALICART_BRIDGE_API_NS );
        return wp_json_encode( [
            'type'          => 'kalicart-merchant-bridge-v1',
            'version'       => KALICART_BRIDGE_VERSION,
            'name'          => get_bloginfo( 'name' ),
            'discovery'     => $base . '/discovery',
            'catalog_api'   => $base . '/catalog',
            'ucp_profile'   => home_url( '/.well-known/ucp.json' ),
            'agent_note'    => 'GET discovery URL first. Contains capabilities, filter rules, shipping policy and agent instructions.',
            'documentation' => 'https://bridge.kalicart.com/docs/',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
    }

    /**
     * RFC 9727 API Catalog as an RFC 9264 linkset. Advertises this site's
     * machine-callable agent APIs in the standard vocabulary that generic
     * agents and API-readiness probes understand (describedby, service-doc,
     * service-meta, item). The OpenAPI service-desc link is added in a later
     * release once the OpenAPI 3.1 document ships.
     */
    private static function api_catalog_linkset(): string {
        $base = rest_url( KALICART_BRIDGE_API_NS );
        return wp_json_encode( [
            'linkset' => [
                [
                    'anchor'       => $base,
                    'service-desc' => [
                        [ 'href' => $base . '/openapi', 'type' => 'application/vnd.oai.openapi+json', 'title' => 'OpenAPI 3.1 description' ],
                    ],
                    'describedby'  => [
                        [ 'href' => $base . '/discovery', 'type' => 'application/json', 'title' => 'KaliCart Bridge discovery document' ],
                    ],
                    'service-doc'  => [
                        [ 'href' => 'https://bridge.kalicart.com/docs/', 'type' => 'text/html', 'title' => 'KaliCart Bridge documentation' ],
                    ],
                    'service-meta' => [
                        [ 'href' => home_url( '/.well-known/ucp.json' ), 'type' => 'application/json', 'title' => 'UCP profile' ],
                    ],
                    'item'         => [
                        [ 'href' => $base . '/catalog', 'type' => 'application/json', 'title' => 'Read-only WooCommerce catalog API' ],
                        [ 'href' => $base . '/mcp', 'type' => 'application/json', 'title' => 'Model Context Protocol endpoint (JSON-RPC 2.0)' ],
                    ],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
    }

    public static function serve_well_known( $wp = null ): void {
        $raw = '';
        if ( $wp instanceof WP && isset( $wp->query_vars['kalicart_well_known'] ) ) {
            $raw = (string) $wp->query_vars['kalicart_well_known'];
        } elseif ( isset( $_GET['kalicart_well_known'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $raw = sanitize_key( wp_unslash( $_GET['kalicart_well_known'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- public discovery endpoint, no nonce applicable
        }
        // Accept both the extension-less convention path and the .json mirror form.
        $file = sanitize_key( preg_replace( '/\.json$/', '', $raw ) );
        if ( ! $file ) return;
        if ( ! get_option( 'kalicart_bridge_well_known_enabled', true ) ) {
            status_header( 404 );
            nocache_headers();
            header( 'Content-Type: application/json; charset=utf-8' );
            echo wp_json_encode( [ 'success' => false, 'message' => 'Not found.' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON payload
            exit;
        }

        $content_type = 'application/json; charset=utf-8';
        if ( $file === 'ucp' ) {
            // UCP profile — declares catalog capabilities, checkout via continue_url.
            $payload = self::ucp_profile_json();
        } elseif ( $file === 'api-catalog' ) {
            // RFC 9727 API Catalog — linkset (RFC 9264) of this site's agent APIs.
            $payload      = self::api_catalog_linkset();
            $content_type = 'application/linkset+json; charset=utf-8';
        } else {
            // kalicart-bridge / agent-catalog / agent.json — shared entry-point doc.
            $payload = self::bridge_discovery_payload();
        }

        header( 'Content-Type: ' . $content_type );
        header( 'Cache-Control: public, max-age=3600' );
        echo $payload; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON payload
        exit;
    }

    /**
     * Removes legacy extension-less discovery files from /.well-known/.
     *
     * If a physical file exists at those paths, the webserver serves it
     * statically BEFORE WordPress runs, assigning text/plain (nginx ignores
     * .htaccess entirely, so ForceType only ever patched Apache). The paths
     * are served by the rewrite -> serve_well_known() handler instead, which
     * sets Content-Type: application/json on every stack.
     * Only files written by this plugin are removed.
     */
    public static function cleanup_well_known_static_files(): void {
        $dir = rtrim( ABSPATH, '/' ) . '/.well-known/';
        if ( ! is_dir( $dir ) ) return;

        foreach ( [ 'kalicart-bridge', 'agent-catalog', 'api-catalog', 'ucp' ] as $stale ) {
            $path = $dir . $stale;
            if ( ! file_exists( $path ) ) continue;
            $body = (string) @file_get_contents( $path );
            if ( strpos( $body, 'kalicart' ) !== false ) {
                wp_delete_file( $path );
            }
        }

        // Legacy Apache-only .htaccess: remove only if byte-identical to ours.
        $htaccess = $dir . '.htaccess';
        $legacy   = "<Files 'kalicart-bridge'>\n  ForceType application/json\n</Files>\n<Files 'agent-catalog'>\n  ForceType application/json\n</Files>\n<Files 'ucp'>\n  ForceType application/json\n</Files>\n";
        if ( file_exists( $htaccess ) && @file_get_contents( $htaccess ) === $legacy ) {
            wp_delete_file( $htaccess );
        }
    }

    /**
     * Removes every discovery file owned by this plugin when discovery is disabled
     * or the plugin is deactivated. Merchant-managed files are never touched.
     */
    public static function remove_well_known_files(): void {
        $dir = rtrim( ABSPATH, '/' ) . '/.well-known/';
        if ( ! is_dir( $dir ) ) return;

        $owned_names = [
            'agent.json',
            'kalicart-bridge.json',
            'agent-catalog.json',
            'ucp.json',
            'api-catalog.json',
            'kalicart-bridge',
            'agent-catalog',
            'api-catalog',
            'ucp',
        ];
        foreach ( $owned_names as $name ) {
            $path = $dir . $name;
            if ( ! file_exists( $path ) ) continue;
            $body = (string) @file_get_contents( $path );
            if ( stripos( $body, 'kalicart' ) !== false ) {
                wp_delete_file( $path );
            }
        }

        // Legacy Apache-only .htaccess: remove only if byte-identical to ours.
        $htaccess = $dir . '.htaccess';
        $legacy   = "<Files 'kalicart-bridge'>\n  ForceType application/json\n</Files>\n<Files 'agent-catalog'>\n  ForceType application/json\n</Files>\n<Files 'ucp'>\n  ForceType application/json\n</Files>\n";
        if ( file_exists( $htaccess ) && @file_get_contents( $htaccess ) === $legacy ) {
            wp_delete_file( $htaccess );
        }
    }

    public static function write_well_known_files(): void {
        $dir = rtrim( ABSPATH, '/' ) . '/.well-known/';
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }

        // Extension-less convention paths (kalicart-bridge, agent-catalog, ucp)
        // are served by the rewrite -> serve_well_known() handler, which sets
        // Content-Type: application/json on every stack. A physical extension-less
        // file would be served as text/plain, so remove any of ours.
        self::cleanup_well_known_static_files();

        // Physical .json mirrors: the .json extension maps to application/json in
        // every default mime table, so these stay reachable WITH the correct
        // Content-Type even on hosts that serve /.well-known/ as a static location
        // (where the rewrite never runs, e.g. nginx ACME setups), and as a no-PHP
        // fallback for agents probing the filesystem path.
        $bridge  = self::bridge_discovery_payload();
        $mirrors = [
            'agent.json'           => $bridge,
            'kalicart-bridge.json' => $bridge,
            'agent-catalog.json'   => $bridge,
            'ucp.json'             => self::ucp_profile_json(),
            'api-catalog.json'     => self::api_catalog_linkset(),
        ];
        foreach ( $mirrors as $fname => $body ) {
            $path     = $dir . $fname;
            $existing = file_exists( $path ) ? (string) @file_get_contents( $path ) : '';
            // Only (over)write files that are ours or absent — never clobber a
            // file the host/merchant placed there (ACME, autoconfig, etc.).
            if ( $existing === '' || strpos( $existing, 'kalicart' ) !== false ) {
                @file_put_contents( $path, $body ); // phpcs:ignore PluginCheck.CodeAnalysis.WriteFile.ABSPATHDetected -- /.well-known/ files must reside in web root, not uploads/
            }
        }
    }

    public static function register_sitemap_rewrite(): void {
        add_rewrite_rule( '^sitemap-agentic-bridge\.xml$', 'index.php?kalicart_sitemap=1', 'top' );
        // Prevent WP canonical redirect from adding trailing slash
        add_filter( 'redirect_canonical', function( $redirect_url, $requested_url ) {
            if ( strpos( $requested_url, 'sitemap-agentic-bridge.xml' ) !== false ) return false;
            return $redirect_url;
        }, 10, 2 );
    }

    public static function add_sitemap_query_var( array $vars ): array {
        $vars[] = 'kalicart_sitemap';
        return $vars;
    }

    public static function serve_sitemap(): void {
        if ( ! get_query_var( 'kalicart_sitemap' ) ) return;

        header( 'Content-Type: application/xml; charset=utf-8' );
        header( 'X-Robots-Tag: noindex' );
        // Cache 1 ora lato client/CDN
        header( 'Cache-Control: public, max-age=3600' );

        echo self::build_sitemap_xml(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML output, not HTML
        exit;
    }

    public static function register_sitemap_provider(): void {
        add_filter( 'wp_sitemaps_index_sitemaps', function ( array $sitemaps ) {
            $sitemaps['kalicart-bridge'] = [
                'sitemap_url'   => home_url( '/sitemap-agentic-bridge.xml' ),
                'last_modified' => gmdate( 'Y-m-d\TH:i:s\Z' ),
            ];
            return $sitemaps;
        } );
    }

    private static function build_sitemap_xml(): string {
        $base = rest_url( KALICART_BRIDGE_API_NS );
        $now  = gmdate( 'Y-m-d' );
        $site = get_bloginfo( 'name' );

        $out  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $out .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n";
        $out .= '        xmlns:agent="https://kalicart.com/ns/agent/1.0">' . "\n";
        $out .= '  <!-- KaliCart Bridge — Agentic Catalog Sitemap -->' . "\n";
        $out .= '  <!--' . "\n";
        $out .= '    type: kalicart-merchant-bridge-v1' . "\n";
        $out .= '    merchant: ' . esc_html( $site ) . "\n";
        $out .= '    entry_point: ' . esc_url( rest_url( KALICART_BRIDGE_API_NS . '/discovery' ) ) . "\n";
        $out .= '    read: GET entry_point first — contains capabilities, filter rules and agent instructions' . "\n";
        $out .= '    taxonomy: merchant_native_woocommerce' . "\n";
        $out .= '    data: live WooCommerce database, no sync delay' . "\n";
        $out .= '    generated: ' . gmdate( 'c' ) . "\n";
        $out .= '  -->' . "\n\n";

        $core = [
            [ 'url' => $base . '/discovery',         'freq' => 'daily',  'pri' => '1.0', 'role' => 'entry-point',  'note' => 'Discovery document. Start here. Contains capabilities, merchant info and all endpoint URLs.' ],
            [ 'url' => $base . '/catalog/categories', 'freq' => 'weekly', 'pri' => '0.8', 'role' => 'taxonomy',     'note' => 'Full merchant category tree. Use to enumerate browsable paths.' ],
            [ 'url' => $base . '/catalog/products',   'freq' => 'hourly', 'pri' => '0.9', 'role' => 'product-list', 'note' => 'Paginated product listing. Supports: category, gender, color, price_range, in_stock, per_page, page.' ],
            [ 'url' => $base . '/catalog/search',     'freq' => 'hourly', 'pri' => '0.9', 'role' => 'search',       'note' => 'Full-text + filter search. Params: q, category, gender, color, min_price, max_price, in_stock.' ],
        ];

        foreach ( $core as $ep ) {
            $out .= "  <url>\n";
            $out .= '    <loc>' . esc_url( $ep['url'] ) . "</loc>\n";
            $out .= '    <lastmod>' . $now . "</lastmod>\n";
            $out .= '    <changefreq>' . $ep['freq'] . "</changefreq>\n";
            $out .= '    <priority>' . $ep['pri'] . "</priority>\n";
            $out .= '    <agent:role>' . esc_html( $ep['role'] ) . "</agent:role>\n";
            $out .= '    <agent:note>' . esc_html( $ep['note'] ) . "</agent:note>\n";
            $out .= "  </url>\n\n";
        }

        $terms = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => true, 'number' => 500 ] );
        if ( ! is_wp_error( $terms ) ) {
            foreach ( $terms as $term ) {
                $cat_url = $base . '/catalog/products?category=' . urlencode( $term->slug );
                $out .= "  <url>\n";
                $out .= '    <loc>' . esc_url( $cat_url ) . "</loc>\n";
                $out .= '    <lastmod>' . $now . "</lastmod>\n";
                $out .= "    <changefreq>daily</changefreq>\n";
                $out .= "    <priority>0.6</priority>\n";
                $out .= '    <agent:role>category-browse</agent:role>' . "\n";
                $out .= '    <agent:note>Products in: ' . esc_html( $term->name ) . ' (' . (int) $term->count . ' items)</agent:note>' . "\n";
                $out .= "  </url>\n";
            }
        }

        // .well-known discovery files
        foreach ( [ '/.well-known/kalicart-bridge.json', '/.well-known/agent-catalog.json', '/.well-known/ucp.json' ] as $wk_path ) {
            $out .= "  <url>\n";
            $out .= '    <loc>' . esc_url( home_url( $wk_path ) ) . "</loc>\n";
            $out .= '    <lastmod>' . $now . "</lastmod>\n";
            $out .= "    <changefreq>weekly</changefreq>\n";
            $out .= "    <priority>0.8</priority>\n";
            $out .= '    <agent:role>well-known-discovery</agent:role>' . "\n";
            $out .= '    <agent:note>Standard /.well-known/ discovery path. Returns catalog entry point JSON for agents that probe before navigating.</agent:note>' . "\n";
            $out .= "  </url>\n";
        }

        $out .= '</urlset>';
        return $out;
    }
}
