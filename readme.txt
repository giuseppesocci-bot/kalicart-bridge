=== KaliCart Bridge ===
Contributors: kalicart
Tags: woocommerce, ai, agent, catalog, machine-readable
Requires at least: 6.0
Tested up to: 7.0
Stable tag: 1.0.69
Requires PHP: 8.0
WC requires at least: 7.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Makes your WooCommerce catalog machine-readable and agent-accessible. No LLM. No cloud. No API key.

Documentation: https://bridge.kalicart.com/docs/

== Description ==

KaliCart Bridge exposes your WooCommerce product catalog as a structured, normalized REST API designed for AI shopping agents to consume directly.

**What it does:**

* Exposes a `/discovery` endpoint — the single entry point any agent needs to understand your catalog
* Provides `/catalog/search`, `/catalog/products`, `/catalog/product/{id}`, `/catalog/categories` endpoints
* Computationally normalizes product data: prices (min/max for variables, sale %, discount), stock, gender inference, color family mapping, size type detection
* Exposes WooCommerce shipping-zone policy for agent reasoning; checkout remains the final authority for exact destination/cart shipping cost
* Exposes active product/category-compatible coupons as conditional checkout savings; coupons never replace the catalog price
* Category tree nodes include direct `products_url` and `search_url_template` fields so agents can navigate without constructing URLs manually
* Dashboard issue cards and suggestions link to filtered product lists for direct remediation
* Uses **your merchant taxonomy** — no global remapping, products stay in your WooCommerce categories
* Injects a `<link rel="kalicart-agent">` in your site `<head>` for agent auto-discovery
* Adds an "agent ready" badge in the footer
* Injects `Allow: /wp-json/kalicart/` in `robots.txt`
* Generates `/kalicart-sitemap.xml` linked from the WP sitemap index
* Shows a catalog health dashboard in wp-admin with quarantine tracking and improvement suggestions

**What it does NOT do:**

* No LLM calls
* No external service or cloud dependency
* No data sent anywhere outside your server
* No API key required for public endpoints

**Normalization engine:**

* Price: regular, sale, current, discount %, currency — variable products get min/max ranges
* Stock: status, in_stock bool, quantity if managed, backorder policy
* Gender: inferred from `pa_gender` attribute, category paths, tags, product name (multilingual keywords: IT/EN/FR/DE/ES)
* Color: mapped to 13 color families via keyword matching on `pa_color`/`pa_colore` and product metadata
* Size: detected from `pa_size`/`pa_taglia`, type auto-detected (clothing S/M/L, numeric EU, shoes EU half-sizes)

**Catalog health / quarantine:**

Products are scored 0–100 based on: title quality, description length, category assignment, price validity, image presence and SKU presence. Quarantine is reserved for blocking computability issues: ambiguous/too-short titles, missing or very short descriptions, missing real categories, and missing or zero prices. Missing images and SKUs remain clickable improvement suggestions but do not quarantine products.

**Checkout sessions (optional):**

When enabled in WP Admin → KaliCart → Settings, agents can create checkout sessions containing one or more products. Each session returns cart_url (lands on WooCommerce cart for review) and checkout_url (goes directly to checkout). Sessions expire after 30 minutes. No OAuth, no PII, no payment on the agent side.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/kalicart-bridge/`
2. Activate the plugin through the Plugins screen in WordPress
3. Navigate to **KaliCart** in the admin menu
4. The catalog is immediately accessible at `yourdomain.com/wp-json/kalicart/v1/discovery`

Full operational documentation is always available at `https://bridge.kalicart.com/docs/`.

== Frequently Asked Questions ==

= Does this share my data with KaliCart or any third party? =

No. The plugin runs entirely on your server. No data is sent externally.

= Do I need a KaliCart account? =

No. This plugin is fully standalone and free.

= Who can access the catalog endpoints? =

Public endpoints (discovery, search, products, categories) are accessible without authentication — same as the WooCommerce REST API public surfaces. The `/health` endpoint requires `manage_woocommerce` capability.

= Can I disable the badge or robots.txt injection? =

Yes — all three signals (badge, robots.txt, sitemap) can be toggled individually in the KaliCart settings tab.

= How often is the health report cached? =

5 minutes. You can force a refresh via the "Refresh analysis" button or the `?force=true` query parameter on the `/health` endpoint.


== Release process ==

Il bump di versione si esegue con un solo comando dal server:

  python3 /home/kalicart-bridge/bump.py <new_version>

Prima del bump, aggiornare manualmente:
1. changelog.txt — aggiungere voce con le note della sessione
2. readme.txt sezione == Changelog == — stesse note in formato WP (= X.Y.Z =, righe con *)

Lo script aggiorna in sequenza:
- kalicart-bridge.php        (Version header + KALICART_BRIDGE_VERSION)
- readme.txt                 (Stable tag — NON tocca == Changelog ==)
- bridge.kalicart.com/index.html
- bridge.kalicart.com/docs/index.html
- bridge.kalicart.com/docs/docs/index.html
- updates/kalicart-bridge.json  (version, download_url, download_link, sections.changelog sincronizzato da readme.txt)
- latest.json
- Genera ZIP versionato escludendo *.bak.* e *.backup.*

sections.changelog nel JSON di aggiornamento viene auto-sincronizzato dal readme.txt ad ogni bump.
Non aggiornare mai sections.changelog manualmente.

== Changelog ==

= 1.0.69 =
* Fix - suggestions sorted by priority (high first)

= 1.0.68 =
* Fix - NO_RETURN_POLICY suggestion priority high (red dot, top of list)
* Fix - NO_RETURN_POLICY shows Configure link instead of 0 products label
* Fix - suggestions without affected count no longer render products button

= 1.0.67 =
* Added - Return policy URL field in Settings
* Added - return_policy object in discovery endpoint (configured, url, agent note)
* Added - NO_RETURN_POLICY suggestion (medium priority) in health dashboard
* Added - Health score penalty: -10 points when return policy URL is missing

= 1.0.66 =
* Fixed - /.well-known/ucp is written as a physical file for hosts that bypass WordPress rewrites on .well-known paths
* Fixed - .well-known/.htaccess declares ucp as application/json
* Refactor - UCP profile generation centralized for dynamic and physical discovery paths

= 1.0.65 =
* Perf - Context-aware normalize_product(): variations not loaded in search/list results — 16× speedup on variable-heavy catalogs
* Note - Use /catalog/product/{id} to get full variants[] before checkout on variable products

= 1.0.64 =
* Perf - normalize_product() optimized: all heavy methods called once per product, results reused across fields (stock, purchase_readiness, barcodes, variations)

= 1.0.63 =
* Added - price.vat_included, price.tax_enabled, price.price_type (STATIC)
* Added - stock.quantity_tracked, stock.backorder_allowed boolean
* Added - shipping.zones[] with methods, costs, locations, free threshold per zone

= 1.0.62 =
* Fixed - on_sale suppressed when discount_pct < 1%
* Fixed - discount_pct: one decimal precision
* Fixed - price.display: clean space, no non-breaking space
* Added - price_format in discovery: major/minor units distinction, autonomous checkout conversion rule

= 1.0.61 =
* Added - price.encoding: decimal_major_units — unambiguous vs UCP minor units
* Added - price.display: plain-text formatted price string (e.g. "247,00 €")
* Added - price_format in discovery document

= 1.0.60 =
* Added - autonomous_checkout roadmap block in discovery: AP2-compatible contract defined, ready: false pending WooCommerce/gateway support

= 1.0.59 =
* Update - Admin Endpoints tab: added /.well-known/ucp, /.well-known/kalicart-bridge, POST /checkout/session
* Update - Endpoint descriptions updated with new fields
* Update - Docs updated with UCP, variants[], list_price, barcodes, metadata sections

= 1.0.58 =
* Added - /.well-known/ucp UCP profile (catalog.search + catalog.lookup, version 2026-04-08)
* Added - ucp_profile_url in discovery document and well-known files
* Added - stock.availability_status with UCP-standard values
* Added - barcodes[] on products and variations (EAN, GTIN, UPC)
* Added - variants[] always present — variable products expose all variations, simple products expose single variant
* Added - list_price (UCP-compatible strikethrough price) on simple products
* Added - metadata{} block with purchase_readiness, stock_confidence, bridge_version

= 1.0.57 =
* Fixed - Plugin information popup changelog now reads from readme.txt instead of hardcoded entries
* Fixed - Release ZIP excludes backup and temporary files

= 1.0.56 =
* Fixed - popup_changelog() reads from readme.txt — always in sync with distributed ZIP
* Fixed - bump.py: sections.changelog in update JSON now auto-synced from readme.txt on every release

= 1.0.55 =
* Update - active_coupons filter: coupons with no computable product value (fixed_cart, estimated_saving=0, not free_shipping) excluded from product payload
* Update - coupon payload: added applicable_at field (cart_only / product_or_cart)

= 1.0.54 =
* Update - NO_IMAGE score deduction: 8 points; NO_SKU score deduction: 4 points
* Update - stock.quantity suppressed for variable products (variant_dependent) — aggregate count was misleading
* Update - quarantine build_issue_list: score calculated from severity map

= 1.0.53 =
* Update - stock.quantity: null for variable products with variant_dependent confidence
* Update - score deductions: NO_IMAGE -8, NO_SKU -4 applied at product level

= 1.0.52 =
* Fixed - Single product page link injected inside .product_meta via appendChild

= 1.0.51 =
* Added - .well-known files served via WordPress rewrite rules with Content-Type: application/json — works on any server
* Fixed - Removed dependency on .htaccess for Content-Type on .well-known files

= 1.0.49 =
* Added - variations[] in /catalog/product/{id} for variable products
* Added - agent_index_url field in admin settings, exposed in discovery document
* Fixed - query_construction: size removed from structured filters
* Update - catalog/meta: on_sale, size_note, coupon_verification_rule added

= 1.0.48 =
* Added - on_sale=true as real search filter
* Added - purchase_readiness block per product
* Added - stock.confidence: numeric_stock_quantity / availability_status_only / variant_dependent
* Added - discovery: stock_rule, variation_discovery, semantic_fit_guidance, evidence_required, total_verification_rule
* Update - search_url_template: {spine} replaced with {q}
* Update - coupon_policy: coupon_verification_rule and combinable_with_sale added

= 1.0.47 =
* Fixed - WC tested up to updated to 10.8

= 1.0.45 =
* Fixed - __return_true replaced with public_catalog_permission() — QIT security clean
* Fixed - wp_unslash() on $_GET inputs in class-checkout.php
* Fixed - Global variables in uninstall.php prefixed with kalicart_bridge_
* Fixed - Escape output via wp_json_encode() on boolean hint variables
* QIT Security: 0 errors, 0 warnings; PHP Compatibility: 0 errors, 0 warnings (PHP 8.0–8.5)

= 1.0.42 =
* Added - [kalicart_agent_index] shortcode: agent-readable catalog index with live category tree
* Added - bump.py centralized release script

= 1.0.38 =
* Update - Search results catalog link shown on zero-results and with-results pages
* Update - Link injected before footer; color:inherit; title hints for agents
* Removed - search?q= link from zero-results block

= 1.0.36 =
* Added - Honey JS: structured links on search, zero-results, category and product pages
* Added - Agent discovery hints toggles in admin settings
* Fixed - All toggles now save correctly

= 1.0.34 =
* Added - Hidden machine-readable anchor in primary nav menu

= 1.0.30 =
* Added - .well-known/kalicart-bridge and .well-known/agent-catalog discovery files
* Added - Warning alerts when disabling critical discovery signals

= 1.0.28 =
* Added - Checkout sessions: POST /checkout/session, returns cart_url and checkout_url
* Added - Checkout sessions toggle in admin settings (off by default)

= 1.0.25 =
* Added - WC tested up to header; HPOS compatibility declaration

= 1.0.17 =
* Added - Shipping policy and active coupons exposed on every product and in discovery document
* Added - /catalog/meta endpoint with accepted filter values and price range
* Added - Badge position configurable from admin settings
* Update - Catalog health dashboard: SQL-based, quarantine excludes no-image products

= 1.0.0 =
* Initial release
* REST API: /discovery, /catalog/search, /catalog/products, /catalog/product/{id}, /catalog/categories, /catalog/health
* Normalized product data: price, stock, gender inference, color families, size detection
* Agent signals: link rel kalicart-agent, badge, robots.txt, sitemap-agentic-bridge.xml
* Catalog health dashboard with quarantine and improvement suggestions
