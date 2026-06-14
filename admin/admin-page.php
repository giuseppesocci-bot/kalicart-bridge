<?php defined( 'ABSPATH' ) || exit; ?>
<div class="kali-wrap">

  <!-- HEADER -->
  <div class="kali-header">
    <div class="kali-header__brand">
      <div class="logo">
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=kalicart-bridge' ) ); ?>" data-initial-mark="">
          <svg class="kali-logo-mark" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 1196" width="60" height="60" role="img" aria-label="KaliCart" focusable="false" style="display:block;margin-right:8px;flex:0 0 auto"><rect width="1200" height="1196" rx="150" ry="150" fill="#0070F3"/><rect x="280" y="184" width="212" height="824" fill="#FFFFFF"/><path d="M677 184H900V411L720 504Z" fill="#FFFFFF"/><path d="M575 691L780 568L1018 1008H790Z" fill="#FFFFFF"/><path d="M900 411L780 568L575 691L720 504Z" fill="#BFDBFC"/></svg>Kalicart
        </a>
      </div>
      <span class="kali-header__product">Bridge</span>
      <span class="kali-version">v<?php echo esc_html( KALICART_BRIDGE_VERSION ); ?></span>
    </div>
    <span class="kali-header__tagline">Agent-readable catalog</span>
  </div>

  <!-- TABS -->
  <div class="kali-tabs">
    <button class="kali-tab kali-tab--active" data-tab="overview">Overview</button>
    <button class="kali-tab" data-tab="quarantine">Quarantine</button>
    <button class="kali-tab" data-tab="endpoints">Endpoints</button>
    <button class="kali-tab" data-tab="settings">Settings</button>
  </div>

  <!-- LOADING -->
  <div id="kali-loading" class="kali-loading">
    <div class="kali-spinner"></div>
    <p>Analysing catalog&hellip;</p>
  </div>

  <!-- TAB: OVERVIEW -->
  <div id="kali-tab-overview" class="kali-panel" style="display:none">

    <!-- Score + bars row -->
    <div class="kali-score-row">
      <div class="kali-score-box">
        <span class="kali-score-box__num" id="scoreValue">&ndash;</span>
        <span class="kali-score-box__lbl">quality score</span>
      </div>
      <div class="kali-bars-wrap">
        <div class="kali-section-title" style="margin-bottom:12px">Catalog coverage</div>
        <div class="kali-bar-row">
          <span class="kali-bar-lbl">In stock</span>
          <div class="kali-bar"><div class="kali-bar__fill" id="barInStock" style="width:0%"></div></div>
          <span class="kali-bar-pct" id="pctInStock">&ndash;</span>
        </div>
        <div class="kali-bar-row">
          <span class="kali-bar-lbl">Images</span>
          <div class="kali-bar"><div class="kali-bar__fill" id="barImages" style="width:0%"></div></div>
          <span class="kali-bar-pct" id="pctImages">&ndash;</span>
        </div>
        <div class="kali-bar-row">
          <span class="kali-bar-lbl">Description</span>
          <div class="kali-bar"><div class="kali-bar__fill" id="barDesc" style="width:0%"></div></div>
          <span class="kali-bar-pct" id="pctDesc">&ndash;</span>
        </div>
        <div class="kali-bar-row">
          <span class="kali-bar-lbl">SKU</span>
          <div class="kali-bar"><div class="kali-bar__fill" id="barSku" style="width:0%"></div></div>
          <span class="kali-bar-pct" id="pctSku">&ndash;</span>
        </div>
      </div>
    </div>

    <!-- Stats 6 -->
    <div class="kali-stats-grid kali-stats-grid--6">
      <div class="kali-stat">
        <span class="kali-stat__value" id="statTotal">&ndash;</span>
        <span class="kali-stat__label">Total products</span>
      </div>
      <div class="kali-stat kali-stat--green">
        <span class="kali-stat__value" id="statHealthy">&ndash;</span>
        <span class="kali-stat__label">Healthy</span>
      </div>
      <div class="kali-stat kali-stat--red kali-stat--clickable" id="stat-card-quarantine">
        <span class="kali-stat__value" id="statQuarantine">&ndash;</span>
        <span class="kali-stat__label">Quarantine</span>
      </div>
      <div class="kali-stat kali-stat--green">
        <span class="kali-stat__value" id="statInStock">&ndash;</span>
        <span class="kali-stat__label">In stock</span>
      </div>
      <div class="kali-stat kali-stat--red kali-stat--clickable" id="stat-card-out_stock">
        <span class="kali-stat__value" id="statOutStock">&ndash;</span>
        <span class="kali-stat__label">Out of stock</span>
      </div>
      <div class="kali-stat kali-stat--clickable" id="stat-card-no_sku">
        <span class="kali-stat__value" id="statNoSku">&ndash;</span>
        <span class="kali-stat__label">No SKU</span>
      </div>
    </div>

    <!-- Issues -->
    <div class="kali-section-title">Catalog issues</div>
    <div class="kali-issues-grid" id="issuesGrid">
      <div class="kali-issue" id="issue-bad_title">
        <span class="kali-issue__icon" aria-hidden="true"><img src="<?php echo esc_url( KALICART_BRIDGE_URL . 'admin/assets/icons/description-svgrepo-com.svg' ); ?>" alt="" loading="lazy"></span>
        <div><span class="kali-issue__count" id="issueBadTitle">&ndash;</span><span class="kali-issue__label">Bad title</span></div>
      </div>
      <div class="kali-issue" id="issue-no_image">
        <span class="kali-issue__icon" aria-hidden="true"><img src="<?php echo esc_url( KALICART_BRIDGE_URL . 'admin/assets/icons/image-square-xmark-svgrepo-com.svg' ); ?>" alt="" loading="lazy"></span>
        <div><span class="kali-issue__count" id="issueNoImage">&ndash;</span><span class="kali-issue__label">No image</span></div>
      </div>
      <div class="kali-issue" id="issue-no_desc">
        <span class="kali-issue__icon" aria-hidden="true"><img src="<?php echo esc_url( KALICART_BRIDGE_URL . 'admin/assets/icons/description-svgrepo-com.svg' ); ?>" alt="" loading="lazy"></span>
        <div><span class="kali-issue__count" id="issueNoDesc">&ndash;</span><span class="kali-issue__label">No description</span></div>
      </div>
      <div class="kali-issue" id="issue-no_cat">
        <span class="kali-issue__icon" aria-hidden="true"><img src="<?php echo esc_url( KALICART_BRIDGE_URL . 'admin/assets/icons/category-svgrepo-com.svg' ); ?>" alt="" loading="lazy"></span>
        <div><span class="kali-issue__count" id="issueNoCat">&ndash;</span><span class="kali-issue__label">No category</span></div>
      </div>
      <div class="kali-issue" id="issue-no_price">
        <span class="kali-issue__icon" aria-hidden="true"><img src="<?php echo esc_url( KALICART_BRIDGE_URL . 'admin/assets/icons/price-tag-svgrepo-com.svg' ); ?>" alt="" loading="lazy"></span>
        <div><span class="kali-issue__count" id="issueNoPrice">&ndash;</span><span class="kali-issue__label">Zero price</span></div>
      </div>
    </div>

    <!-- Suggestions -->
    <div class="kali-section-title">Suggestions</div>
    <div id="suggestionsList" class="kali-suggestions"></div>

    <div class="kali-refresh-wrap">
      <button id="btnRefresh" class="kali-btn kali-btn--secondary">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 4v6h6"/><path d="M23 20v-6h-6"/><path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10M23 14l-4.64 4.36A9 9 0 0 1 3.51 15"/></svg>
        Refresh
      </button>
      <span class="kali-last-updated" id="lastUpdated"></span>
    </div>
  </div>

  <!-- TAB: QUARANTINE -->
  <div id="kali-tab-quarantine" class="kali-panel" style="display:none">
    <div class="kali-section-title">
      Quarantined products
      <span class="kali-badge kali-badge--red" id="quarantineCount">&ndash;</span>
    </div>
    <p class="kali-hint">Products with blocking computability issues: missing descriptions, categories, or invalid prices.</p>
    <div id="quarantineList" class="kali-quarantine-list"></div>
  </div>

  <!-- TAB: ENDPOINTS -->
  <div id="kali-tab-endpoints" class="kali-panel" style="display:none">
    <div class="kali-section-title">API Endpoints</div>
    <p class="kali-hint">Read-only REST surfaces. No authentication required except <code>/health</code>.</p>
    <div class="kali-endpoint-head">
      <div class="kali-link-tag-wrap">
        <code id="headLinkTag"></code>
        <button class="kali-btn kali-btn--icon" id="copyHeadLink" title="Copy tag">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
        </button>
      </div>
    </div>
    <div class="kali-file-links">
      <a class="kali-file-link" href="<?php echo esc_url( home_url( '/sitemap-agentic-bridge.xml' ) ); ?>" target="_blank">sitemap-agentic-bridge.xml</a>
      <a class="kali-file-link" href="<?php echo esc_url( home_url( '/robots.txt' ) ); ?>" target="_blank">robots.txt</a>
    </div>
    <div id="endpointList" class="kali-endpoint-list"></div>
  </div>

  <!-- TAB: SETTINGS -->
  <div id="kali-tab-settings" class="kali-panel" style="display:none">

    <?php
    $kbridge_rp_full    = get_option( 'kalicart_bridge_return_policy_url', '' );
    $kbridge_rp_base    = trailingslashit( get_site_url() );
    $kbridge_rp_slug    = $kbridge_rp_full ? ltrim( str_replace( $kbridge_rp_base, '', $kbridge_rp_full ), '/' ) : '';
    $kbridge_rp_set     = ! empty( $kbridge_rp_slug );
    $kbridge_rp_color   = $kbridge_rp_set ? '#00a32a' : '#f0a000';
    $kbridge_rp_bg      = $kbridge_rp_set ? '#f0fff4' : '#fff8f0';
    $kbridge_rp_badge   = $kbridge_rp_set ? 'CONFIGURED' : 'REQUIRED';
    ?>
    <div id="returnPolicyBlock" style="margin-bottom:24px;padding:16px 20px;background:<?php echo esc_attr( $kbridge_rp_bg ); ?>;border:1px solid <?php echo esc_attr( $kbridge_rp_color ); ?>;border-radius:8px;">
      <div style="display:flex;align-items:flex-start;gap:10px;margin-bottom:12px;">
        <span id="returnPolicyBadge" style="display:inline-block;background:<?php echo esc_attr( $kbridge_rp_color ); ?>;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:4px;white-space:nowrap;margin-top:2px;"><?php echo esc_html( $kbridge_rp_badge ); ?></span>
        <div>
          <strong style="font-size:13px;color:#1d2327;">Refund and Returns Policy URL</strong>
          <p style="font-size:12px;color:#666;margin:4px 0 0;">
            Add your refund and returns policy page so AI agents can inform buyers about your return conditions.
            Without this, the catalog health score is reduced by 10 points.
          </p>
        </div>
      </div>
      <div id="returnPolicyWrap" style="display:flex;align-items:center;gap:0;border:1px solid <?php echo esc_attr( $kbridge_rp_color ); ?>;border-radius:4px;overflow:hidden;">
        <span id="returnPolicyPrefix" style="padding:6px 10px;background:#f9f9f9;border-right:1px solid <?php echo esc_attr( $kbridge_rp_color ); ?>;font-size:13px;color:#555;white-space:nowrap;"><?php echo esc_html( $kbridge_rp_base ); ?></span>
        <input type="text" id="returnPolicySlug" placeholder="refund-policy"
          value="<?php echo esc_attr( $kbridge_rp_slug ); ?>"
          style="flex:1;padding:6px 10px;border:none;font-size:13px;outline:none;" />
      </div>
      <div id="returnPolicyTestLink" style="margin-top:8px;font-size:12px;<?php echo $kbridge_rp_set ? '' : 'display:none;'; ?>">
        <?php if ( $kbridge_rp_set ) : ?>
        <a href="<?php echo esc_url( $kbridge_rp_full ); ?>" target="_blank" rel="noopener" style="color:#00a32a;">&#x1F517; Test link: <?php echo esc_html( $kbridge_rp_full ); ?></a>
        <?php endif; ?>
      </div>
    </div>

    <div class="kali-section-title">Signal settings</div>
    <div class="kali-settings-list">

      <div class="kali-toggle-group">
        <label class="kali-toggle-row">
          <div class="kali-toggle-info">
            <strong>AI catalog badge</strong>
            <span>Speaking HTML badge visible on the storefront — machine-readable anchor for AI agents.</span>
          </div>
          <div class="kali-toggle">
            <input type="checkbox" id="toggleBadge">
            <span class="kali-toggle__slider"></span>
          </div>
        </label>
        <div class="kali-badge-position" id="badgePositionWrap">
          <span class="kali-pos-label">Position</span>
          <div class="kali-pos-grid">
            <?php
            $kalicart_positions = [ 'top-left' => 'Top left', 'top-right' => 'Top right', 'bottom-left' => 'Bottom left', 'bottom-right' => 'Bottom right' ];
            $kalicart_current   = get_option( 'kalicart_bridge_badge_position', 'bottom-right' );
            foreach ( $kalicart_positions as $kalicart_val => $kalicart_label ) :
              $kalicart_active = $kalicart_current === $kalicart_val ? ' kali-pos-btn--active' : '';
            ?>
            <button class="kali-pos-btn<?php echo esc_attr( $kalicart_active ); ?>" data-pos="<?php echo esc_attr( $kalicart_val ); ?>"><?php echo esc_html( $kalicart_label ); ?></button>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <label class="kali-toggle-row">
        <div class="kali-toggle-info">
          <strong>robots.txt directive</strong>
          <span>Adds <code>Allow: /wp-json/kalicart/</code> to robots.txt.</span>
        </div>
        <div class="kali-toggle"><input type="checkbox" id="toggleRobots"><span class="kali-toggle__slider"></span></div>
      </label>

      <label class="kali-toggle-row">
        <div class="kali-toggle-info">
          <strong>KaliCart Global indexing consent</strong>
          <span>Allows KaliCart Global to index this catalog and include it in federated agent search. Published in the discovery document as <code>crawler_policy.allow_global_indexing</code> and <code>intent_flags</code>. Read-only: Global never writes to your store.</span>
        </div>
        <div class="kali-toggle"><input type="checkbox" id="toggleGlobalConsent"><span class="kali-toggle__slider"></span></div>
      </label>

      <label class="kali-toggle-row">
        <div class="kali-toggle-info">
          <strong>Checkout sessions <span style="font-size:11px;font-weight:500;background:var(--kb-acc-bg);color:var(--kb-acc);border:1px solid rgba(0,112,243,.2);border-radius:99px;padding:1px 8px;vertical-align:middle">optional</span></strong>
          <span>Enables <code>POST /checkout/session</code> — agent creates a session, merchant gets a checkout URL, human completes payment on WooCommerce. No OAuth, no PII, no payment on the agent side.</span>
        </div>
        <div class="kali-toggle"><input type="checkbox" id="toggleCheckout"><span class="kali-toggle__slider"></span></div>
      </label>

      <label class="kali-toggle-row">
        <div class="kali-toggle-info">
          <strong>Agentic sitemap</strong>
          <span>Serves <a href="<?php echo esc_url( home_url( '/sitemap-agentic-bridge.xml' ) ); ?>" target="_blank">sitemap-agentic-bridge.xml</a> with annotated catalog endpoints.</span>
        </div>
        <div class="kali-toggle"><input type="checkbox" id="toggleSitemap"><span class="kali-toggle__slider"></span></div>
      </label>

      <label class="kali-toggle-row">
        <div class="kali-toggle-info">
          <strong>Agent discovery files <small style="font-size:11px;font-weight:400;color:var(--kb-muted)">.well-known/</small></strong>
          <span>Writes <code>/.well-known/kalicart-bridge</code> and <code>/.well-known/agent-catalog</code> — standard discovery points for AI agents that probe the site before navigating it.</span>
        </div>
        <div class="kali-toggle"><input type="checkbox" id="toggleWellKnown"><span class="kali-toggle__slider"></span></div>
      </label>

      <div class="kali-section-title" style="margin-top:20px">Agent discovery hints</div>

      <label class="kali-toggle-row">
        <div class="kali-toggle-info">
          <strong>Search form link</strong>
          <span>Appends a visible "Structured catalog for AI agents" link after every search form.</span>
        </div>
        <div class="kali-toggle"><input type="checkbox" id="toggleHintSearch"><span class="kali-toggle__slider"></span></div>
      </label>

      <label class="kali-toggle-row">
        <div class="kali-toggle-info">
          <strong>Search results link</strong>
          <span>Shows a minimal "Machine-readable catalog" link at the bottom of search pages — both on zero-results and when results are ambiguous or partial.</span>
        </div>
        <div class="kali-toggle"><input type="checkbox" id="toggleHintZero"><span class="kali-toggle__slider"></span></div>
      </label>

      <label class="kali-toggle-row">
        <div class="kali-toggle-info">
          <strong>Category &amp; product page links</strong>
          <span>Shows a "Machine-readable category data" link on category pages and a "Machine-readable product data" link above the product meta on single product pages — both pointing to the structured API endpoint.</span>
        </div>
        <div class="kali-toggle"><input type="checkbox" id="toggleHintCategory"><span class="kali-toggle__slider"></span></div>
      </label>

    </div>

    <div style="margin:24px 0 0;padding:16px 20px;background:#f9f9f9;border:1px solid #e5e5e5;border-radius:6px;">
      <div class="kali-section-title" style="margin-bottom:10px">Agent entry-point page <span style="font-size:11px;font-weight:400;text-transform:none;letter-spacing:0;color:#999;">(optional)</span></div>
      <p style="margin:0 0 10px;font-size:13px;color:#555;line-height:1.6;">
        If you want to expose a dedicated machine-readable index for AI agents — a structured directory of your catalog endpoints and category tree — you can create a WordPress page and add this shortcode. The page will render a navigable tree of your catalog API, readable by both agents and curious humans. It is not required: all discovery signals work automatically without it.
      </p>
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;">
        <code style="background:#fff;border:1px solid #ddd;border-radius:4px;padding:6px 12px;font-size:13px;color:#1d2327;user-select:all;">[kalicart_agent_index]</code>
        <span style="font-size:12px;color:#999;">Copy and paste into any WordPress page</span>
      </div>
      <div style="display:flex;align-items:center;gap:10px;">
        <label style="font-size:13px;color:#555;white-space:nowrap;">Agent index page URL</label>
        <input type="url" id="agentIndexUrl" placeholder="https://yoursite.com/ai-catalog"
          value="<?php echo esc_attr( get_option( 'kalicart_bridge_agent_index_url', '' ) ); ?>"
          style="flex:1;padding:6px 10px;border:1px solid #ddd;border-radius:4px;font-size:13px;" />
        <span style="font-size:12px;color:#999;">Paste the URL of the page where you added the shortcode</span>
      </div>
    </div>

    <div class="kali-settings-footer">
      <button id="btnSaveSettings" class="kali-btn kali-btn--primary">Save settings</button>
      <span id="settingsSaved" class="kali-saved-notice" style="display:none">&#x2713; Saved</span>
    </div>
  </div>

</div>
