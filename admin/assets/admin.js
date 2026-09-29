/* global KaliBridge */
( function () {
  'use strict';

  let report       = null;
  let badgePosition = 'bottom-right';
  let activeIssueFilter = null;

  const $ = id => document.getElementById( id );
  const $$ = sel => document.querySelectorAll( sel );
  const STR = KaliBridge.i18n || {};

  document.addEventListener( 'DOMContentLoaded', () => {
    badgePosition = KaliBridge.badge_position || 'bottom-right';
    initTabs();
    initSettings();
    initEndpoints();
    loadHealth( false );
    $( 'btnRefresh' )?.addEventListener( 'click', () => loadHealth( true ) );
    $( 'copyHeadLink' )?.addEventListener( 'click', copyHeadLink );
    initIssueClicks();
  } );

  // ── Tabs ──────────────────────────────────────────────────────────────────
  function initTabs() {
    $$( '.kali-tab' ).forEach( btn => {
      btn.addEventListener( 'click', () => {
        $$( '.kali-tab' ).forEach( b => b.classList.remove( 'kali-tab--active' ) );
        $$( '.kali-panel' ).forEach( p => ( p.style.display = 'none' ) );
        btn.classList.add( 'kali-tab--active' );
        const panel = $( 'kali-tab-' + btn.dataset.tab );
        if ( panel ) panel.style.display = 'block';
        if ( btn.dataset.tab === 'settings' ) updateReturnPolicyBlock();
        const url = new URL( window.location.href );
        url.searchParams.set( 'tab', btn.dataset.tab );
        window.history.replaceState( {}, '', url );
      } );
    } );

    const requested = new URLSearchParams( window.location.search ).get( 'tab' );
    if ( requested && $( 'kali-tab-' + requested ) ) switchTab( requested );
  }

  function switchTab( name ) {
    $$( '.kali-tab' ).forEach( b => b.classList.toggle( 'kali-tab--active', b.dataset.tab === name ) );
    $$( '.kali-panel' ).forEach( p => ( p.style.display = 'none' ) );
    const panel = $( 'kali-tab-' + name );
    if ( panel ) panel.style.display = 'block';
    if ( name === 'settings' ) updateReturnPolicyBlock();
  }

  function initIssueClicks() {
    const map = {
      'issue-bad_title': 'TITLE_TOO_SHORT',
      'issue-no_image': 'NO_IMAGE',
      'issue-no_desc': 'NO_DESCRIPTION',
      'issue-no_cat': 'NO_CATEGORY',
      'issue-no_price': 'ZERO_PRICE',
      'stat-card-no_sku': 'NO_SKU'
    };
    Object.entries( map ).forEach( ( [ id, code ] ) => {
      const el = $( id );
      if ( ! el ) return;
      el.dataset.issueCode = code;
      el.tabIndex = 0;
      el.setAttribute( 'role', 'button' );
      el.addEventListener( 'click', () => showIssueProducts( code ) );
      el.addEventListener( 'keydown', e => {
        if ( e.key === 'Enter' || e.key === ' ' ) {
          e.preventDefault();
          showIssueProducts( code );
        }
      } );
    } );

    makeClickable( 'stat-card-quarantine', showAllQuarantine );
    makeClickable( 'stat-card-out_stock', showOutOfStockProducts );
  }

  function makeClickable( id, callback ) {
    const el = $( id );
    if ( ! el ) return;
    el.tabIndex = 0;
    el.setAttribute( 'role', 'button' );
    el.addEventListener( 'click', callback );
    el.addEventListener( 'keydown', e => {
      if ( e.key === 'Enter' || e.key === ' ' ) {
        e.preventDefault();
        callback();
      }
    } );
  }

  // ── Health ────────────────────────────────────────────────────────────────
  function loadHealth( force ) {
    showLoading( true );
    const fd = new FormData();
    fd.append( 'action', 'kalicart_health' );
    fd.append( 'nonce',  KaliBridge.nonce );
    fd.append( 'force',  force ? '1' : '0' );

    fetch( KaliBridge.ajax_url, { method: 'POST', body: fd, credentials: 'same-origin' } )
      .then( r => r.json() )
      .then( res => {
        if ( res.success ) {
          report = res.data;
          renderOverview( report );
          renderQuarantine( report );
        } else {
          showError( res.data?.message || STR.unknown_error );
        }
      } )
      .catch( e => showError( e.message ) )
      .finally( () => showLoading( false ) );
  }

  function showLoading( yes ) {
    const loading = $( 'kali-loading' );
    const panel   = $( 'kali-tab-overview' );
    if ( ! loading || ! panel ) return;
    const overviewActive = $$( '.kali-tab--active' )[0]?.dataset.tab === 'overview';
    loading.style.display = yes && overviewActive ? 'block' : 'none';
    if ( ! yes && overviewActive ) panel.style.display = 'block';
    if ( yes && overviewActive ) panel.style.display = 'none';
  }

  function showError( msg ) {
    const loading = $( 'kali-loading' );
    if ( loading ) loading.innerHTML = `<p style="color:#ef4444">${ STR.error } ${ esc( msg ) }</p>`;
  }

  // ── Overview ──────────────────────────────────────────────────────────────
  function renderOverview( data ) {
    // Score box
    const score = data.average_score ?? 0;
    const scoreEl = $( 'scoreValue' );
    if ( scoreEl ) {
      scoreEl.textContent = score;
      scoreEl.style.color = score >= 80 ? 'var(--kb-green)' : score >= 50 ? 'var(--kb-yellow)' : 'var(--kb-red)';
    }

    // Coverage bars
    const total = data.total_products || 1;
    const noImage = data.issues?.no_image ?? 0;
    const noDesc  = data.issues?.no_description ?? 0;
    const noSku   = data.issues?.no_sku ?? 0;
    const outStock = data.out_of_stock_count ?? 0;

    function setBar( barId, pctId, pct ) {
      const b = $( barId ); const p = $( pctId );
      if ( b ) b.style.width = pct + '%';
      if ( p ) p.textContent = pct + '%';
    }
    setBar( 'barInStock', 'pctInStock', Math.round( ( ( total - outStock ) / total ) * 100 ) );
    setBar( 'barImages',  'pctImages',  Math.round( ( ( total - noImage  ) / total ) * 100 ) );
    setBar( 'barDesc',    'pctDesc',    Math.round( ( ( total - noDesc   ) / total ) * 100 ) );
    setBar( 'barSku',     'pctSku',     Math.round( ( ( total - noSku    ) / total ) * 100 ) );

    // Stats
    setText( 'statTotal',      data.total_products ?? 0 );
    setText( 'statHealthy',    data.healthy_count ?? 0 );
    setText( 'statQuarantine', data.quarantine_count ?? 0 );
    setText( 'statInStock',    data.in_stock_count ?? 0 );
    setText( 'statOutStock',   data.out_of_stock_count ?? 0 );
    setText( 'statNoSku',      data.issues?.no_sku ?? 0 );

    // Issues breakdown — colora in rosso se > 0
    const issues = data.issues ?? {};
    setIssue( 'issueBadTitle', issues.bad_title );
    setIssue( 'issueNoImage', issues.no_image );
    setIssue( 'issueNoDesc',  issues.no_description );
    setIssue( 'issueNoCat',   issues.no_category );
    setIssue( 'issueNoPrice', issues.zero_price );

    // Suggestions
    const sug = data.suggestions ?? [];
    const container = $( 'suggestionsList' );
    if ( container ) {
      container.innerHTML = sug.length === 0
        ? `<div class="kali-empty">&#x1F389; ${ STR.no_issues }</div>`
        : sug.map( s => `
          <div class="kali-suggestion" data-issue-code="${ esc( s.code ) }">
            <div class="kali-suggestion__dot kali-suggestion__dot--${ s.priority }"></div>
            <div class="kali-suggestion__content">
              <div class="kali-suggestion__label">${ esc( s.label ) }</div>
              <div class="kali-suggestion__detail">${ esc( s.detail ) }</div>
            </div>
            ${ s.affected != null
              ? `<button class="kali-suggestion__affected" type="button" data-view-issue="${ esc( s.code ) }">${ s.affected } ${ STR.products }</button>`
              : ( s.admin_url ? `<button class="kali-suggestion__affected" type="button" data-switch-tab="settings">${ STR.configure } →</button>` : '' )
            }
          </div>` ).join( '' );
      container.querySelectorAll( '[data-view-issue]' ).forEach( btn => {
        btn.addEventListener( 'click', () => showIssueProducts( btn.dataset.viewIssue ) );
      } );
      container.querySelectorAll( '[data-switch-tab]' ).forEach( btn => {
        btn.addEventListener( 'click', () => switchTab( btn.dataset.switchTab ) );
      } );
    }

    const lu = $( 'lastUpdated' );
    if ( lu && data.generated_at ) lu.textContent = STR.updated + ' ' + new Date( data.generated_at ).toLocaleTimeString();
  }

  function setIssue( id, count ) {
    const el = $( id );
    if ( ! el ) return;
    el.textContent = count ?? 0;
    el.style.color = count > 0 ? '#ef4444' : '#22c55e';
  }

  // ── Quarantine ────────────────────────────────────────────────────────────
  const ISSUE_COUNT_KEY = {
    TITLE_TOO_SHORT: 'bad_title', NO_DESCRIPTION: 'no_description', NO_CATEGORY: 'no_category',
    ZERO_PRICE: 'zero_price', NO_IMAGE: 'no_image', NO_SKU: 'no_sku',
  };

  function qualityFilterBtn( label, count, url ) {
    if ( ! count ) return '';
    return `<a class="kali-btn kali-btn--ghost kali-q-filter-btn" href="${ esc( url ) }">${ label } <span class="kali-q-filter-count">${ count }</span></a>`;
  }

  // Due file: segnali critici (ragioni di quality signal) e migliorie.
  // Ogni bottone lavora UN problema nella lista prodotti nativa (bulk/quick edit gratis).
  function renderQualityFilters( data ) {
    const i = data.issues ?? {};
    const base = KaliBridge.productsUrl;
    const critical = [
      qualityFilterBtn( STR.btn_title,       i.bad_title,      base + '&kalicart_missing=title' ),
      qualityFilterBtn( STR.btn_description, i.no_description, base + '&kalicart_missing=description' ),
      qualityFilterBtn( STR.btn_category,    i.no_category,    base + '&kalicart_missing=category' ),
      qualityFilterBtn( STR.btn_price,       i.zero_price,     base + '&kalicart_missing=price' ),
    ].join( '' );
    const improvements = [
      qualityFilterBtn( STR.btn_image, i.no_image, base + '&kalicart_missing=image' ),
      qualityFilterBtn( STR.btn_sku,   i.no_sku,   base + '&kalicart_missing=sku' ),
      qualityFilterBtn( STR.btn_stock, data.out_of_stock_count, base + '&stock_status=outofstock' ),
    ].join( '' );
    if ( ! critical && ! improvements ) return '';
    return `<div class="kali-q-actions">
      ${ critical ? `<div class="kali-q-actions__row"><span class="kali-q-actions__label">${ STR.critical_signals }</span>${ critical }</div>` : '' }
      ${ improvements ? `<div class="kali-q-actions__row"><span class="kali-q-actions__label">${ STR.improvements }</span>${ improvements }</div>` : '' }
    </div>`;
  }

  function renderQuarantine( data ) {
    const badge = $( 'quarantineCount' );
    if ( badge ) badge.textContent = data.quarantine_count ?? 0;

    const container = $( 'quarantineList' );
    if ( ! container ) return;

    const allItems = activeIssueFilter === 'OUT_OF_STOCK'
      ? ( data.out_of_stock_products ?? [] )
      : activeIssueFilter && data.issue_products?.[ activeIssueFilter ]
        ? data.issue_products[ activeIssueFilter ]
      : ( data.quarantined_products ?? [] );
    const items = activeIssueFilter && activeIssueFilter !== 'OUT_OF_STOCK' && ! data.issue_products?.[ activeIssueFilter ]
      ? allItems.filter( item => ( item.flags ?? [] ).some( f => f.code === activeIssueFilter ) )
      : allItems;

    const issueIntro = activeIssueFilter ? renderIssueIntro( activeIssueFilter ) : '';
    if ( items.length === 0 ) {
      // Anche a lista vuota i bottoni-filtro restano: altri problemi (immagini,
      // SKU, stock) possono avere conteggi > 0 pur senza segnali critici.
      container.innerHTML = issueIntro + ( activeIssueFilter
        ? `<div class="kali-empty">&#x2713; ${ STR.no_products_for } ${ esc( activeIssueFilter ) }.</div>`
        : `<div class="kali-empty">&#x2713; ${ STR.no_products_quarantine }</div>` ) + renderQualityFilters( data );
      $( 'clearIssueFilter' )?.addEventListener( 'click', () => { activeIssueFilter = null; renderQuarantine( report || data ); } );
      return;
    }

    const totalForView = activeIssueFilter === 'OUT_OF_STOCK'
      ? ( data.out_of_stock_count ?? items.length )
      : activeIssueFilter
        ? ( data.issues?.[ ISSUE_COUNT_KEY[ activeIssueFilter ] ] ?? items.length )
        : ( data.quarantine_count ?? items.length );
    const sampleNote = totalForView > items.length
      ? `<div class="kali-q-sample-note">${ STR.showing_first.replace( '%1$s', String( items.length ) ).replace( '%2$s', String( totalForView ) ) }</div>`
      : '';
    const filterBar = activeIssueFilter
      ? `<div class="kali-q-filter">${ STR.showing } ${ esc( activeIssueFilter ) } <button type="button" id="clearIssueFilter">${ STR.clear_filter }</button></div>`
      : '';

    container.innerHTML = issueIntro + filterBar + sampleNote + items.map( item => {
      const c = item.score >= 70 ? '#22c55e' : item.score >= 40 ? '#f59e0b' : '#ef4444';
      const flags = ( item.flags ?? [] ).map( f =>
        `<span class="kali-flag kali-flag--${ f.severity }">${ esc( f.code ) }</span>`
      ).join( '' );
      return `
        <div class="kali-q-item">
          <div class="kali-q-item__score" style="color:${ c }">${ item.score }</div>
          <div class="kali-q-item__body">
            ${ item.url
              ? `<a class="kali-q-item__name kali-q-item__name--link" href="${ esc( item.url ) }" target="_blank" title="${ esc( item.name ) }">${ esc( item.name ) } <small style="color:#475569;font-weight:400">#${ item.id }</small></a>`
              : `<div class="kali-q-item__name" title="${ esc( item.name ) }">${ esc( item.name ) } <small style="color:#475569;font-weight:400">#${ item.id }</small></div>`
            }
            <div class="kali-q-item__flags">${ flags }</div>
          </div>
          ${ item.url ? `<a class="kali-q-item__edit" href="${ esc( item.url ) }" target="_blank">${ STR.open_product } &rarr;</a>` : '' }
        </div>`;
    } ).join( '' );
    container.innerHTML += renderQualityFilters( data );
    $( 'clearIssueFilter' )?.addEventListener( 'click', () => {
      activeIssueFilter = null;
      renderQuarantine( report || data );
    } );
  }

  function renderIssueIntro( code ) {
    const meta = issueMeta( code );
    if ( ! meta ) return '';

    return `
      <div class="kali-q-intro">
        <strong>${ esc( meta.title ) }</strong>
        <p>${ esc( meta.detail ) }</p>
      </div>`;
  }

  function issueMeta( code ) {
    const map = {
      TITLE_TOO_SHORT: {
        title: STR.why_title,
        detail: STR.why_title_d
      },
      NO_DESCRIPTION: {
        title: STR.why_desc,
        detail: STR.why_desc_d
      },
      NO_CATEGORY: {
        title: STR.why_cat,
        detail: STR.why_cat_d
      },
      ZERO_PRICE: {
        title: STR.why_price,
        detail: STR.why_price_d
      },
      NO_IMAGE: {
        title: STR.why_image,
        detail: STR.why_image_d
      },
      NO_SKU: {
        title: STR.why_sku,
        detail: STR.why_sku_d
      },
      OUT_OF_STOCK: {
        title: STR.why_stock,
        detail: STR.why_stock_d
      }
    };
    return map[ code ] || null;
  }

  function showIssueProducts( code ) {
    if ( ! code ) return;
    activeIssueFilter = code;
    switchTab( 'quarantine' );
    if ( report ) renderQuarantine( report );
  }

  function showAllQuarantine() {
    activeIssueFilter = null;
    switchTab( 'quarantine' );
    if ( report ) renderQuarantine( report );
  }

  function showOutOfStockProducts() {
    activeIssueFilter = 'OUT_OF_STOCK';
    switchTab( 'quarantine' );
    if ( report ) renderQuarantine( report );
  }

  // ── Endpoints ─────────────────────────────────────────────────────────────
  function initEndpoints() {
    const base = KaliBridge.rest_base;
    const headLink = `<link rel="kalicart-agent" type="application/json" href="${ base }/discovery">`;
    if ( $( 'headLinkTag' ) ) $( 'headLinkTag' ).textContent = headLink;

    const endpoints = [
      { path: '/.well-known/kalicart-bridge.json', desc: STR.ep_wellknown, auth: false, example: '', wellknown: true },
      { path: '/discovery',            desc: STR.ep_discovery,           auth: false, example: '' },
      { path: '/mcp',              desc: STR.ep_mcp, auth: false, example: '', method: 'POST' },
      { path: '/catalog/meta',         desc: STR.ep_meta,                             auth: false, example: '' },
      { path: '/catalog/search',       desc: STR.ep_search,   auth: false, example: '?q=scarpe&gender=male&per_page=10' },
      { path: '/catalog/products',     desc: STR.ep_products,                               auth: false, example: '?category=elettronica&in_stock=true' },
      { path: '/catalog/product/{id}', desc: STR.ep_product,        auth: false, example: '' },
      { path: '/catalog/categories',   desc: STR.ep_categories,                              auth: false, example: '' },
      { path: '/catalog/health',       desc: STR.ep_health,                  auth: true,  example: '?force=true' },
      { path: '/checkout/session',     desc: STR.ep_checkout, auth: false, example: '', method: 'POST' },
    ];

    const container = $( 'endpointList' );
    if ( ! container ) return;

    container.innerHTML = endpoints.map( ( ep, i ) => {
      const url        = ep.wellknown ? ( window.location.origin + ep.path ) : ( base + ep.path );
      const method     = ep.method || 'GET';
      const canPreview = ! ep.auth && ! ep.path.includes( '{id}' ) && method === 'GET';
      return `
        <div class="kali-endpoint" id="ep-${ i }">
          <div class="kali-endpoint__header" onclick="window.kaliToggleEp(${ i })">
            <span class="kali-method">${ method }</span>
            <span class="kali-endpoint__path">${ esc( ep.path ) }</span>
            <span class="kali-endpoint__desc">${ esc( ep.desc ) }</span>
            ${ ep.auth ? `<span class="kali-endpoint__auth">${ STR.admin_only }</span>` : '' }
          </div>
          <div class="kali-endpoint__body" id="ep-body-${ i }">
            <div class="kali-endpoint__url">
              ${ canPreview
                  ? `<a href="${ esc( url + ep.example ) }" target="_blank">${ esc( url ) }</a>${ ep.example ? ' <span style="color:#475569">' + esc( ep.example ) + '</span>' : '' }`
                  : esc( url )
              }
            </div>
            ${ canPreview ? `<button class="kali-preview-btn" onclick="window.kaliPreview(${ i }, '${ encodeURIComponent( url + ep.example ) }')">&#x25B6; ${ STR.preview }</button>` : '' }
            <div class="kali-endpoint__preview" id="ep-preview-${ i }" style="display:none"></div>
          </div>
        </div>`;
    } ).join( '' );
  }

  window.kaliToggleEp = i => $( 'ep-body-' + i )?.classList.toggle( 'open' );

  window.kaliPreview = function ( i, encodedUrl ) {
    const preview = $( 'ep-preview-' + i );
    if ( ! preview ) return;
    preview.style.display = 'block';
    preview.textContent = STR.loading;
    fetch( decodeURIComponent( encodedUrl ) )
      .then( r => r.json() )
      .then( data => {
        if ( data.products?.length > 3 )             { data.products = data.products.slice( 0, 3 ); data._truncated = true; }
        if ( data.quarantined_products?.length > 3 ) { data.quarantined_products = data.quarantined_products.slice( 0, 3 ); data._truncated = true; }
        preview.textContent = JSON.stringify( data, null, 2 );
      } )
      .catch( e => ( preview.textContent = STR.error + ' ' + e.message ) );
  };

  // ── Settings ──────────────────────────────────────────────────────────────
  function initSettings() {
    if ( $( 'toggleBadge' ) )     $( 'toggleBadge' ).checked     = KaliBridge.badge_enabled;
    if ( $( 'toggleCheckout' ) )   $( 'toggleCheckout' ).checked   = KaliBridge.checkout_enabled;
    if ( $( 'toggleWellKnown' ) )    $( 'toggleWellKnown' ).checked    = KaliBridge.well_known_enabled;
    if ( $( 'toggleHintSearch' ) )   $( 'toggleHintSearch' ).checked   = KaliBridge.hint_search;
    if ( $( 'toggleHintZero' ) )     $( 'toggleHintZero' ).checked     = KaliBridge.hint_zero;
    if ( $( 'toggleHintCategory' ) ) $( 'toggleHintCategory' ).checked = KaliBridge.hint_category;
    if ( $( 'toggleRobots' ) )  $( 'toggleRobots' ).checked  = KaliBridge.robots_enabled;
    if ( $( 'toggleSitemap' ) ) $( 'toggleSitemap' ).checked = KaliBridge.sitemap_enabled;
    initFederation();
    initProviderConsent();
    initExternalVisibility();
    initCoupons();

    syncPositionWrap();
    $( 'toggleBadge' )?.addEventListener( 'change', syncPositionWrap );

    $$( '.kali-pos-btn' ).forEach( btn => {
      btn.addEventListener( 'click', () => {
        $$( '.kali-pos-btn' ).forEach( b => b.classList.remove( 'kali-pos-btn--active' ) );
        btn.classList.add( 'kali-pos-btn--active' );
        badgePosition = btn.dataset.pos;
      } );
    } );

    $( 'btnSaveSettings' )?.addEventListener( 'click', saveSettings );
    $( 'btnSaveCoupons' )?.addEventListener( 'click', saveSettings );
    $( 'returnPolicySlug' )?.addEventListener( 'input', updateReturnPolicyBlock );

    // Alert gialli su toggle critici
    const WARNINGS = {
      toggleBadge:      STR.warn_badge,
      toggleRobots:     STR.warn_robots,
      toggleSitemap:    STR.warn_sitemap,
      toggleWellKnown:  STR.warn_wellknown,
    };

    Object.entries( WARNINGS ).forEach( ( [ id, msg ] ) => {
      const el = $( id );
      if ( ! el ) return;
      el.addEventListener( 'change', () => {
        const wrap = $( 'kali-warning-' + id );
        if ( ! el.checked ) {
          if ( ! wrap ) {
            const div = document.createElement( 'div' );
            div.id = 'kali-warning-' + id;
            div.className = 'kali-warn-alert';
            div.innerHTML = '<strong>⚠ ' + STR.heads_up + '</strong> ' + esc( msg );
            el.closest( '.kali-toggle-row, .kali-toggle-group' ).after( div );
          }
        } else {
          if ( wrap ) wrap.remove();
        }
      } );
    } );
  }

  function syncPositionWrap() {
    const wrap = $( 'badgePositionWrap' );
    if ( wrap ) wrap.style.display = $( 'toggleBadge' )?.checked ? 'flex' : 'none';
  }

  function updateReturnPolicyBlock() {
    const block    = $( 'returnPolicyBlock' );
    const badgeEl  = $( 'returnPolicyBadge' );
    const wrapEl   = $( 'returnPolicyWrap' );
    const prefixEl = $( 'returnPolicyPrefix' );
    const linkEl   = $( 'returnPolicyTestLink' );
    if ( ! block ) return;

    const slug       = ( $( 'returnPolicySlug' )?.value ?? '' ).trim();
    const configured = slug.length > 0;
    const color      = configured ? '#00a32a' : '#f0a000';
    const bg         = configured ? '#f0fff4' : '#fff8f0';

    block.style.background  = bg;
    block.style.borderColor = color;
    if ( badgeEl )  { badgeEl.style.background = color; badgeEl.textContent = configured ? STR.configured : STR.required; }
    if ( wrapEl )   { wrapEl.style.borderColor = color; }
    if ( prefixEl ) { prefixEl.style.borderRightColor = color; }
    if ( linkEl ) {
      if ( configured ) {
        const fullUrl = KaliBridge.site_url.replace( /\/$/, '' ) + '/' + slug.replace( /^\//, '' );
        linkEl.style.display = 'block';
        linkEl.innerHTML = `<a href="${ fullUrl }" target="_blank" rel="noopener" style="color:#00a32a;">&#x1F517; ${ STR.test_link } ${ fullUrl }</a>`;
      } else {
        linkEl.style.display = 'none';
        linkEl.innerHTML = '';
      }
    }
  }

  // ── Federation (announce / revoke) ──────────────────────────────────────────
  function renderFederation() {
    const regAt    = KaliBridge.federation_registered_at;
    const actBtn   = $( 'federationActivateBtn' );
    const revBtn   = $( 'federationRevokeBtn' );
    const statusEl = $( 'federationStatus' );
    const hintEl   = $( 'federationHint' );
    if ( ! actBtn ) return;
    const confirmBox = $( 'federationRevokeConfirm' );
    if ( confirmBox ) confirmBox.style.display = 'none'; // ogni render parte pulito
    const consentPanel = $( 'federationConsentPanel' );
    const versionEl    = $( 'federationConsentVersion' );
    if ( consentPanel ) consentPanel.style.display = regAt ? 'none' : '';
    if ( versionEl ) {
      const fc = KaliBridge.federation_consent || {};
      if ( regAt && fc.version ) {
        versionEl.style.display = '';
        versionEl.textContent = fc.legacy
          ? ( KaliBridge.i18n?.federation_consent_legacy || 'Consent given before version 1.0.139 (text v1)' )
          : ( KaliBridge.i18n?.federation_consent_version || 'Consent text: %s' ).replace( '%s', fc.version );
      } else {
        versionEl.style.display = 'none';
      }
    }

    if ( regAt ) {
      // gia registrato: mostra stato + revoca, nascondi attiva
      actBtn.style.display = 'none';
      revBtn.style.display = '';
      hintEl.style.display = 'none';
      statusEl.style.display = '';
      const d = new Date( regAt );
      const when = isNaN( d ) ? regAt : d.toLocaleDateString();
      statusEl.textContent = ( KaliBridge.i18n?.federation_registered || 'Registered on' ) + ' ' + when;
    } else {
      // non registrato: il click su Attiva E' l'atto di consenso (un gesto). Sempre abilitato.
      actBtn.style.display = '';
      revBtn.style.display = 'none';
      statusEl.style.display = 'none';
      actBtn.disabled = false;
      hintEl.style.display = 'none';
    }
  }

  // ── Provider-specific federated distribution authorization ───────────────
  function renderProviderConsent() {
    const block       = $( 'providerConsentBlock' );
    const grantPanel  = $( 'providerConsentGrantPanel' );
    const currentBox  = $( 'providerConsentCurrent' );
    const status      = $( 'providerConsentStatus' );
    const required    = $( 'providerGlobalRequired' );
    const checkbox    = $( 'providerConsentCheckbox' );
    const grantBtn    = $( 'providerConsentGrantBtn' );
    const revokeBtn   = $( 'providerConsentRevokeBtn' );
    const confirmBox  = $( 'providerConsentRevokeConfirm' );
    if ( ! block ) return;

    const state        = KaliBridge.provider_consent || {};
    const globalActive = Boolean( KaliBridge.provider_global_active );
    const authorized   = state.authorized === true;
    block.classList.toggle( 'kali-provider-consent--active', authorized );
    // 1.0.139: prima dell'attivazione il canale si autorizza nella casella del flusso di
    // attivazione (sopra); qui la sezione serve a chi e' gia' attivo o ha uno storico.
    block.style.display = ( ! globalActive && ! state.consent_id ) ? 'none' : '';
    if ( required ) required.style.display = globalActive ? 'none' : '';
    if ( grantPanel ) grantPanel.style.display = authorized ? 'none' : '';
    if ( checkbox ) checkbox.disabled = ! globalActive;
    if ( grantBtn ) grantBtn.disabled = ! globalActive || ! checkbox?.checked;
    if ( revokeBtn ) revokeBtn.style.display = authorized ? '' : 'none';
    // L'avviso sul pilot riguarda una richiesta in corso: dopo una revoca non vale.
    const pilotNotice = $( 'providerConsentPilotNotice' );
    if ( pilotNotice ) pilotNotice.style.display = authorized ? '' : 'none';
    if ( confirmBox ) confirmBox.style.display = 'none';

    if ( ! state.consent_id ) {
      if ( currentBox ) currentBox.style.display = 'none';
      return;
    }

    if ( currentBox ) currentBox.style.display = '';
    const rawDate = state.occurred_at || '';
    const parsed  = rawDate ? new Date( rawDate ) : null;
    const when    = parsed && ! isNaN( parsed ) ? parsed.toLocaleString() : rawDate;
    const actionLabel = authorized
      ? ( KaliBridge.i18n?.provider_authorized_on || 'Authorized on' )
      : ( KaliBridge.i18n?.provider_revoked_on || 'Revoked on' );
    const receiptLabels = {
      pending:  KaliBridge.i18n?.provider_receipt_pending || 'awaiting delivery to KaliCart Global',
      accepted: KaliBridge.i18n?.provider_receipt_accepted || 'accepted by KaliCart Global',
      failed:   KaliBridge.i18n?.provider_receipt_failed || 'delivery failed',
      global_pending:  KaliBridge.i18n?.provider_receipt_received || 'received by KaliCart Global; verification pending',
      global_verified: KaliBridge.i18n?.provider_receipt_verified || 'verified by KaliCart Global',
      global_rejected: KaliBridge.i18n?.provider_receipt_rejected || 'rejected by KaliCart Global'
    };
    // A Global status is meaningful only after the local POST was acknowledged.
    // A locally pending 409 must remain "awaiting delivery", not "received".
    const globalReceiptKey = state.receipt_status === 'accepted' && state.global_receipt_status
      ? 'global_' + state.global_receipt_status
      : '';
    const receipt = receiptLabels[ globalReceiptKey ] || receiptLabels[ state.receipt_status ] || state.receipt_status || '—';
    if ( status ) {
      status.innerHTML = '<span class="kali-consent-summary__status"><strong>' + esc( actionLabel ) + ' ' + esc( when ) + '</strong></span>'
        + '<span class="kali-consent-summary__meta"><code>' + esc( state.terms_version || '' ) + '</code></span>'
        + '<span class="kali-consent-summary__meta">' + esc( KaliBridge.i18n?.provider_receipt || 'Receipt' ) + ': ' + esc( receipt ) + '</span>';
    }
  }

  function providerConsentRequest( action, extra ) {
    const fd = new FormData();
    fd.append( 'action', action );
    fd.append( 'nonce', KaliBridge.nonce );
    Object.entries( extra || {} ).forEach( pair => fd.append( pair[0], pair[1] ) );
    return fetch( KaliBridge.ajax_url, { method: 'POST', body: fd, credentials: 'same-origin' } )
      .then( response => response.json() );
  }

  function initProviderConsent() {
    const checkbox  = $( 'providerConsentCheckbox' );
    const grantBtn  = $( 'providerConsentGrantBtn' );
    const revokeBtn = $( 'providerConsentRevokeBtn' );
    if ( ! grantBtn ) return;

    renderProviderConsent();
    checkbox?.addEventListener( 'change', renderProviderConsent );

    grantBtn.addEventListener( 'click', () => {
      if ( ! KaliBridge.provider_global_active ) {
        alert( KaliBridge.i18n?.provider_global_required || 'Activate the Federated Catalog first.' );
        return;
      }
      if ( ! checkbox?.checked ) {
        alert( KaliBridge.i18n?.provider_consent_required || 'Select the authorization checkbox first.' );
        return;
      }
      grantBtn.disabled = true;
      providerConsentRequest( 'kalicart_provider_consent_grant', { accepted: '1' } )
        .then( res => {
          if ( ! res.success ) {
            alert( res.data?.reason === 'global_not_active'
              ? ( KaliBridge.i18n?.provider_global_required || 'Activate the Federated Catalog first.' )
              : ( KaliBridge.i18n?.provider_grant_failed || 'Authorization could not be recorded.' ) );
            return;
          }
          KaliBridge.provider_consent = res.data.state;
          KaliBridge.provider_global_active = res.data.global_active;
          checkbox.checked = false;
          renderProviderConsent();
        } )
        .catch( () => alert( KaliBridge.i18n?.provider_grant_failed || 'Authorization could not be recorded.' ) )
        .finally( renderProviderConsent );
    } );

    revokeBtn?.addEventListener( 'click', () => {
      $( 'providerConsentRevokeConfirm' ).style.display = '';
      revokeBtn.style.display = 'none';
    } );
    $( 'providerConsentRevokeCancelBtn' )?.addEventListener( 'click', renderProviderConsent );
    $( 'providerConsentRevokeConfirmBtn' )?.addEventListener( 'click', () => {
      const btn = $( 'providerConsentRevokeConfirmBtn' );
      btn.disabled = true;
      providerConsentRequest( 'kalicart_provider_consent_revoke' )
        .then( res => {
          if ( ! res.success ) {
            alert( KaliBridge.i18n?.provider_revoke_failed || 'Revocation could not be recorded.' );
            return;
          }
          KaliBridge.provider_consent = res.data.state;
          KaliBridge.provider_global_active = res.data.global_active;
          renderProviderConsent();
        } )
        .catch( () => alert( KaliBridge.i18n?.provider_revoke_failed || 'Revocation could not be recorded.' ) )
        .finally( () => { btn.disabled = false; } );
    } );

    if ( KaliBridge.provider_consent?.consent_id ) {
      providerConsentRequest( 'kalicart_provider_consent_status' )
        .then( res => {
          if ( res.success ) {
            KaliBridge.provider_consent = res.data.state;
            KaliBridge.provider_global_active = res.data.global_active;
            renderProviderConsent();
          }
        } )
        .catch( () => {} );
    }
  }

  function initExternalVisibility() {
    const btn = $( 'externalVisibilityBtn' );
    const out = $( 'externalVisibilityResult' );
    if ( ! btn || ! out ) return;
    const T = KaliBridge.i18n || {};
    const cleanLabel = value => String( value || '' ).replace( /:\s*$/, '' );
    const fact = ( icon, label, value, tone = 'neutral' ) => ''
      + '<div class="kali-visibility-fact kali-visibility-fact--' + tone + '">'
      +   '<span class="dashicons dashicons-' + icon + '" aria-hidden="true"></span>'
      +   '<span class="kali-visibility-fact__text">'
      +     '<span class="kali-visibility-fact__label">' + esc( cleanLabel( label ) ) + '</span>'
      +     '<strong class="kali-visibility-fact__value">' + esc( value ) + '</strong>'
      +   '</span>'
      + '</div>';
    const emptyState = ( icon, title, note, tone = 'neutral' ) => ''
      + '<div class="kali-visibility-empty kali-visibility-empty--' + tone + '">'
      +   '<span class="dashicons dashicons-' + icon + '" aria-hidden="true"></span>'
      +   '<span><strong>' + esc( title ) + '</strong><span>' + esc( note ) + '</span></span>'
      + '</div>';
    const detailPanel = parts => {
      if ( ! parts.length ) return '';
      // Plain description under the facts: no title, not collapsible.
      return '<div class="kali-visibility-detail">'
        + parts.map( part => '<p>' + ( typeof part === 'string' ? esc( part ) : part.html ) + '</p>' ).join( '' )
        + '</div>';
    };

    const loadExternalVisibility = () => {
      btn.disabled = true;
      out.innerHTML = '<div class="kali-visibility-loading"><span class="dashicons dashicons-update" aria-hidden="true"></span>'
        + '<span>' + esc( T.loading || 'Loading\u2026' ) + '</span></div>';
      const fd = new FormData();
      fd.append( 'action', 'kalicart_external_visibility_check' );
      fd.append( 'nonce',  KaliBridge.nonce );
      fetch( KaliBridge.ajax_url, { method: 'POST', body: fd, credentials: 'same-origin' } )
        .then( r => r.json() )
        .then( res => {
          btn.disabled = false;
          if ( ! res.success ) {
            out.innerHTML = emptyState( 'warning', T.external_check_unreachable || 'Not reachable', T.external_check_failed || 'Could not reach KaliCart Global.', 'error' );
            return;
          }
          const d = res.data;
          if ( ! d.checked ) {
            out.innerHTML = emptyState( 'clock', T.external_check_never || 'Never checked', T.external_check_not_probed || 'Not observed from outside yet.' );
            return;
          }
          const yes = T.yes || 'Yes';
          const no  = T.no  || 'No';
          const isReachable = ( d.probe_status === 'ok' );
          // 1.0.137: a blocked check is not "not a Bridge". The value is colored
          // like its dot and always carries its cause; a second line says what
          // it means for the store. The cause comes from KaliCart Global
          // (challenge_provider), so a new detector never needs a plugin release.
          const isChallenged = ( d.probe_status === 'blocked_by_bot_challenge' );
          const isRobots     = ( d.probe_status === 'disallowed_by_robots' );
          const isLimited    = isChallenged || isRobots;
          const providerName = ( slug ) => {
            const known = { cloudflare: 'Cloudflare' };
            const s = String( slug || '' ).trim();
            return known[ s.toLowerCase() ] || ( s ? s.charAt( 0 ).toUpperCase() + s.slice( 1 ) : '' );
          };
          const cause = isChallenged
            ? ( d.challenge_provider
                ? ( T.external_check_cause_named || 'anti-bot challenge from %s' ).replace( '%s', providerName( d.challenge_provider ) )
                : ( T.external_check_cause_generic || 'anti-bot challenge from the service that protects the site' ) )
            : isRobots ? ( T.external_check_cause_robots || 'disallowed by your robots.txt' ) : '';
          const valueText = isReachable ? ( T.external_check_reachable || 'Reachable' )
            : isLimited ? ( T.external_check_challenged || 'Limited external access' )
            : ( T.external_check_unreachable || 'Not reachable' );
          const inCatalog = d.consent_global_indexing === true && d.consent_federated_search === true && d.serving_status === 'active';
          let limitDetail = '';
          if ( isLimited ) {
            limitDetail = inCatalog
              ? ( T.external_check_in_catalog || 'Your store stays in the KaliCart Federated Catalog with the last catalog read; price and availability cannot be verified live.' )
              : ( T.external_check_not_in_catalog || 'Your catalog is not in the KaliCart Federated Catalog, because KaliCart Global cannot read it.' );
            limitDetail += ' ' + ( isChallenged
              ? ( T.external_check_others_too || 'Other automated clients, including AI agents, may receive the same challenge.' )
              : ( T.external_check_robots_note || 'KaliCart Global respects your robots.txt and does not read the Bridge paths it disallows.' ) );
          }
          const detected  = d.bridge_detected ? yes
            : ( isLimited ? ( T.external_check_detected_unknown || 'Unknown (the check was blocked)' ) : no );

          // Eta' relativa + soglia di staleness a 7 giorni (stesso valore che Global usa
          // internamente, LIVENESS_STALE_DAYS, per decidere quando un merchant e' "sospeso"
          // per staleness - qui solo per avvisare, non per nascondere il dato).
          let ago = '—', isStale = false;
          if ( d.last_probed_at ) {
            const ms = Date.now() - new Date( d.last_probed_at ).getTime();
            const days = ms / 86400000;
            if ( days >= 1 ) {
              const dayCount = Math.floor( days );
              const dayTemplate = dayCount === 1
                ? ( KaliBridge.i18n?.external_check_ago_day || '%d day ago' )
                : ( KaliBridge.i18n?.external_check_ago_days || '%d days ago' );
              ago = dayTemplate.replace( '%d', dayCount );
            } else if ( ms >= 3600000 ) {
              const hourCount = Math.floor( ms / 3600000 );
              const hourTemplate = hourCount === 1
                ? ( KaliBridge.i18n?.external_check_ago_hour || '%d hour ago' )
                : ( KaliBridge.i18n?.external_check_ago_hours || '%d hours ago' );
              ago = hourTemplate.replace( '%d', hourCount );
            } else {
              ago = KaliBridge.i18n?.external_check_ago_now || 'just now';
            }
            if ( days > 7 ) {
              isStale = true;
            }
          }
          const when = d.last_probed_at ? new Date( d.last_probed_at ).toLocaleString() + ' (' + ago + ')' : '—';

          // 1.0.138: store identity as KaliCart Global sees it. "Verified" is not
          // "served": without a received catalog the panel says so.
          let identityText = '—';
          let identityTone = 'neutral';
          let identityNote = '';
          const idn = d.identity;
          if ( idn && idn.mode && idn.mode !== 'off' && idn.domain_verification ) {
            const v = idn.domain_verification;
            identityTone = v === 'verified' ? 'ok' : ( v === 'failed' ? 'error' : 'warn' );
            identityText = v === 'verified' ? ( T.identity_verified || 'Verified' )
              : v === 'failed' ? ( T.identity_failed || 'Verification failed' )
              : ( T.identity_pending || 'Verification pending' );
            if ( v === 'verified' && idn.catalog_sync === 'not_accepted' ) {
              identityNote = T.identity_not_received || 'Identity verified, but the catalog cannot be received yet: KaliCart Global cannot read it from outside.';
            } else if ( v === 'pending' ) {
              identityNote = T.identity_pending_note || 'KaliCart Global could not read this site to verify it. It retries automatically.';
            } else if ( v === 'failed' ) {
              identityNote = T.identity_failed_note || 'KaliCart Global read this site but did not find this installation’s key. It retries automatically.';
            }
            if ( v === 'verified' && idn.heartbeat_fresh === false ) {
              identityNote = ( identityNote ? identityNote + ' ' : '' ) + ( T.identity_stale || 'KaliCart Global has not received a signal from this store in the last 7 days.' );
              identityTone = 'warn';
            }
          }

          const details = [];
          if ( cause ) {
            // "Title: explanation", the title in bold. Split on %s so each
            // language keeps its own punctuation (French: "Cause ... : %s").
            const tpl = T.external_check_cause_line || 'Cause of the limited access: %s';
            const at  = tpl.indexOf( '%s' );
            details.push( at < 0
              ? esc( tpl ) + ' ' + esc( cause )
              : { html: '<strong>' + esc( tpl.slice( 0, at ).trimEnd() ) + '</strong> ' + esc( cause ) + esc( tpl.slice( at + 2 ) ) } );
          }
          if ( limitDetail ) details.push( limitDetail );
          if ( identityNote ) details.push( identityNote );
          if ( isStale ) details.push( T.external_check_stale || 'This observation is more than 7 days old.' );
          if ( T.external_check_help ) details.push( T.external_check_help );
          out.innerHTML = '<div class="kali-visibility-facts">'
            + fact( 'visibility', T.external_check_label_access || 'Access from outside:', valueText, isReachable ? 'ok' : ( isLimited ? 'warn' : 'error' ) )
            + fact( 'admin-plugins', T.external_check_label_detected || 'Bridge detected:', detected, d.bridge_detected ? 'ok' : ( isLimited ? 'warn' : 'error' ) )
            + fact( 'shield-alt', T.identity_label || 'Store identity:', identityText, identityTone )
            + fact( 'clock', T.external_check_label_checked || 'Last checked:', when, isStale ? 'warn' : 'neutral' )
            + '</div>' + detailPanel( details );
        } )
        .catch( () => {
          btn.disabled = false;
          out.innerHTML = emptyState( 'warning', T.external_check_unreachable || 'Not reachable', T.external_check_failed || 'Could not reach KaliCart Global.', 'error' );
        } );
    };

    // The check runs on request: at rest the panel shows only the button.
    btn.addEventListener( 'click', loadExternalVisibility );
  }

  function initFederation() {
    const actBtn = $( 'federationActivateBtn' );
    const revBtn = $( 'federationRevokeBtn' );
    if ( ! actBtn ) return;

    renderFederation();
    actBtn.addEventListener( 'click', () => {
      // Il click E' il consenso: niente guardia, il server accende il consenso e annuncia.
      actBtn.disabled = true;
      const fd = new FormData();
      fd.append( 'action', 'kalicart_federation_activate' );
      fd.append( 'nonce',  KaliBridge.nonce );
      fetch( KaliBridge.ajax_url, { method: 'POST', body: fd, credentials: 'same-origin' } )
        .then( r => r.json() )
        .then( res => {
          if ( res.success ) {
            KaliBridge.federation_registered_at = res.data.registered_at;
            KaliBridge.global_consent = true;
            KaliBridge.provider_global_active = true;
            if ( res.data.consent ) KaliBridge.federation_consent = res.data.consent;
            renderFederation();
            renderProviderConsent();
            // Canale OpenAI: SOLO se la casella separata e' spuntata, come atto distinto
            // con la sua ricevuta commerce-consent-1.0 (stesso endpoint del pannello sotto).
            const channelBox = $( 'federationChannelCheckbox' );
            const resultBox  = $( 'federationChannelResult' );
            if ( channelBox && channelBox.checked ) {
              providerConsentRequest( 'kalicart_provider_consent_grant', { accepted: '1' } )
                .then( r2 => {
                  const ok = r2 && r2.success;
                  if ( ok ) { KaliBridge.provider_consent = r2.data.state; KaliBridge.provider_global_active = r2.data.global_active; }
                  if ( resultBox ) {
                    resultBox.style.display = '';
                    resultBox.textContent = ok
                      ? ( KaliBridge.i18n?.federation_channel_granted || 'The channel authorization has been recorded.' )
                      : ( KaliBridge.i18n?.federation_channel_failed || 'The channel authorization could not be recorded.' );
                  }
                } )
                .catch( () => {
                  if ( resultBox ) { resultBox.style.display = ''; resultBox.textContent = KaliBridge.i18n?.federation_channel_failed || 'The channel authorization could not be recorded.'; }
                } )
                .finally( () => { channelBox.checked = false; renderProviderConsent(); } );
            }
          } else {
            actBtn.disabled = false;
            alert( KaliBridge.i18n?.federation_activate_failed || 'Activation failed. Please try again.' );
          }
        } )
        .catch( () => { actBtn.disabled = false; } );
    } );

    // Filtro a due step: il click su "Revoke" NON revoca, mostra la conferma.
    revBtn.addEventListener( 'click', () => {
      const confirmBox = $( 'federationRevokeConfirm' );
      if ( confirmBox ) confirmBox.style.display = '';
      revBtn.style.display = 'none';
    } );

    // "Keep my catalog federated": annulla, torna allo stato registrato.
    $( 'federationRevokeCancelBtn' )?.addEventListener( 'click', () => {
      const confirmBox = $( 'federationRevokeConfirm' );
      if ( confirmBox ) confirmBox.style.display = 'none';
      renderFederation();
    } );

    // "Yes, revoke consent": solo QUI avviene la revoca reale.
    $( 'federationRevokeConfirmBtn' )?.addEventListener( 'click', () => {
      const confirmBtn = $( 'federationRevokeConfirmBtn' );
      confirmBtn.disabled = true;
      const fd = new FormData();
      fd.append( 'action', 'kalicart_federation_revoke' );
      fd.append( 'nonce',  KaliBridge.nonce );
      fetch( KaliBridge.ajax_url, { method: 'POST', body: fd, credentials: 'same-origin' } )
        .then( r => r.json() )
        .then( res => {
          confirmBtn.disabled = false;
          const confirmBox = $( 'federationRevokeConfirm' );
          if ( confirmBox ) confirmBox.style.display = 'none';
          if ( res.success ) {
            KaliBridge.global_consent = false;
            KaliBridge.federation_registered_at = '';
            KaliBridge.provider_global_active = false;
          }
          renderFederation();
          renderProviderConsent();
        } )
        .catch( () => {
          $( 'federationRevokeConfirmBtn' ).disabled = false;
        } );
    } );
  }

  function saveSettings() {
    const fd = new FormData();
    fd.append( 'action',          'kalicart_save_settings' );
    fd.append( 'nonce',           KaliBridge.nonce );
    fd.append( 'badge_enabled',   $( 'toggleBadge' )?.checked   ? '1' : '0' );
    fd.append( 'robots_enabled',  $( 'toggleRobots' )?.checked  ? '1' : '0' );
    fd.append( 'sitemap_enabled',  $( 'toggleSitemap' )?.checked  ? '1' : '0' );
    fd.append( 'checkout_enabled',  $( 'toggleCheckout' )?.checked  ? '1' : '0' );
    fd.append( 'well_known_enabled', $( 'toggleWellKnown' )?.checked    ? '1' : '0' );
    fd.append( 'hint_search',        $( 'toggleHintSearch' )?.checked   ? '1' : '0' );
    fd.append( 'hint_zero',          $( 'toggleHintZero' )?.checked     ? '1' : '0' );
    fd.append( 'hint_category',      $( 'toggleHintCategory' )?.checked ? '1' : '0' );
    fd.append( 'coupons_agent_enabled', $( 'toggleCouponsAgent' )?.checked ? '1' : '0' );
    fd.append( 'coupons_agent_whitelist', collectCouponSelection() );
    fd.append( 'badge_position',  badgePosition );
    fd.append( 'agent_index_url', $( 'agentIndexUrl' )?.value?.trim() ?? '' );
    const slug = $( 'returnPolicySlug' )?.value?.trim() ?? '';
    fd.append( 'return_policy_url', slug ? ( KaliBridge.site_url.replace(/\/$/, '') + '/' + slug.replace(/^\//, '') ) : '' );

    fetch( KaliBridge.ajax_url, { method: 'POST', body: fd, credentials: 'same-origin' } )
      .then( r => r.json() )
      .then( res => {
        if ( res.success ) {
          const notice = $( 'settingsSaved' );
          if ( notice ) { notice.style.display = 'inline'; setTimeout( () => ( notice.style.display = 'none' ), 2500 ); }
          const cnotice = $( 'couponsSaved' );
          if ( cnotice ) { cnotice.style.display = 'inline'; setTimeout( () => ( cnotice.style.display = 'none' ), 2500 ); }

          updateReturnPolicyBlock();
          loadHealth( true );
        }
      } );
  }

  // ── Copy ──────────────────────────────────────────────────────────────────
  function copyHeadLink() {
    const text = $( 'headLinkTag' )?.textContent;
    if ( ! text ) return;
    navigator.clipboard.writeText( text ).catch( () => {} );
    const btn = $( 'copyHeadLink' );
    if ( btn ) { btn.style.color = '#22c55e'; setTimeout( () => ( btn.style.color = '' ), 1500 ); }
  }

  // ── Coupons (agent exposure whitelist) ────────────────────────────────────
  function initCoupons() {
    const master = $( 'toggleCouponsAgent' );
    if ( ! master ) return;
    master.checked = !! KaliBridge.coupons_agent_enabled;

    const hint = $( 'couponsHint' );
    if ( hint ) hint.textContent = KaliBridge.i18n?.coupons_hint || '';

    renderCouponList();
    syncCouponWrap();
    master.addEventListener( 'change', syncCouponWrap );
  }

  function syncCouponWrap() {
    const wrap = $( 'couponsWhitelistWrap' );
    if ( wrap ) wrap.style.display = $( 'toggleCouponsAgent' )?.checked ? 'block' : 'none';
  }

  function renderCouponList() {
    const list = $( 'couponsList' );
    if ( ! list ) return;
    const eligible = Array.isArray( KaliBridge.coupons_eligible ) ? KaliBridge.coupons_eligible : [];
    const selected = ( KaliBridge.coupons_agent_whitelist || [] ).map( Number );

    if ( ! eligible.length ) {
      list.innerHTML = '<p style="font-size:13px;color:var(--kb-muted,#999);margin:0;">'
        + esc( KaliBridge.i18n?.coupons_none || 'No active coupons available to expose.' ) + '</p>';
      return;
    }

    list.innerHTML = eligible.map( c => {
      const checked = selected.includes( Number( c.id ) ) ? ' checked' : '';
      const value = c.type === 'percent'
        ? ( c.amount + '%' )
        : ( c.amount + ' ' + ( KaliBridge.currency || '' ) ).trim();
      return '<label class="kali-coupon-item" style="display:flex;align-items:center;gap:10px;padding:10px 12px;border:1px solid var(--kb-border,#e5e5e5);border-radius:6px;margin-bottom:8px;cursor:pointer;">'
        + '<input type="checkbox" class="kali-coupon-cb" value="' + Number( c.id ) + '"' + checked + ' style="margin:0;">'
        + '<code style="background:var(--kb-code-bg,#fff);border:1px solid var(--kb-border,#ddd);border-radius:4px;padding:2px 8px;font-size:13px;">' + esc( c.code ) + '</code>'
        + '<span style="font-size:12px;color:var(--kb-muted,#777);">' + esc( value ) + '</span>'
        + '</label>';
    } ).join( '' );
  }

  function collectCouponSelection() {
    return Array.from( document.querySelectorAll( '.kali-coupon-cb:checked' ) )
      .map( cb => cb.value ).join( ',' );
  }

  // ── Util ──────────────────────────────────────────────────────────────────
  function setText( id, val ) { const el = $( id ); if ( el ) el.textContent = val; }
  function esc( s ) {
    return String( s ).replace( /&/g,'&amp;' ).replace( /</g,'&lt;' ).replace( />/g,'&gt;' ).replace( /"/g,'&quot;' );
  }
} )();

/* Agent Commerce: la generazione e' sincrona (secondi su cataloghi grandi):
   feedback immediato, bottone disabilitato, spinner attivo fino al reload. */
(function () {
  var form = document.getElementById('kb-acp-form');
  if (!form) return;
  form.addEventListener('submit', function () {
    var btn = form.querySelector('button[type="submit"]');
    var spin = form.querySelector('.spinner');
    // Defer: disabling a submit button synchronously would exclude its
    // name/value from the serialized form data (classic gotcha).
    setTimeout(function () {
      if (btn) { btn.disabled = true; btn.style.opacity = '0.6'; }
      if (spin) { spin.classList.add('is-active'); }
    }, 0);
  });
})();
