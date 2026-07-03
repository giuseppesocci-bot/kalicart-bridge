<?php
defined( 'ABSPATH' ) || exit;

// Keep the selected tab stable across refreshes and form submissions.
$kalicart_bridge_allowed_tabs = [ 'overview', 'quarantine', 'endpoints', 'agent-commerce', 'settings', 'coupons' ];
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
      <button class="kali-tab<?php echo 'quarantine' === $kalicart_bridge_active_tab ? ' kali-tab--active' : ''; ?>" data-tab="quarantine"><?php esc_html_e( 'Quarantine', 'kalicart-bridge' ); ?></button>
      <button class="kali-tab<?php echo 'endpoints' === $kalicart_bridge_active_tab ? ' kali-tab--active' : ''; ?>" data-tab="endpoints"><?php esc_html_e( 'Endpoints', 'kalicart-bridge' ); ?></button>
      <button class="kali-tab<?php echo 'agent-commerce' === $kalicart_bridge_active_tab ? ' kali-tab--active' : ''; ?>" data-tab="agent-commerce">ChatGPT Feed</button>
      <button class="kali-tab<?php echo 'settings' === $kalicart_bridge_active_tab ? ' kali-tab--active' : ''; ?>" data-tab="settings"><?php esc_html_e( 'Settings', 'kalicart-bridge' ); ?></button>
      <button class="kali-tab<?php echo 'coupons' === $kalicart_bridge_active_tab ? ' kali-tab--active' : ''; ?>" data-tab="coupons"><?php esc_html_e( 'Coupons', 'kalicart-bridge' ); ?></button>
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
        <span class="kali-stat__label"><?php esc_html_e( 'Quarantine', 'kalicart-bridge' ); ?></span>
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
      <?php esc_html_e( 'Quarantined products', 'kalicart-bridge' ); ?>
      <span class="kali-badge kali-badge--red" id="quarantineCount">&ndash;</span>
    </div>
    <p class="kali-hint"><?php esc_html_e( 'Products with blocking computability issues: missing descriptions, categories, or invalid prices.', 'kalicart-bridge' ); ?></p>
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
          <span><?php esc_html_e( 'Shows a minimal "Machine-readable catalog" link at the bottom of search pages — both on zero-results and when results are ambiguous or partial.', 'kalicart-bridge' ); ?></span>
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

</div>
