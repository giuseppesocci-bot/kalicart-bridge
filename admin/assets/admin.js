/* global KaliBridge */
( function () {
  'use strict';

  let report       = null;
  let badgePosition = 'bottom-right';
  let activeIssueFilter = null;

  const $ = id => document.getElementById( id );
  const $$ = sel => document.querySelectorAll( sel );

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
      } );
    } );
  }

  function switchTab( name ) {
    $$( '.kali-tab' ).forEach( b => b.classList.toggle( 'kali-tab--active', b.dataset.tab === name ) );
    $$( '.kali-panel' ).forEach( p => ( p.style.display = 'none' ) );
    const panel = $( 'kali-tab-' + name );
    if ( panel ) panel.style.display = 'block';
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
          showError( res.data?.message || 'Unknown error' );
        }
      } )
      .catch( e => showError( e.message ) )
      .finally( () => showLoading( false ) );
  }

  function showLoading( yes ) {
    const loading = $( 'kali-loading' );
    const panel   = $( 'kali-tab-overview' );
    if ( ! loading || ! panel ) return;
    loading.style.display = yes ? 'block' : 'none';
    if ( ! yes && $$( '.kali-tab--active' )[0]?.dataset.tab === 'overview' ) panel.style.display = 'block';
    if ( yes ) panel.style.display = 'none';
  }

  function showError( msg ) {
    const loading = $( 'kali-loading' );
    if ( loading ) loading.innerHTML = `<p style="color:#ef4444">Error: ${ esc( msg ) }</p>`;
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
        ? '<div class="kali-empty">&#x1F389; No issues. Catalog looks great!</div>'
        : sug.map( s => `
          <div class="kali-suggestion" data-issue-code="${ esc( s.code ) }">
            <div class="kali-suggestion__dot kali-suggestion__dot--${ s.priority }"></div>
            <div class="kali-suggestion__content">
              <div class="kali-suggestion__label">${ esc( s.label ) }</div>
              <div class="kali-suggestion__detail">${ esc( s.detail ) }</div>
            </div>
            ${ s.affected != null
              ? `<button class="kali-suggestion__affected" type="button" data-view-issue="${ esc( s.code ) }">${ s.affected } products</button>`
              : ( s.admin_url ? `<button class="kali-suggestion__affected" type="button" data-switch-tab="settings">Configure →</button>` : '' )
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
    if ( lu && data.generated_at ) lu.textContent = 'Updated: ' + new Date( data.generated_at ).toLocaleTimeString();
  }

  function setIssue( id, count ) {
    const el = $( id );
    if ( ! el ) return;
    el.textContent = count ?? 0;
    el.style.color = count > 0 ? '#ef4444' : '#22c55e';
  }

  // ── Quarantine ────────────────────────────────────────────────────────────
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
      container.innerHTML = issueIntro + ( activeIssueFilter
        ? `<div class="kali-empty">&#x2713; No products for ${ esc( activeIssueFilter ) }.</div>`
        : '<div class="kali-empty">&#x2713; No products in quarantine.</div>' );
      return;
    }

    const filterBar = activeIssueFilter
      ? `<div class="kali-q-filter">Showing ${ esc( activeIssueFilter ) } <button type="button" id="clearIssueFilter">Clear filter</button></div>`
      : '';

    container.innerHTML = issueIntro + filterBar + items.map( item => {
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
          ${ item.url ? `<a class="kali-q-item__edit" href="${ esc( item.url ) }" target="_blank">Open product &rarr;</a>` : '' }
        </div>`;
    } ).join( '' );
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
        title: 'Why product titles matter',
        detail: 'Agents use the title as the first matching signal. Titles should contain at least three useful words, usually product type, brand and model or variant.'
      },
      NO_DESCRIPTION: {
        title: 'Why descriptions matter',
        detail: 'Descriptions give agents the extra attributes that are often missing from filters, such as fit, material, use case, style and compatibility.'
      },
      NO_CATEGORY: {
        title: 'Why categories matter',
        detail: 'Categories let agents browse and narrow the catalog even when the user does not know the exact product name used by the merchant.'
      },
      ZERO_PRICE: {
        title: 'Why price matters',
        detail: 'Price is required for budget checks, comparisons and purchase decisions. Products without a valid price cannot be trusted in commerce-intent results.'
      },
      NO_IMAGE: {
        title: 'Why images matter',
        detail: 'Images do not block agent queries, but they improve visual verification and reduce ambiguity when products have similar names or variants.'
      },
      NO_SKU: {
        title: 'Why SKUs matter',
        detail: 'SKUs do not block agent queries, but they help identify, deduplicate and reconcile exact products across syncs, variants and downstream systems.'
      },
      OUT_OF_STOCK: {
        title: 'Why stock matters',
        detail: 'Availability tells agents whether a product can be proposed now or should be excluded from purchase-ready results.'
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
      { path: '/.well-known/ucp',       desc: 'UCP profile — dev.ucp.shopping.catalog.search + catalog.lookup (v2026-04-08)',  auth: false, example: '', wellknown: true },
      { path: '/.well-known/kalicart-bridge', desc: 'KaliCart Bridge discovery entry point for agents probing well-known paths', auth: false, example: '', wellknown: true },
      { path: '/discovery',            desc: 'Discovery document — entry point for every agent, full capability map',           auth: false, example: '' },
      { path: '/catalog/meta',         desc: 'Accepted filter values, category slugs, price range',                             auth: false, example: '' },
      { path: '/catalog/search',       desc: 'Full-text + filtered search — supports q, category, on_sale, in_stock, price',   auth: false, example: '?q=scarpe&gender=male&per_page=10' },
      { path: '/catalog/products',     desc: 'Browse products by filters (no text query needed)',                               auth: false, example: '?category=elettronica&in_stock=true' },
      { path: '/catalog/product/{id}', desc: 'Single product — price, stock, variants[], barcodes, purchase_readiness',        auth: false, example: '' },
      { path: '/catalog/categories',   desc: 'Full merchant category tree with has_products flag',                              auth: false, example: '' },
      { path: '/catalog/health',       desc: 'Catalog quality report — quarantine list, suggestions, scores',                  auth: true,  example: '?force=true' },
      { path: '/checkout/session',     desc: 'POST — agent creates cart session, returns cart_url and checkout_url for buyer', auth: false, example: '', method: 'POST' },
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
            ${ ep.auth ? '<span class="kali-endpoint__auth">admin only</span>' : '' }
          </div>
          <div class="kali-endpoint__body" id="ep-body-${ i }">
            <div class="kali-endpoint__url">
              ${ canPreview
                  ? `<a href="${ esc( url + ep.example ) }" target="_blank">${ esc( url ) }</a>${ ep.example ? ' <span style="color:#475569">' + esc( ep.example ) + '</span>' : '' }`
                  : esc( url )
              }
            </div>
            ${ canPreview ? `<button class="kali-preview-btn" onclick="window.kaliPreview(${ i }, '${ encodeURIComponent( url + ep.example ) }')">&#x25B6; Preview</button>` : '' }
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
    preview.textContent = 'Loading…';
    fetch( decodeURIComponent( encodedUrl ) )
      .then( r => r.json() )
      .then( data => {
        if ( data.products?.length > 3 )             { data.products = data.products.slice( 0, 3 ); data._truncated = true; }
        if ( data.quarantined_products?.length > 3 ) { data.quarantined_products = data.quarantined_products.slice( 0, 3 ); data._truncated = true; }
        preview.textContent = JSON.stringify( data, null, 2 );
      } )
      .catch( e => ( preview.textContent = 'Error: ' + e.message ) );
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

    // Alert gialli su toggle critici
    const WARNINGS = {
      toggleBadge:      'Disabling the AI catalog badge removes a key discovery signal for agents browsing the storefront DOM. Agents that rely on body anchors will not find your catalog.',
      toggleRobots:     'Disabling the robots.txt directive removes the crawl permission for AI agents. Some agents check robots.txt before querying any endpoint.',
      toggleSitemap:    'Disabling the agentic sitemap removes the structured endpoint map that agents use to enumerate your catalog surfaces.',
      toggleWellKnown:  'Disabling .well-known discovery files removes the first-probe signal used by agents that check standard discovery paths before loading your storefront.',
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
            div.innerHTML = '<strong>⚠ Heads up</strong> ' + esc( msg );
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
    fd.append( 'badge_position',  badgePosition );
    fd.append( 'agent_index_url', $( 'agentIndexUrl' )?.value?.trim() ?? '' );
    fd.append( 'return_policy_url', $( 'returnPolicyUrl' )?.value?.trim() ?? '' );

    fetch( KaliBridge.ajax_url, { method: 'POST', body: fd, credentials: 'same-origin' } )
      .then( r => r.json() )
      .then( res => {
        if ( res.success ) {
          const notice = $( 'settingsSaved' );
          if ( notice ) { notice.style.display = 'inline'; setTimeout( () => ( notice.style.display = 'none' ), 2500 ); }
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

  // ── Util ──────────────────────────────────────────────────────────────────
  function setText( id, val ) { const el = $( id ); if ( el ) el.textContent = val; }
  function esc( s ) {
    return String( s ).replace( /&/g,'&amp;' ).replace( /</g,'&lt;' ).replace( />/g,'&gt;' ).replace( /"/g,'&quot;' );
  }
} )();
