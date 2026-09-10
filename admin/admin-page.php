<?php
defined( 'ABSPATH' ) || exit;

// Keep the selected tab stable across refreshes and form submissions.
$kalicart_bridge_allowed_tabs = [ 'overview', 'quarantine', 'endpoints', 'agent-commerce', 'settings', 'coupons', 'stats' ];
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only UI selection; no state change.
$kalicart_bridge_active_tab   = sanitize_key( wp_unslash( $_GET['tab'] ?? 'overview' ) );
if ( ! in_array( $kalicart_bridge_active_tab, $kalicart_bridge_allowed_tabs, true ) ) {
  $kalicart_bridge_active_tab = 'overview';
}
?>
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
    <span class="kali-header__tagline"><?php esc_html_e( 'Agent-readable catalog', 'kalicart-bridge' ); ?></span>
  </div>

  <!-- FEDERATION (sempre visibile, sotto l'header): announce + revoca consensuale -->
  <div class="kali-federation-block" style="margin:0 0 4px;padding:14px 16px;border:1px solid var(--kb-border,#e2e4e7);border-radius:10px;background:var(--kb-acc-bg,#f6f9ff)">
    <?php $kalicart_bridge_is_federated = (bool) get_option( 'kalicart_bridge_federation_registered_at', '' ); ?>
    <strong style="display:block;margin-bottom:6px"><?php
      echo esc_html( $kalicart_bridge_is_federated
        ? __( 'Great choice! Your catalog is going global.', 'kalicart-bridge' )
        : __( 'Increase your catalog\'s visibility', 'kalicart-bridge' )
      ); ?></strong>
    <p style="margin:0 0 10px;font-size:13px;line-height:1.5;color:var(--kb-muted,#555)">
      <?php if ( $kalicart_bridge_is_federated ) : ?>
        <?php echo wp_kses_post( sprintf(
          /* translators: %1$s: opening link tag to privacy notice, %2$s: closing link tag */
          __( 'AI assistants can now find your products across the KaliCart global network. Only public catalog data is shared, and you can deactivate it anytime. %1$sSee what is shared%2$s.', 'kalicart-bridge' ),
          '<a href="https://bridge.kalicart.com/privacy/" target="_blank" rel="noopener">',
          '</a>'
        ) ); ?>
      <?php else : ?>
        <?php echo wp_kses_post( sprintf(
          /* translators: %1$s: opening link tag to privacy notice, %2$s: closing link tag */
          __( 'Activate the Federated Catalog for free: your products join a global network where AI assistants can find them. It only takes one click, and you can deactivate it anytime. %1$sSee what is shared%2$s.', 'kalicart-bridge' ),
          '<a href="https://bridge.kalicart.com/privacy/" target="_blank" rel="noopener">',
          '</a>'
        ) ); ?>
      <?php endif; ?>
    </p>
    <button type="button" class="kali-btn kali-btn--primary" id="federationActivateBtn"><?php esc_html_e( 'Activate Federated Catalog', 'kalicart-bridge' ); ?></button>
    <button type="button" class="kali-btn kali-btn--secondary" id="federationRevokeBtn" style="display:none"><?php esc_html_e( 'Revoke consent', 'kalicart-bridge' ); ?></button>
    <span id="federationStatus" style="display:none;margin-left:10px;font-size:13px;color:var(--kb-ok,#00a32a)"></span>
    <span id="federationHint" style="display:none;margin-left:10px;font-size:12px;color:var(--kb-muted,#888)"><?php esc_html_e( 'Use the Federated Catalog banner above to manage consent.', 'kalicart-bridge' ); ?></span>

    <!-- External Agent Visibility Check: read-only, shows what KaliCart Global observed
         from OUTSIDE this site the last time it probed (hosting/cache/CDN/robots can make
         a correctly-configured Bridge invisible to real agents; this surfaces that gap). -->
    <div style="margin-top:14px;padding-top:12px;border-top:1px solid var(--kb-border,#e2e2e2)">
      <button type="button" class="kali-btn kali-btn--secondary" id="externalVisibilityBtn"><?php esc_html_e( 'Check external visibility', 'kalicart-bridge' ); ?></button>
      <p style="margin:6px 0 0;font-size:12px;color:var(--kb-muted,#888)"><?php esc_html_e( 'Shows KaliCart Global’s latest periodic observation of your discovery endpoint — not a live scan. It checks discovery reachability only, not MCP, the ChatGPT feed, or checkout.', 'kalicart-bridge' ); ?></p>
      <div id="externalVisibilityResult" style="margin-top:8px;font-size:13px"></div>
    </div>

    <!-- Filtro revoca a due step (stile plugin: kali-warn-alert). Nascosto finche' non si clicca Revoke. -->
    <div id="federationRevokeConfirm" class="kali-warn-alert" style="display:none;margin-top:12px">
      <strong>&#9888; <?php esc_html_e( 'Heads up', 'kalicart-bridge' ); ?></strong>
      <span><?php esc_html_e( 'Revoking removes your catalog from KaliCart Global federated search. Agents using the federated index will no longer discover your products there. Your data is parked, not deleted, and restored if you re-activate.', 'kalicart-bridge' ); ?></span>
      <div style="margin-top:10px">
        <button type="button" class="kali-btn kali-btn--secondary" id="federationRevokeConfirmBtn"><?php esc_html_e( 'Yes, revoke consent', 'kalicart-bridge' ); ?></button>
        <button type="button" class="kali-btn kali-btn--primary" id="federationRevokeCancelBtn"><?php esc_html_e( 'Keep my catalog federated', 'kalicart-bridge' ); ?></button>
      </div>
    </div>
  </div>

  <!-- TABS -->
  <div class="kali-tabsrow">
    <div class="kali-tabs">
      <button class="kali-tab<?php echo 'overview' === $kalicart_bridge_active_tab ? ' kali-tab--active' : ''; ?>" data-tab="overview"><?php esc_html_e( 'Overview', 'kalicart-bridge' ); ?></button>
      <button class="kali-tab<?php echo 'quarantine' === $kalicart_bridge_active_tab ? ' kali-tab--active' : ''; ?>" data-tab="quarantine"><?php esc_html_e( 'Quality Signals', 'kalicart-bridge' ); ?></button>
      <button class="kali-tab<?php echo 'endpoints' === $kalicart_bridge_active_tab ? ' kali-tab--active' : ''; ?>" data-tab="endpoints"><?php esc_html_e( 'Endpoints', 'kalicart-bridge' ); ?></button>
      <button class="kali-tab<?php echo 'agent-commerce' === $kalicart_bridge_active_tab ? ' kali-tab--active' : ''; ?>" data-tab="agent-commerce">ChatGPT Feed</button>
      <button class="kali-tab<?php echo 'settings' === $kalicart_bridge_active_tab ? ' kali-tab--active' : ''; ?>" data-tab="settings"><?php esc_html_e( 'Settings', 'kalicart-bridge' ); ?></button>
      <button class="kali-tab<?php echo 'coupons' === $kalicart_bridge_active_tab ? ' kali-tab--active' : ''; ?>" data-tab="coupons"><?php esc_html_e( 'Coupons', 'kalicart-bridge' ); ?></button>
      <button class="kali-tab<?php echo 'stats' === $kalicart_bridge_active_tab ? ' kali-tab--active' : ''; ?>" data-tab="stats"><?php esc_html_e( 'Stats', 'kalicart-bridge' ); ?></button>
    </div>
  </div>

  <!-- LOADING -->
  <div id="kali-loading" class="kali-loading"<?php echo 'overview' === $kalicart_bridge_active_tab ? '' : ' style="display:none"'; ?>>
    <div class="kali-spinner"></div>
    <p><?php esc_html_e( 'Analysing catalog…', 'kalicart-bridge' ); ?></p>
  </div>

  <!-- TAB: OVERVIEW -->
  <div id="kali-tab-overview" class="kali-panel" style="display:none">

    <!-- Score + bars row -->
    <div class="kali-score-row">
      <div class="kali-score-box">
        <span class="kali-score-box__num" id="scoreValue">&ndash;</span>
        <span class="kali-score-box__lbl"><?php esc_html_e( 'quality score', 'kalicart-bridge' ); ?></span>
      </div>
      <div class="kali-bars-wrap">
        <div class="kali-section-title" style="margin-bottom:12px"><?php esc_html_e( 'Catalog coverage', 'kalicart-bridge' ); ?></div>
        <div class="kali-bar-row">
          <span class="kali-bar-lbl"><?php esc_html_e( 'In stock', 'kalicart-bridge' ); ?></span>
          <div class="kali-bar"><div class="kali-bar__fill" id="barInStock" style="width:0%"></div></div>
          <span class="kali-bar-pct" id="pctInStock">&ndash;</span>
        </div>
        <div class="kali-bar-row">
          <span class="kali-bar-lbl"><?php esc_html_e( 'Images', 'kalicart-bridge' ); ?></span>
          <div class="kali-bar"><div class="kali-bar__fill" id="barImages" style="width:0%"></div></div>
          <span class="kali-bar-pct" id="pctImages">&ndash;</span>
        </div>
        <div class="kali-bar-row">
          <span class="kali-bar-lbl"><?php esc_html_e( 'Description', 'kalicart-bridge' ); ?></span>
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
        <span class="kali-stat__label"><?php esc_html_e( 'Total products', 'kalicart-bridge' ); ?></span>
      </div>
      <div class="kali-stat kali-stat--green">
        <span class="kali-stat__value" id="statHealthy">&ndash;</span>
        <span class="kali-stat__label"><?php esc_html_e( 'Healthy', 'kalicart-bridge' ); ?></span>
      </div>
      <div class="kali-stat kali-stat--red kali-stat--clickable" id="stat-card-quarantine">
        <span class="kali-stat__value" id="statQuarantine">&ndash;</span>
        <span class="kali-stat__label"><?php esc_html_e( 'Quality signals', 'kalicart-bridge' ); ?></span>
      </div>
      <div class="kali-stat kali-stat--green">
        <span class="kali-stat__value" id="statInStock">&ndash;</span>
        <span class="kali-stat__label"><?php esc_html_e( 'In stock', 'kalicart-bridge' ); ?></span>
      </div>
      <div class="kali-stat kali-stat--red kali-stat--clickable" id="stat-card-out_stock">
        <span class="kali-stat__value" id="statOutStock">&ndash;</span>
        <span class="kali-stat__label"><?php esc_html_e( 'Out of stock', 'kalicart-bridge' ); ?></span>
      </div>
      <div class="kali-stat kali-stat--clickable" id="stat-card-no_sku">
        <span class="kali-stat__value" id="statNoSku">&ndash;</span>
        <span class="kali-stat__label"><?php esc_html_e( 'No SKU', 'kalicart-bridge' ); ?></span>
      </div>
    </div>

    <!-- Issues -->
    <div class="kali-section-title"><?php esc_html_e( 'Catalog issues', 'kalicart-bridge' ); ?></div>
    <div class="kali-issues-grid" id="issuesGrid">
      <div class="kali-issue" id="issue-bad_title">
        <span class="kali-issue__icon" aria-hidden="true"><img src="<?php echo esc_url( KALICART_BRIDGE_URL . 'admin/assets/icons/description-svgrepo-com.svg' ); ?>" alt="" loading="lazy"></span>
        <div><span class="kali-issue__count" id="issueBadTitle">&ndash;</span><span class="kali-issue__label"><?php esc_html_e( 'Bad title', 'kalicart-bridge' ); ?></span></div>
      </div>
      <div class="kali-issue" id="issue-no_image">
        <span class="kali-issue__icon" aria-hidden="true"><img src="<?php echo esc_url( KALICART_BRIDGE_URL . 'admin/assets/icons/image-square-xmark-svgrepo-com.svg' ); ?>" alt="" loading="lazy"></span>
        <div><span class="kali-issue__count" id="issueNoImage">&ndash;</span><span class="kali-issue__label"><?php esc_html_e( 'No image', 'kalicart-bridge' ); ?></span></div>
      </div>
      <div class="kali-issue" id="issue-no_desc">
        <span class="kali-issue__icon" aria-hidden="true"><img src="<?php echo esc_url( KALICART_BRIDGE_URL . 'admin/assets/icons/description-svgrepo-com.svg' ); ?>" alt="" loading="lazy"></span>
        <div><span class="kali-issue__count" id="issueNoDesc">&ndash;</span><span class="kali-issue__label"><?php esc_html_e( 'No description', 'kalicart-bridge' ); ?></span></div>
      </div>
      <div class="kali-issue" id="issue-no_cat">
        <span class="kali-issue__icon" aria-hidden="true"><img src="<?php echo esc_url( KALICART_BRIDGE_URL . 'admin/assets/icons/category-svgrepo-com.svg' ); ?>" alt="" loading="lazy"></span>
        <div><span class="kali-issue__count" id="issueNoCat">&ndash;</span><span class="kali-issue__label"><?php esc_html_e( 'No category', 'kalicart-bridge' ); ?></span></div>
      </div>
      <div class="kali-issue" id="issue-no_price">
        <span class="kali-issue__icon" aria-hidden="true"><img src="<?php echo esc_url( KALICART_BRIDGE_URL . 'admin/assets/icons/price-tag-svgrepo-com.svg' ); ?>" alt="" loading="lazy"></span>
        <div><span class="kali-issue__count" id="issueNoPrice">&ndash;</span><span class="kali-issue__label"><?php esc_html_e( 'Zero price', 'kalicart-bridge' ); ?></span></div>
      </div>
    </div>

    <!-- Suggestions -->
    <div class="kali-section-title"><?php esc_html_e( 'Suggestions', 'kalicart-bridge' ); ?></div>
    <div id="suggestionsList" class="kali-suggestions"></div>

    <div class="kali-refresh-wrap">
      <button id="btnRefresh" class="kali-btn kali-btn--secondary">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 4v6h6"/><path d="M23 20v-6h-6"/><path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10M23 14l-4.64 4.36A9 9 0 0 1 3.51 15"/></svg>
        <?php esc_html_e( 'Refresh', 'kalicart-bridge' ); ?>
      </button>
      <span class="kali-last-updated" id="lastUpdated"></span>
    </div>
  </div>

  <!-- TAB: QUARANTINE -->
  <div id="kali-tab-quarantine" class="kali-panel" style="display:<?php echo 'quarantine' === $kalicart_bridge_active_tab ? 'block' : 'none'; ?>">
    <div class="kali-section-title">
      <?php esc_html_e( 'Products with quality signals', 'kalicart-bridge' ); ?>
      <span class="kali-badge kali-badge--red" id="quarantineCount">&ndash;</span>
    </div>
    <p class="kali-hint"><?php esc_html_e( 'Nothing here is blocked or hidden: these products stay fully served to agents in catalog, search and MCP. Their issues travel with the data as declared quality flags and a lower score, and agents can weigh them. Fix the signals to raise computability - use the filters below to work each problem in the native Products list.', 'kalicart-bridge' ); ?></p>
    <div id="quarantineList" class="kali-quarantine-list"></div>
  </div>

  <!-- TAB: ENDPOINTS -->
  <div id="kali-tab-endpoints" class="kali-panel" style="display:<?php echo 'endpoints' === $kalicart_bridge_active_tab ? 'block' : 'none'; ?>">
    <div class="kali-section-title"><?php esc_html_e( 'API Endpoints', 'kalicart-bridge' ); ?></div>
    <p class="kali-hint"><?php echo wp_kses_post( sprintf( /* translators: %s: the /health endpoint path, shown as code */ __( 'Read-only REST surfaces. No authentication required except %s.', 'kalicart-bridge' ), '<code>/health</code>' ) ); ?></p>
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

  <!-- TAB: AGENT COMMERCE -->
  <div id="kali-tab-agent-commerce" class="kali-panel" style="display:<?php echo 'agent-commerce' === $kalicart_bridge_active_tab ? 'block' : 'none'; ?>">
    <?php KaliCart_Bridge_ACP_Feed::render_panel(); ?>
  </div>

  <!-- TAB: SETTINGS -->
  <div id="kali-tab-settings" class="kali-panel" style="display:<?php echo 'settings' === $kalicart_bridge_active_tab ? 'block' : 'none'; ?>">

    <?php
    // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- local vars in included template, not actual globals
    $kbridge_rp_full    = get_option( 'kalicart_bridge_return_policy_url', '' );
    $kbridge_rp_base    = trailingslashit( get_site_url() );
    $kbridge_rp_slug    = $kbridge_rp_full ? ltrim( str_replace( $kbridge_rp_base, '', $kbridge_rp_full ), '/' ) : '';
    $kbridge_rp_set     = ! empty( $kbridge_rp_slug );
    $kbridge_rp_color   = $kbridge_rp_set ? '#00a32a' : '#f0a000';
    $kbridge_rp_bg      = $kbridge_rp_set ? '#f0fff4' : '#fff8f0';
    $kbridge_rp_badge   = $kbridge_rp_set ? 'CONFIGURED' : 'REQUIRED';
    // phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
    ?>
    <div id="returnPolicyBlock" style="margin-bottom:24px;padding:16px 20px;background:<?php echo esc_attr( $kbridge_rp_bg ); ?>;border:1px solid <?php echo esc_attr( $kbridge_rp_color ); ?>;border-radius:8px;">
      <div style="display:flex;align-items:flex-start;gap:10px;margin-bottom:12px;">
        <span id="returnPolicyBadge" style="display:inline-block;background:<?php echo esc_attr( $kbridge_rp_color ); ?>;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:4px;white-space:nowrap;margin-top:2px;"><?php echo esc_html( $kbridge_rp_badge ); ?></span>
        <div>
          <strong style="font-size:13px;color:#1d2327;"><?php esc_html_e( 'Refund and Returns Policy URL', 'kalicart-bridge' ); ?></strong>
          <p style="font-size:12px;color:#666;margin:4px 0 0;">
            <?php esc_html_e( 'Add your refund and returns policy page so AI agents can inform buyers about your return conditions. Without this, the catalog health score is reduced by 10 points.', 'kalicart-bridge' ); ?>
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
        <a href="<?php echo esc_url( $kbridge_rp_full ); ?>" target="_blank" rel="noopener" style="color:#00a32a;">&#x1F517; <?php esc_html_e( 'Test link:', 'kalicart-bridge' ); ?> <?php echo esc_html( $kbridge_rp_full ); ?></a>
        <?php endif; ?>
      </div>
    </div>

    <div class="kali-section-title"><?php esc_html_e( 'Signal settings', 'kalicart-bridge' ); ?></div>
    <div class="kali-settings-list">

      <div class="kali-toggle-group">
        <label class="kali-toggle-row">
          <div class="kali-toggle-info">
            <strong><?php esc_html_e( 'AI catalog badge', 'kalicart-bridge' ); ?></strong>
            <span><?php esc_html_e( 'Speaking HTML badge visible on the storefront — machine-readable anchor for AI agents.', 'kalicart-bridge' ); ?></span>
          </div>
          <div class="kali-toggle">
            <input type="checkbox" id="toggleBadge">
            <span class="kali-toggle__slider"></span>
          </div>
        </label>
        <div class="kali-badge-position" id="badgePositionWrap">
          <span class="kali-pos-label"><?php esc_html_e( 'Position', 'kalicart-bridge' ); ?></span>
          <div class="kali-pos-grid">
            <?php
            $kalicart_positions = [ 'top-left' => __( 'Top left', 'kalicart-bridge' ), 'top-right' => __( 'Top right', 'kalicart-bridge' ), 'bottom-left' => __( 'Bottom left', 'kalicart-bridge' ), 'bottom-right' => __( 'Bottom right', 'kalicart-bridge' ) ];
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
          <strong><?php esc_html_e( 'robots.txt directive', 'kalicart-bridge' ); ?></strong>
          <span><?php echo wp_kses_post( sprintf( /* translators: %s: a robots.txt Allow directive, shown as code */ __( 'Adds %s to robots.txt.', 'kalicart-bridge' ), '<code>Allow: /wp-json/kalicart/</code>' ) ); ?></span>
        </div>
        <div class="kali-toggle"><input type="checkbox" id="toggleRobots"><span class="kali-toggle__slider"></span></div>
      </label>

      <label class="kali-toggle-row">
        <div class="kali-toggle-info">
          <strong><?php esc_html_e( 'Checkout sessions', 'kalicart-bridge' ); ?> <span style="font-size:11px;font-weight:500;background:var(--kb-acc-bg);color:var(--kb-acc);border:1px solid rgba(0,112,243,.2);border-radius:99px;padding:1px 8px;vertical-align:middle"><?php esc_html_e( 'optional', 'kalicart-bridge' ); ?></span></strong>
          <span><?php echo wp_kses_post( sprintf( /* translators: %s: the POST /checkout/session endpoint, shown as code */ __( 'Enables %s — agent creates a session, merchant gets a checkout URL, human completes payment on WooCommerce. No OAuth, no PII, no payment on the agent side.', 'kalicart-bridge' ), '<code>POST /checkout/session</code>' ) ); ?></span>
        </div>
        <div class="kali-toggle"><input type="checkbox" id="toggleCheckout"><span class="kali-toggle__slider"></span></div>
      </label>

      <label class="kali-toggle-row">
        <div class="kali-toggle-info">
          <strong><?php esc_html_e( 'Agentic sitemap', 'kalicart-bridge' ); ?></strong>
          <span><?php echo wp_kses_post( sprintf( /* translators: %s: a link to the agentic sitemap file */ __( 'Serves %s with annotated catalog endpoints.', 'kalicart-bridge' ), '<a href="' . esc_url( home_url( '/sitemap-agentic-bridge.xml' ) ) . '" target="_blank">sitemap-agentic-bridge.xml</a>' ) ); ?></span>
        </div>
        <div class="kali-toggle"><input type="checkbox" id="toggleSitemap"><span class="kali-toggle__slider"></span></div>
      </label>

      <label class="kali-toggle-row">
        <div class="kali-toggle-info">
          <strong><?php esc_html_e( 'Agent discovery files', 'kalicart-bridge' ); ?> <small style="font-size:11px;font-weight:400;color:var(--kb-muted)">.well-known/</small></strong>
          <span><?php echo wp_kses_post( sprintf( /* translators: %1$s and %2$s: .well-known discovery file paths, shown as code */ __( 'Writes %1$s and %2$s — standard discovery points for AI agents that probe the site before navigating it.', 'kalicart-bridge' ), '<code>/.well-known/kalicart-bridge</code>', '<code>/.well-known/agent-catalog</code>' ) ); ?></span>
        </div>
        <div class="kali-toggle"><input type="checkbox" id="toggleWellKnown"><span class="kali-toggle__slider"></span></div>
      </label>

      <div class="kali-section-title" style="margin-top:20px"><?php esc_html_e( 'Agent discovery hints', 'kalicart-bridge' ); ?></div>

      <label class="kali-toggle-row">
        <div class="kali-toggle-info">
          <strong><?php esc_html_e( 'Search form link', 'kalicart-bridge' ); ?></strong>
          <span><?php esc_html_e( 'Appends a visible "Structured catalog for AI agents" link after every search form.', 'kalicart-bridge' ); ?></span>
        </div>
        <div class="kali-toggle"><input type="checkbox" id="toggleHintSearch"><span class="kali-toggle__slider"></span></div>
      </label>

      <label class="kali-toggle-row">
        <div class="kali-toggle-info">
          <strong><?php esc_html_e( 'Search results link', 'kalicart-bridge' ); ?></strong>
          <span><?php esc_html_e( 'Shows a minimal "Machine-readable catalog" link at the bottom of search pages, with or without results.', 'kalicart-bridge' ); ?></span>
        </div>
        <div class="kali-toggle"><input type="checkbox" id="toggleHintZero"><span class="kali-toggle__slider"></span></div>
      </label>

      <label class="kali-toggle-row">
        <div class="kali-toggle-info">
          <strong><?php esc_html_e( 'Category & product page links', 'kalicart-bridge' ); ?></strong>
          <span><?php esc_html_e( 'Shows a "Machine-readable category data" link on category pages and a "Machine-readable product data" link above the product meta on single product pages — both pointing to the structured API endpoint.', 'kalicart-bridge' ); ?></span>
        </div>
        <div class="kali-toggle"><input type="checkbox" id="toggleHintCategory"><span class="kali-toggle__slider"></span></div>
      </label>

    </div>

    <div class="kali-settings-footer">
      <button id="btnSaveSettings" class="kali-btn kali-btn--primary"><?php esc_html_e( 'Save settings', 'kalicart-bridge' ); ?></button>
      <span id="settingsSaved" class="kali-saved-notice" style="display:none">&#x2713; <?php esc_html_e( 'Saved', 'kalicart-bridge' ); ?></span>
    </div>
  </div>

  <!-- TAB: COUPONS -->
  <div id="kali-tab-coupons" class="kali-panel" style="display:<?php echo 'coupons' === $kalicart_bridge_active_tab ? 'block' : 'none'; ?>">

    <div class="kali-section-title"><?php esc_html_e( 'Agent coupon exposure', 'kalicart-bridge' ); ?></div>
    <p style="margin:0 0 16px;font-size:13px;color:var(--kb-muted,#555);line-height:1.6;max-width:680px;">
      <?php esc_html_e( 'By default the catalog tells agents nothing about your coupons. Turn this on and pick exactly which active coupons agents may see. Selected coupons are presented to agents as conditional savings — WooCommerce checkout always has the final say on whether a coupon actually applies. Private, targeted or newsletter codes you leave unticked are never exposed.', 'kalicart-bridge' ); ?>
    </p>

    <div class="kali-settings-list">
      <label class="kali-toggle-row">
        <div class="kali-toggle-info">
          <strong><?php esc_html_e( 'Expose coupons to agents', 'kalicart-bridge' ); ?></strong>
          <span><?php esc_html_e( 'Master switch. When off, active_coupons is always empty regardless of selection below.', 'kalicart-bridge' ); ?></span>
        </div>
        <div class="kali-toggle"><input type="checkbox" id="toggleCouponsAgent"><span class="kali-toggle__slider"></span></div>
      </label>
    </div>

    <div id="couponsWhitelistWrap" style="margin-top:18px;display:none">
      <div class="kali-section-title" style="margin-bottom:6px"><?php esc_html_e( 'Eligible coupons', 'kalicart-bridge' ); ?></div>
      <p id="couponsHint" style="margin:0 0 12px;font-size:12px;color:var(--kb-muted,#999);"></p>
      <div id="couponsList" class="kali-coupons-list"></div>
    </div>

    <div class="kali-settings-footer">
      <button id="btnSaveCoupons" class="kali-btn kali-btn--primary"><?php esc_html_e( 'Save settings', 'kalicart-bridge' ); ?></button>
      <span id="couponsSaved" class="kali-saved-notice" style="display:none">&#x2713; <?php esc_html_e( 'Saved', 'kalicart-bridge' ); ?></span>
    </div>
  </div>

  <!-- TAB: STATS -->
  <div id="kali-tab-stats" class="kali-panel" style="display:<?php echo 'stats' === $kalicart_bridge_active_tab ? 'block' : 'none'; ?>">
    <?php
    // ── Assistenti AI ─────────────────────────────────────────────────────────
    // Tre colonne, una riga per ASSISTENTE (non per bot): pagine e catalogo
    // arrivano dal contatore per user-agent, gli ordini dall'attribuzione per
    // dominio. L'aggregazione sta in get_panel_rows(), qui solo la resa.
    $kalicart_bridge_panel    = class_exists( 'KaliCart_Bridge_Signals' ) ? KaliCart_Bridge_Signals::get_panel_rows( 30 ) : null;
    $kalicart_bridge_counting = (bool) get_option( 'kalicart_bridge_ai_traffic_enabled', true );
    if ( $kalicart_bridge_panel ) :
      $kalicart_bridge_rows = $kalicart_bridge_panel['rows'];
      $kalicart_bridge_pmax = 1; $kalicart_bridge_cmax = max( 1, (int) $kalicart_bridge_panel['unnamed_catalog'] ); $kalicart_bridge_omax = 1;
      foreach ( $kalicart_bridge_rows as $kalicart_bridge_r ) {
        $kalicart_bridge_pmax = max( $kalicart_bridge_pmax, $kalicart_bridge_r['pages'] );
        $kalicart_bridge_cmax = max( $kalicart_bridge_cmax, $kalicart_bridge_r['catalog'] );
        $kalicart_bridge_omax = max( $kalicart_bridge_omax, $kalicart_bridge_r['orders'] );
      }
    ?>
    <div class="kali-section-title"><?php esc_html_e( 'AI assistants and your store', 'kalicart-bridge' ); ?></div>

    <?php if ( ! $kalicart_bridge_counting ) : ?>
      <p class="kali-hint"><strong><?php esc_html_e( 'Counting is turned off: this section is not measuring anything. That is not the same as no assistant having visited.', 'kalicart-bridge' ); ?></strong></p>
    <?php elseif ( empty( $kalicart_bridge_rows ) ) : ?>
      <p class="kali-hint"><?php
        /* translators: %d: number of days observed */
        printf( esc_html__( 'No assistant has visited your store yet in the last %d days observed.', 'kalicart-bridge' ), (int) $kalicart_bridge_panel['days_covered'] ); ?></p>
    <?php else : ?>
      <div class="kali-agents">
        <div class="kali-agents__head">
          <div class="kali-agents__col kali-agents__col--spacer"></div>
          <div class="kali-agents__col">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#6b6b7a" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 3h11l5 5v13H4z"/><path d="M15 3v5h5"/><path d="M8 13h8M8 17h5"/></svg>
            <div><b><?php esc_html_e( 'Read your pages', 'kalicart-bridge' ); ?></b>
            <span><?php esc_html_e( 'Anyone can read them, with or without KaliCart', 'kalicart-bridge' ); ?></span></div>
          </div>
          <div class="kali-agents__col kali-agents__col--strong">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#1a7f3c" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6.5C3 5 5.7 4 9 4s6 1 6 2.5S12.3 9 9 9 3 8 3 6.5z"/><path d="M3 6.5v11C3 19 5.7 20 9 20s6-1 6-2.5v-11"/><path d="M3 12c0 1.5 2.7 2.5 6 2.5s6-1 6-2.5"/><path d="M17.5 13.5l3.5 3.5-3.5 3.5"/></svg>
            <div><b><?php esc_html_e( 'Asked for your KaliCart catalog', 'kalicart-bridge' ); ?></b>
            <span><?php esc_html_e( 'Only possible with the Bridge active', 'kalicart-bridge' ); ?></span></div>
          </div>
          <div class="kali-agents__col kali-agents__col--maybe">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#b45309" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 6h15l-1.5 9h-12z"/><path d="M6 6L5 3H2"/><circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/></svg>
            <div><b><?php esc_html_e( 'Orders converted', 'kalicart-bridge' ); ?></b>
            <span><?php esc_html_e( 'Customers who arrived from there and bought', 'kalicart-bridge' ); ?></span></div>
          </div>
        </div>

        <?php foreach ( $kalicart_bridge_rows as $kalicart_bridge_r ) : ?>
          <div class="kali-agents__row kali-agents__row--4">
            <div class="kali-agents__name">
              <span class="kali-agents__dot<?php echo $kalicart_bridge_r['catalog'] ? ' kali-agents__dot--strong' : ''; ?>"></span>
              <div>
                <?php echo esc_html( $kalicart_bridge_r['name'] ); ?>
                <?php if ( ! empty( $kalicart_bridge_r['bots'] ) ) : ?>
                  <em><?php echo esc_html( implode( ', ', $kalicart_bridge_r['bots'] ) ); ?></em>
                <?php endif; ?>
              </div>
            </div>
            <div class="kali-agents__cell">
              <span class="kali-agents__num<?php echo $kalicart_bridge_r['pages'] ? '' : ' kali-agents__num--muted'; ?>"><?php echo $kalicart_bridge_r['pages'] ? esc_html( number_format_i18n( $kalicart_bridge_r['pages'] ) ) : '&mdash;'; ?></span>
              <span class="kali-agents__bar"><i style="width:<?php echo esc_attr( $kalicart_bridge_r['pages'] ? max( 3, round( $kalicart_bridge_r['pages'] * 100 / $kalicart_bridge_pmax ) ) : 0 ); ?>%"></i></span>
            </div>
            <div class="kali-agents__cell">
              <span class="kali-agents__num<?php echo $kalicart_bridge_r['catalog'] ? ' kali-agents__num--strong' : ' kali-agents__num--muted'; ?>"><?php echo $kalicart_bridge_r['catalog'] ? esc_html( number_format_i18n( $kalicart_bridge_r['catalog'] ) ) : '&mdash;'; ?></span>
              <span class="kali-agents__bar"><i class="is-strong" style="width:<?php echo esc_attr( $kalicart_bridge_r['catalog'] ? max( 3, round( $kalicart_bridge_r['catalog'] * 100 / $kalicart_bridge_cmax ) ) : 0 ); ?>%"></i></span>
            </div>
            <div class="kali-agents__cell">
              <?php if ( $kalicart_bridge_r['orders'] ) :
                // SCALA DEI COLORI — un colore dichiara un LIVELLO DI PROVA, mai
                // un'intensita'. Non aggiungere sfumature e non spostare il verde:
                //
                //   verde   dimostrato. Oggi solo la colonna catalogo: quelle
                //           richieste sono arrivate su rotte che senza il Bridge
                //           non esistono. Domani anche gli ordini che porteranno
                //           la firma del catalogo nell'URL.
                //   arancio coincidenza qualificata. Lo stesso assistente ha letto
                //           il catalogo e ha portato clienti nel periodo. NON e'
                //           dimostrato che le due cose siano collegate: il bot
                //           interroga dai server del fornitore, la persona compra
                //           dal proprio browser, e non esiste identificativo
                //           condiviso fra i due.
                //   grigio  nessun legame osservato.
                //
                // Il verde sugli ordini e' stato rimosso il 2026-09-10: su duinshop
                // mostrava 2 conversioni in verde con la colonna catalogo vuota, e
                // ha ingannato chi conosceva i dati.
                $kalicart_bridge_assisted = $kalicart_bridge_r['catalog'] > 0; ?>
                <span class="kali-agents__num<?php echo $kalicart_bridge_assisted ? ' kali-agents__num--maybe' : ''; ?>"><?php echo esc_html( number_format_i18n( $kalicart_bridge_r['orders'] ) ); ?></span>
                <span class="kali-agents__money<?php echo $kalicart_bridge_assisted ? ' kali-agents__money--maybe' : ' kali-agents__money--plain'; ?>"><?php echo wp_kses_post( wc_price( $kalicart_bridge_r['total'], [ 'currency' => $kalicart_bridge_panel['currency'] ] ) ); ?></span>
              <?php else : ?>
                <span class="kali-agents__num kali-agents__num--muted">&mdash;</span>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>

        <?php if ( ! empty( $kalicart_bridge_panel['unnamed_catalog'] ) ) : ?>
          <div class="kali-agents__row kali-agents__row--4">
            <div class="kali-agents__name">
              <span class="kali-agents__dot kali-agents__dot--strong"></span>
              <div><em><?php esc_html_e( 'Agents that do not identify themselves', 'kalicart-bridge' ); ?></em></div>
            </div>
            <div class="kali-agents__cell"><span class="kali-agents__num kali-agents__num--muted">&mdash;</span></div>
            <div class="kali-agents__cell">
              <span class="kali-agents__num kali-agents__num--strong"><?php echo esc_html( number_format_i18n( $kalicart_bridge_panel['unnamed_catalog'] ) ); ?></span>
              <span class="kali-agents__bar"><i class="is-strong" style="width:<?php echo esc_attr( max( 3, round( $kalicart_bridge_panel['unnamed_catalog'] * 100 / $kalicart_bridge_cmax ) ) ); ?>%"></i></span>
            </div>
            <div class="kali-agents__cell"><span class="kali-agents__num kali-agents__num--muted">&mdash;</span></div>
          </div>
        <?php endif; ?>
      </div>
      <p class="kali-hint"><?php esc_html_e( 'Anyone can read your pages, with or without KaliCart. Only those who find the Bridge active on your site can ask for the catalog. Converted orders are people who clicked a link inside an assistant and bought from you.', 'kalicart-bridge' ); ?></p>
    <?php endif; ?>

    <?php
    // BANNER RIMOSSO il 2026-09-10. Diceva "Confermato: X ha chiesto il catalogo
    // N volte e nello stesso periodo ha portato M clienti". Le due meta' erano
    // vere, il legame no: il bot interroga dai server del fornitore, la persona
    // compra dal proprio browser, non c'e' identificativo condiviso. Una frase in
    // prosa con un segno di spunta AFFERMA, e il merchant la ripete a terzi: puo'
    // esistere solo dove c'e' una traccia, non dove c'e' una coincidenza.
    // Tornera' quando il Bridge firmera' gli URL che consegna agli agenti e
    // l'ordine portera' quella firma. Fino ad allora parla la tabella.
    ?>
    <div class="kali-legend">
      <span class="kali-legend__item"><i class="kali-legend__dot kali-legend__dot--proven"></i><?php esc_html_e( 'Proven: those requests arrived on routes that do not exist without the Bridge.', 'kalicart-bridge' ); ?></span>
      <span class="kali-legend__item"><i class="kali-legend__dot kali-legend__dot--maybe"></i><?php esc_html_e( 'Same period: this assistant both asked for your catalog and brought customers. The two are not proven to be connected.', 'kalicart-bridge' ); ?></span>
      <span class="kali-legend__item"><i class="kali-legend__dot kali-legend__dot--none"></i><?php esc_html_e( 'No link observed.', 'kalicart-bridge' ); ?></span>
    </div>

    <p class="kali-hint"><?php
      /* translators: %d: number of days observed */
      printf( esc_html__( 'Local to your site, last %d days observed. No data leaves your store.', 'kalicart-bridge' ), (int) $kalicart_bridge_panel['days_covered'] ); ?></p>
    <?php endif; ?>

    <div class="kali-section-title"><?php esc_html_e( 'Agent checkout', 'kalicart-bridge' ); ?></div>
    <p class="kali-hint"><?php esc_html_e( 'Last 30 days. Local counters only — no cloud. Orders created from a Bridge checkout session, through either classic checkout or Checkout Block, are linked back to that session.', 'kalicart-bridge' ); ?></p>

    <?php
    $kalicart_bridge_funnel = class_exists( 'KaliCart_Bridge_Checkout' ) ? KaliCart_Bridge_Checkout::get_funnel_report() : null;
    // Il checkout agentico completo — l'agente che costruisce il carrello e paga
    // da solo — non e' ancora una pratica diffusa: su quasi tutti i negozi questi
    // quattro contatori sono a zero. Mostrare quattro zeri fa concludere che il
    // plugin non funzioni, mentre le sezioni sopra dimostrano il contrario. Si
    // mostra la griglia SOLO quando c'e' qualcosa dentro; altrimenti una riga che
    // spiega cosa comparira'. Stesso principio della riga "Confermato".
    // Soglia: VALORE NETTO PAGATO, non sessioni ne' carrelli. Una sessione aperta
    // senza carrello e senza ordine non e' attivita' — la prima versione di questa
    // guardia sommava i tre contatori e su un negozio reale ha lasciato passare la
    // griglia per 1 sessione, 0, 0, 0,00 €: esattamente cio' che doveva impedire.
    // Questa sezione esiste per dimostrare che un agente ha COMPRATO, quindi
    // l'unica soglia coerente e' il denaro effettivamente incassato.
    // Conseguenza accettata: un ordine agentico collegato ma non ancora pagato
    // (bonifico, contrassegno) resta nascosto finche' il pagamento non e'
    // confermato. Meglio mostrare tardi che mostrare un valore che il merchant
    // potrebbe leggere come incassato.
    $kalicart_bridge_funnel_active = $kalicart_bridge_funnel
      && (float) ( $kalicart_bridge_funnel['net_paid_value'] ?? 0 ) > 0;
    if ( ! $kalicart_bridge_funnel_active ) : ?>
      <p class="kali-hint"><?php esc_html_e( 'When an assistant builds the cart and completes the order on its own, with no human step, it will show up here. The Bridge already keeps that possibility open: no assistant does it at scale yet.', 'kalicart-bridge' ); ?></p>
    <?php endif;
    if ( $kalicart_bridge_funnel_active ) :
    ?>
    <div class="kali-stats-grid kali-stats-grid--4">
      <div class="kali-stat">
        <span class="kali-stat__value"><?php echo esc_html( number_format_i18n( $kalicart_bridge_funnel['sessions_created'] ) ); ?></span>
        <span class="kali-stat__label"><?php esc_html_e( 'Sessions created', 'kalicart-bridge' ); ?></span>
      </div>
      <div class="kali-stat">
        <span class="kali-stat__value"><?php echo esc_html( number_format_i18n( $kalicart_bridge_funnel['carts_loaded'] ) ); ?></span>
        <span class="kali-stat__label"><?php esc_html_e( 'Carts loaded', 'kalicart-bridge' ); ?></span>
      </div>
      <div class="kali-stat kali-stat--green">
        <span class="kali-stat__value"><?php echo esc_html( number_format_i18n( $kalicart_bridge_funnel['orders_linked'] ) ); ?></span>
        <span class="kali-stat__label"><?php esc_html_e( 'Orders linked', 'kalicart-bridge' ); ?></span>
      </div>
      <div class="kali-stat kali-stat--green">
        <span class="kali-stat__value"><?php echo wp_kses_post( wc_price( $kalicart_bridge_funnel['net_paid_value'], [ 'currency' => $kalicart_bridge_funnel['currency'] ] ) ); ?></span>
        <span class="kali-stat__label"><?php esc_html_e( 'Net value, payment confirmed', 'kalicart-bridge' ); ?></span>
      </div>
    </div>
    <p class="kali-hint"><?php esc_html_e( 'Cash on delivery, bank transfer and cheque orders are included in “Orders linked”, but excluded from net value unless WooCommerce records a payment date. Reaching “processing” is not proof of payment for these methods.', 'kalicart-bridge' ); ?></p>
    <?php endif; ?>
  </div>

</div>
