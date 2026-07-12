=== KaliCart Bridge – Product Feed for ChatGPT & AI Agents ===
Contributors: carthub
Tags: chatgpt, woocommerce, ai agents, agentic commerce, product feed
Requires at least: 6.0
Tested up to: 7.0
Stable tag: 1.0.118
Requires PHP: 8.0
WC requires at least: 7.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Validated OpenAI-compatible product feed for ChatGPT product discovery (ACP), plus catalog API, MCP server and UCP discovery for AI agents.

== Description ==

Make your WooCommerce catalog ready to be read and verified by AI shopping systems.

KaliCart Bridge gives you an immediate catalog-readiness report inside WooCommerce: it identifies products with missing images, brand, usable description, category, price or SKU, and links you directly to the native Products screen to fix them. It then exposes your live catalog in a structured form for AI agents and can generate a validated product feed ready for ChatGPT merchant submission.

No LLM runs on your site. No cloud service or customer data is sent anywhere by default. Prices, availability and product changes remain live on your WooCommerce store.

The plugin does not promise traffic, ChatGPT placement or merchant approval. It prepares and exposes your catalog; OpenAI controls access to its own shopping surfaces and delivery channel.

**What you get after installation**

* A catalog-readiness report with actionable product fixes.
* A current, structured catalog API for agents that reach your store.
* An optional ChatGPT feed, validated before export.
* Optional inclusion in KaliCart's federated catalog, only with explicit consent.

Documentation: https://bridge.kalicart.com/docs/

**For developers and AI integrations**

**What it does:**

* Exposes a `/discovery` endpoint — the single entry point any agent needs to understand your catalog
* Provides `/catalog/search`, `/catalog/products`, `/catalog/product/{id}`, `/catalog/categories` endpoints
* Exposes a Model Context Protocol (MCP) server at `/wp-json/kalicart/v1/mcp` (JSON-RPC 2.0) so MCP-capable agents can call the catalog as tools — same data as the REST endpoints, no API key
* Gives AI chatbot and assistant builders a structured catalog source to ingest or call, instead of scraping product pages
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
* No cloud dependency for core functionality
* No data sent anywhere outside your server by default — the only optional exception is the Federated Catalog feature (see "External services" below), which you turn on explicitly
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

**Model Context Protocol (MCP) endpoint:**

The same read-only catalog is also exposed as an MCP server at `/wp-json/kalicart/v1/mcp` (JSON-RPC 2.0 over HTTP POST). MCP-capable agents and assistants connect to it and call the catalog as tools: `search_products`, `list_products`, `get_product`, `list_categories`, `get_meta`. It is read-only and needs no authentication, exactly like the public REST endpoints — it adds a second transport, not new data. No LLM, no external calls. Checkout and payment are never exposed over MCP.

== ChatGPT Product Discovery Feed ==

1. **Configure and generate.** Set your return policy URL and target countries (derived from your WooCommerce selling locations); optionally set a brand fallback for own-label stores. The plugin validates every row against the OpenAI Product Feed specification: incomplete store configuration blocks generation entirely, products without image or brand are excluded and counted, and a failed run never destroys the last valid feed.
2. **Apply at chatgpt.com/merchants.** Application and approval are required and decided by OpenAI.
3. **Deliver after approval.** Upload the generated file (stable filename, `jsonl.gz` supported) on the delivery channel OpenAI assigns. The plugin regenerates a full daily snapshot via WP-Cron.

No acceptance or visibility is guaranteed by the plugin: approval, delivery access and final ranking are determined by OpenAI.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/kalicart-bridge/`
2. Activate the plugin through the Plugins screen in WordPress
3. Navigate to **KaliCart** in the admin menu
4. The catalog is immediately accessible at `yourdomain.com/wp-json/kalicart/v1/discovery`
5. MCP-capable agents can connect to the MCP server at `yourdomain.com/wp-json/kalicart/v1/mcp`

Full operational documentation is always available at `https://bridge.kalicart.com/docs/`.

== Frequently Asked Questions ==

= Does the ChatGPT feed work outside the U.S.? =

The feed format is region-neutral. Merchant eligibility and shopping-surface availability are determined by OpenAI and may vary by market.

= Does the plugin handle checkout? =

No, by design. The feed is discovery-only (`is_eligible_checkout=false`): customers buy on your storefront and you remain merchant of record. This matches OpenAI's own shift toward merchant-owned checkout.

= Does this share my data with KaliCart or any third party? =

Not unless you choose to. By default the plugin runs entirely on your server and sends nothing externally. The only exception is the optional Federated Catalog feature: if you explicitly activate it, the plugin sends your store's public URL (and nothing else) to KaliCart Global so your public catalog can be included in federated agent search. No customer, order, or private data is ever sent. See "External services" below, and the privacy notice at https://bridge.kalicart.com/privacy/.

= Do I need a KaliCart account? =

No. This plugin is fully standalone and free.

= What is the Federated Catalog? =

The Federated Catalog is an optional discovery network operated by KaliCart Global. The Bridge makes your catalog readable by agents that already reach your domain; the Federated Catalog makes it discoverable by agents that do not know your store yet. It is opt-in, separate from the local Bridge endpoints, and revocable at any time.

There are two discovery paths. Local signals — `.well-known` files, robots.txt entries, the `rel="kalicart-agent"` head link and the badge — help an agent that lands on your own domain find the Bridge. They do not feed the federated index by themselves. The federated index is a separate cross-merchant index: after you activate it, KaliCart Global reads your already-public `/discovery` and `/catalog/*` endpoints and lets agents search across participating stores.

End to end: from WP Admin → KaliCart Bridge you activate the Federated Catalog; the plugin sends only your public site URL; KaliCart Global pulls the public catalog read-only; matching results route agents back to your store. Authoritative price, availability and checkout stay on your WooCommerce site, served live by your Bridge. If you revoke consent, your catalog leaves federated results while direct agent access to your store remains active.

= Who can access the catalog endpoints? =

Public endpoints (discovery, search, products, categories) are accessible without authentication — same as the WooCommerce REST API public surfaces. The `/health` endpoint requires `manage_woocommerce` capability.

= Can I disable the badge or robots.txt injection? =

Yes — all three signals (badge, robots.txt, sitemap) can be toggled individually in the KaliCart settings tab.

= How often is the health report cached? =

5 minutes. You can force a refresh via the "Refresh analysis" button or the `?force=true` query parameter on the `/health` endpoint.

= How do I connect this to an AI assistant or MCP client? =

The catalog can be consumed two ways. Any agent can call the plain REST endpoints under `/wp-json/kalicart/v1/catalog/` directly. MCP-capable clients can instead connect to the MCP server at `/wp-json/kalicart/v1/mcp` (JSON-RPC 2.0): the assistant connects to that URL — no API key — and gains the catalog tools (search_products, get_product, list_categories, get_meta, list_products). In clients that support remote MCP connectors, add it as a custom connector pointing to that URL.

= Can WooCommerce chatbot services use the Bridge? =

Yes, when the chatbot service can ingest URLs, API documents or external knowledge sources. Give it the discovery URL first: `https://yourdomain.com/wp-json/kalicart/v1/discovery`. From there it can find the catalog endpoints, the OpenAPI description and the MCP endpoint. If the service supports live REST or MCP calls, it can query current catalog data directly. If it only imports a knowledge base, it may create a snapshot: useful for product answers, but final price, stock, coupons, shipping and checkout should still be verified on the merchant site.

The benefit is practical: chatbot builders can read a structured, machine-readable WooCommerce catalog instead of scraping product pages. The Bridge does not add an LLM to your site and does not decide how the chatbot works; it supplies cleaner catalog data for tools that can consume it.



== External services ==

This plugin works fully standalone. It connects to one external service **only if you explicitly opt in** by activating the optional Federated Catalog feature in WP Admin → KaliCart Bridge.

**Service:** KaliCart Global (https://dashboard.kalicart.com)

**When data is sent:** Only when an administrator clicks "Activate Federated Catalog" (and, symmetrically, "Revoke consent"). Nothing is sent automatically, on activation, or in the background. With the feature off, the plugin makes no external requests.

**What is sent:** A single value — your site's public URL (e.g. https://yourstore.com). On revoke, the same URL is sent to withdraw. No customer data, orders, personal data, credentials, or API keys are ever transmitted.

**What the service does:** The URL tells KaliCart Global your store wishes to be discovered. KaliCart Global then periodically reads your already-public catalog (the same data exposed by the Bridge's public REST endpoints) and includes it in federated agent search. It only reads; it never writes to your store. Revoking stops this and parks your catalog.

**Privacy notice:** https://bridge.kalicart.com/privacy/
**Terms / documentation:** https://bridge.kalicart.com/docs/

== Changelog ==

= 1.0.118 =
* New: checkout attribution. Orders created from a Bridge checkout session — through either classic checkout or Checkout Block — are linked back to that session. A local 30-day funnel (sessions created, carts loaded, orders linked and net paid value) is shown in the Stats tab of the Bridge dashboard. No data is sent to the cloud.
* Accuracy: net paid value includes only linked orders for which WooCommerce has recorded a payment date, net of refunds. Cash on delivery, bank transfer and cheque orders are excluded unless WooCommerce records an actual payment confirmation.
* Hardening: POST /checkout/session now has per-client and short global rate limits with filterable thresholds. X-Forwarded-For is parsed from the nearest hop and honored only when the direct connection comes from a configurable trusted-proxy allowlist. Each checkout session can produce at most one attributed conversion, enforced atomically. Reused session links return a generic HTTP 410 response throughout the claim-retention window without revealing details about the original order. Requested quantities are validated against the effective WooCommerce product or variation’s maximum purchasable quantity before creating a session.
* Maintenance: checkout-session claim records are removed automatically by a daily cleanup with filterable retention, and during plugin uninstall.

= 1.0.117 =
* New: "Check external visibility" in the Federated Catalog panel. Shows what KaliCart Global observed from outside the last time it probed your /discovery endpoint — the same reachability an external agent depends on. Clearly labeled as a snapshot (not a live scan), scoped to discovery reachability only, with a staleness warning past 7 days. Read-only: does not trigger a new probe or change federation consent.

= 1.0.116 =
* New: POST /checkout/session honors an optional Idempotency-Key header. Retrying with the same key and payload returns the original session instead of creating a duplicate; reusing a key with a different payload returns 409. Hardens agent retries and double-submits on an existing endpoint.

= 1.0.115 =
* Fix: products whose description is empty markup (empty paragraph, `&nbsp;`, non-breaking space) are no longer dropped from the ChatGPT feed. The product name is used as a fallback, and entity-only descriptions no longer leak into the feed. Plain-text extraction now decodes HTML entities.
* Change: the AI catalog badge is now off by default on new installs. Existing installs and merchant choices are preserved across updates.
* New: ChatGPT feed readiness reports how many rows were sent with the product name in place of a real description.
* The validator's excluded-row count is now always shown in the feed snapshot summary.
* Readme: Description rewritten to lead with the catalog-readiness report; technical details grouped under "For developers and AI integrations".

= 1.0.114 =
* Quality Signals: the Quarantine tab renamed and rebuilt for large catalogs - honest sample (the most recent 100, with full counts and a visible note), plus per-problem filter buttons that open the native Products list pre-filtered (bulk and quick edit for free)
* New Products-list filters: short titles, no description, no category, no price, no SKU - served from the cached health report, so button counts and list contents always match
* Clarified in the UI: nothing in Quality Signals is blocked or hidden - products stay fully served to agents with their issues declared as quality flags and score, which agents can weigh
* Overview: new suggestion for products without a brand - not required by the agent-readable catalog, search or MCP, but required by the ChatGPT product feed specification
* Signals cleanup: removed the api-catalog head link and its robots.txt entry (the extensionless /.well-known path returns 404 on most hosting setups); the discovery and OpenAPI links remain, and the physical api-catalog.json file stays served for clients that probe it

= 1.0.113 =
* Performance - ChatGPT feed generation is dramatically faster on large catalogs: product data is now batch-primed (posts, meta and terms per page, variations included), collapsing thousands of per-product queries; catalogs that previously took ~20 seconds generate in a fraction of that, and very large catalogs no longer risk PHP execution-time limits
* ChatGPT Feed - the tab is now named for what it is; single "Save and generate/validate now" action (the separate save-only button always left an unverified state), with a progress spinner while the snapshot is generated
* Fix - saving settings now reliably regenerates the feed: generation no longer depends on the submit button's own value, which browsers omit when submitting with the Enter key
* Fallback brand - the "Fallback applied" readiness state is now a soft amber notice instead of red: with the merchant's explicit declaration it is a legitimate configuration for own-label stores, not an error
* Translations - fixed five interface strings per language that silently rendered in English at runtime (Italian, French, German, Spanish are now fully translated end to end)
* Housekeeping - the optional "Agent entry-point page" shortcode section has been retired from Settings and from the discovery document: real-world telemetry showed agents use the structured discovery signals, not HTML directory pages. Existing pages using the shortcode keep rendering unchanged

= 1.0.112 =
* Agent Commerce - the ChatGPT feed now lives in a dedicated Agent Commerce tab inside the plugin dashboard, with a readiness checklist (return policy, countries, brand, images, schema validation, daily refresh, delivery status), live data-gap counts with one-click access to the pre-filtered Products list and a full CSV export, and a step-by-step guide to OpenAI's application and delivery workflow
* ChatGPT feed - missing brand is no longer blocking: rows enter the file without the brand field (never an empty or fabricated value) and are counted and flagged, with an explicit notice that OpenAI may reject them and that the merchant submits them under their own responsibility; products without a primary image remain excluded as the specification requires
* Catalog - the merchant-declared brand (WooCommerce Brands taxonomy, Perfect Brands or brand attributes) is now exposed across every surface: product detail, search summaries, full records, OpenAPI schemas and the ChatGPT feed, from a single resolver; HTML entities in brand names are decoded everywhere
* Interface - the new tab fully adopts the plugin design system (cards, buttons, toggle, status pills, flex-row lists instead of tables)
* Translations - the entire Agent Commerce experience is fully translated in all shipped locales: Italian, French, German and Spanish

= 1.0.111 =
* ChatGPT product discovery - new OpenAI-compatible product feed generator (ACP file-upload specification): per-row schema validator as a hard gate (every emitted row is conformant), atomic snapshot swap that preserves the last valid feed on any failure, global configuration gate (return policy, countries), honest exclusion counts for products missing image or brand, stable filename ready for the delivery channel OpenAI assigns after merchant approval
* Feed admin - new ChatGPT Shopping page with readiness statistics, exclusion counts, feed downloads and settings: opt-in brand fallback for own-label stores, return policy URL (defaults to your Refund and Returns page), target countries derived from WooCommerce selling locations

= 1.0.110 =
* Scraper-facing discovery - an HTML comment at the top of every page and an HTTP Link header (rel="kalicart-agent") now point AI agents to the structured catalog in the very surfaces they scrape; validated in blind agent tests (3/3 autonomous discovery vs 0/1 without these signals)
* MCP handshake - /mcp/.well-known/oauth-protected-resource now answers with RFC 9728 Protected Resource Metadata carrying an empty authorization_servers list, telling MCP clients explicitly that this keyless server requires no OAuth instead of a 404 they must interpret
* Agent traffic insight - new opt-out telemetry counts daily agent traffic per surface (storefront HTML, catalog REST, MCP) with client classification (branded agents, anonymous programmatic, generic clients, browsers), API route and status breakdown, and MCP client identity, method, tool and outcome from the protocol handshake; server-internal and health-check traffic is excluded; data stays local in a single option, 31-day retention
* OpenAPI accuracy - the fields parameter is now documented per endpoint (search defaults to summary; products defaults to full and switches to summary when filters are present) and a ProductSummary schema describes the slim projection, so OpenAPI-driven clients no longer read summary responses as missing fields
* Agent instructions - step 5 now names /catalog/search and /catalog/products explicitly for summary-based triage

= 1.0.109 =
* Catalog triage - search and filtered product browsing now default to the compact summary projection when fields is omitted; MCP search and list tools always return summaries and default to 10 results, while unfiltered REST enumeration remains full for federated index compatibility
* Product verification - /catalog/product/{id} now returns compact purchase-relevant evidence by default (price, stock, variants, shipping and coupons); append fields=full only when descriptions, images or complete metadata are required
* Agent guidance - summary responses declare which facts are already complete and direct verification to the final selected product only; price_min and price_max mistakes now return a corrected summary URL instead of being ignored

= 1.0.108 =
* Discovery - agent_instructions now explicitly prescribes the fields=summary triage strategy for browse and listing over large catalogs: request the slim per-item projection (roughly an order of magnitude smaller than the default full record) for cheap triage at scale, then fetch /catalog/product/{id} (or fields=full) only for the candidates worth pursuing. The projection shipped in 1.0.107 but was only documented under search_filters, so agents following the numbered instruction flow never reached it; it is now part of the instruction sequence and is also surfaced in human_readable_summary. No change to API behavior: full remains the default.

= 1.0.107 =
* Catalog search - new `fields` query parameter on `/catalog/products` and `/catalog/search`. `fields=summary` returns a slim per-item projection — `id`, `sku`, `name`, `url`, `price.current`, `price.display`, `stock.in_stock`, `categories`, `type` and `updated_at` — so an agent can triage a large catalog cheaply and open `/catalog/product/{id}` in full only for the candidates worth pursuing. `fields=full` remains the default, so existing consumers are unaffected. On a full page the summary payload is roughly an order of magnitude smaller than full, and on browse and search without post-filters the response is also assembled with far fewer database queries because the heavy per-product work (attribute terms, images, gender/colour inference, shipping, variants) is skipped
* Core - query_products() now declares a default for the on_sale argument, avoiding an Undefined array key notice when the catalog engine is called directly without it. The public REST API always supplied the argument, so live endpoints were never affected

= 1.0.106 =
* Catalog search - variable products in list and search context now include a variation_summary field with total_variations, in_stock_variations_count (proxy: variants with a price) and cheapest_available_price. Uses get_variation_prices() which is already cached by WooCommerce — no extra query. Agents can use this to decide whether a detail call is needed without fetching it
* Catalog search - price object now includes discount_amount (absolute value: regular minus current, rounded to 2 decimals) for all products with on_sale=true. Previously only discount_pct was available and agents had to compute the absolute saving themselves
* Catalog search - new size soft post-filter (?size=M, ?size=1, ?size=42). Returns products where at least one variant attribute value matches the requested size string (case-insensitive exact match). Applied after search with the same pagination contract as gender and color filters. Does not guarantee the size is in stock — verify via product detail. Works with both taxonomy attributes (pa_taglia) and custom attributes (Size, Taglia)
* Catalog search - sizes extraction now matches by attribute name in addition to key, so custom attributes not prefixed with pa_ (e.g. key=size name=Size) are correctly identified as size attributes. Previously only taxonomy keys like pa_taglia were recognized
* Catalog search - sizes.type now returns alphanumeric when the majority of size values do not match any known vocabulary (clothing, numeric, shoes). Covers cup sizes (36C, 38D), hardware codes (M8, DN50), and any format the Bridge does not recognise. Previously these were misclassified as shoes due to overlap with numeric shoe size ranges
* Catalog search - stock.agent_note now carries a race condition warning when quantity equals 1: Last unit available. Race condition possible — complete checkout immediately. Applied to both product-level stock and per-variant stock in the detail endpoint
* Catalog meta - deal_statistics field added: on_sale_total (count of products with an active WooCommerce sale price) and lowest_sale_price. Computed at meta generation time and cached with the rest of the meta response. Agents can use this to decide whether filtering on_sale=true is worthwhile before running the search
* Catalog meta - accepted_filters.orderby is now a structured object with values, default, default_order and a note explaining that price sorts by WooCommerce _price meta and that variable product parent price may differ from variant prices
* Discovery - shipping cost fields now serialized with round() to 2 decimal places, eliminating IEEE 754 float artifacts (e.g. 4.9000000000000003552...) in the JSON wire format
* Discovery - size filter documentation updated across discovery, accepted_filters and query construction guidance to reflect the new soft post-filter behaviour
* Core - added rest_post_dispatch filter that sets serialize_precision=-1 for all /kalicart/v1/ REST responses. On PHP hosts where serialize_precision is set to a high value (e.g. 17), json_encode emits full IEEE 754 float representations regardless of rounding applied in PHP code. Setting serialize_precision=-1 before WordPress serializes the response ensures floats are always emitted with the minimum digits needed to round-trip correctly, on any hosting environment

= 1.0.105 =
* Catalog search - price.current is now always present on variable products (type=range). Previously only min_regular, max_sale etc. were exposed; agents reading price.current on a variable product received a missing field. The field is now computed as min_sale ?? min_regular and returned alongside the range fields
* Catalog search - orderby=price now excludes products with no price from results. Previously products with a missing _price meta were sorted before all priced products when ordering ASC, making the first result useless to agents
* Catalog search - total and total_pages now reflect post-filters (gender, color, on_sale, variable price). Previously total reflected the WP_Query count before PHP-side filtering, so an agent could see total=120 with returned=0 when filtering by color
* Catalog search - on_sale=true now excludes products where the Bridge discount threshold (1%) is not met. WooCommerce includes products with a sub-1% discount in its sale index; the Bridge now applies the same gate it uses in price.on_sale so the two are always consistent
* Catalog search - max_price and min_price filters now use the authoritative price computed from variant prices for variable products, instead of the stale _price meta WooCommerce may have left on the parent when the product type was changed. Variable products are now post-filtered against price.current after normalization
* Product detail - Variant objects now include a stock field with in_stock, quantity, quantity_tracked and confidence. Previously the field was null on every variant
* Product detail - Variable products that are out of stock now report purchase_readiness.status as out_of_stock instead of variant_selection_required. Asking an agent to select a variant on an OOS product was a contradictory signal
* Catalog search - Variable products in list and search context now include purchase_readiness.variant_options_note, which names the available attribute options and links directly to the product detail endpoint for per-variant price and stock
* Categories - /catalog/categories response now includes total_root (root-level count) and total_all (all nodes including children). The note field now explains that the response is hierarchical (subcategories are in children[]) and directs agents to /catalog/meta for a flat list of all slugs
* Catalog meta - /catalog/meta now includes available_genders and available_colors: the gender and color values actually present in this merchant catalog, with counts, computed at meta generation time and cached with the rest of the meta response. This is distinct from accepted_filters which lists theoretically valid values regardless of catalog content
* Catalog meta - available_genders and available_colors are now computed by a background WP-Cron job (every 12 hours) and stored as a persistent option. The meta endpoint reads the pre-computed result instead of scanning all products inline on every request. On first install, a one-time synchronous computation runs so the data is immediately available; all subsequent requests are served from the stored option without added latency
* Catalog search - pagination now works correctly when post-filters (gender, color, on_sale, price) are active. Previously the response always returned total_pages=1 and total equal to the number of results on the current page only, making it impossible for an agent to paginate. The engine now collects the full filtered result set internally and slices it to the requested page and per_page, returning the correct total and total_pages across all pages

= 1.0.104 =
* Agent guidance - Added explicit runtime signals for agents using the public catalog API: `q` is the only text-search parameter, `per_page` is the result-count parameter, `/catalog/search` is for text search, and `/catalog/products` is for browsing/listing. Invalid aliases such as `query`, `limit`, or `q` on `/catalog/products` now return a guided 400 response with the corrected endpoint and `suggested_url`
* Agent guidance - Zero-result searches now include recovery guidance so agents retry with a simpler product spine or category browse before declaring an item unavailable
* Documentation - Added FAQs explaining the optional Federated Catalog and how WooCommerce chatbot/assistant services can use the Bridge as a structured catalog source instead of scraping product pages
* Admin - Removed the duplicate Global indexing consent toggle from the Settings tab. Federated Catalog consent is now managed only from the header banner via Activate/Revoke, while the internal consent flag remains the discovery source of truth

= 1.0.103 =
* Agent coupon visibility - Merchants can now choose exactly which coupons AI agents are allowed to see. A new Coupons tab adds a master switch (off by default, so no coupon is ever exposed unless you decide to) and a list of your active coupons to pick from. Selected coupons are presented to agents as conditional savings, with WooCommerce checkout always remaining the final authority on whether a coupon actually applies. Private, targeted or newsletter codes you do not select are never shared with agents

= 1.0.102 =
* Federated Catalog panel - Updated the activation banner copy to make the benefit clearer and the action more deliberate, and added a distinct post-activation confirmation message (the panel now reads differently before and after the catalog is federated). Text and translation update only; no functional code changed. Strings localized in Italian, German, French and Spanish

= 1.0.101 =
* Discoverability - Refined the plugin's directory metadata to better describe what it does and who it is for. The display name, search tags, short description and opening description now state plainly that the plugin makes a WooCommerce catalog computable and readable by AI agents and assistants (via REST API and an MCP server). No functional code changed in this release

= 1.0.100 =
* i18n - Translated the Federated Catalog admin panel into Italian, German, French and Spanish. The panel was already translation-ready in code but its strings (added in 1.0.97) were missing from the translation catalogs, so they previously fell back to English in every locale. Regenerated the translation template and updated all four locale catalogs: activation and revoke buttons, the consent description, the two-step revoke confirmation and the dynamic status messages are now localized

= 1.0.99 =
* Multilingual - Fixed duplicate products and categories on sites running a database-translating multilingual plugin (WPML/WooCommerce Multilingual, Polylang). The public agent catalog is now served exclusively in the site default language: each translated product or category previously appeared once per language, multiplying the catalog. The request context is pinned to the default language at the start of every public catalog request, so product enumeration, the category tree and the flat category list all return the canonical default-language entries only
* Multilingual - The single-product endpoint (/catalog/product/{id}) now canonicalizes a translated product ID to its default-language counterpart and returns it; an ID with no mapping into the default language returns 404, keeping the catalog identity stable for agents regardless of which translation ID they hold
* Multilingual - The product total now reflects the canonical default-language catalog instead of counting every translation; the catalog meta cache is namespaced per language. No-op on monolingual sites and on output-translating plugins (e.g. Weglot, GTranslate proxy) where the database holds a single language

= 1.0.98 =
* Security / hardening - Checkout session REST endpoints reviewed for WordPress.org compliance. Read-only public routes now declare an explicit public permission_callback (__return_true). The destructive DELETE (cancel session) route uses a dedicated permission_callback that validates the token format and confirms the session exists before allowing cancellation. Session IDs are now cryptographically secure 128-bit random bearer tokens (random_bytes) instead of md5(uniqid()). The same explicit-public posture was applied to the read-only catalog and MCP endpoints

= 1.0.97 =
* Federated Catalog (opt-in) - New optional feature: from WP Admin you can join the KaliCart Global federated agent network. Clicking "Activate Federated Catalog" is an explicit consent action that sends only your store's public URL to KaliCart Global, which then includes your already-public catalog in federated agent search. A two-step "Revoke consent" control withdraws and parks your catalog at any time. No customer, order, or private data is ever sent
* Privacy - Consent for Global indexing now defaults to OFF (opt-in). Added an "External services" section to this readme and a public privacy notice (https://bridge.kalicart.com/privacy/) disclosing exactly what is sent and how it is used, per WordPress.org guidelines 6 and 7
* Admin - The Federated Catalog panel is shown under the plugin header and uses the plugin's native button and alert styles for consistency

= 1.0.96 =
* Discovery - The read-only product listing endpoint (/wp-json/kalicart/v1/catalog/products) now accepts an optional modified_after parameter (ISO-8601 datetime). When provided, only products changed at or after that time are returned, filtered on the WordPress post modification date. This lets federated indexers and agents pull incremental updates instead of re-reading the whole catalog. Invalid or absent values are ignored and the full catalog is returned, so existing callers are unaffected

= 1.0.95 =
* Admin - The wp-admin sidebar menu label now reads "KaliCart Bridge" (was "KaliCart") to clearly distinguish it from other KaliCart tools

= 1.0.94 =
* Compliance - Removed the "Powered by" attribution link from the public [kalicart_agent_index] shortcode output, per WordPress.org guideline 10 (no credit links on user-facing pages without explicit opt-in)
* Performance - Removed an unused get_posts() query that ran on every public discovery request without using its result

= 1.0.93 =
* i18n - The wp-admin screen and the storefront badge are now fully translatable. Every admin string (catalog health, quarantine list, suggestions, endpoint descriptions and toggle warnings) and the badge text is wrapped for translation; JavaScript strings are localized server-side via wp_localize_script so they ship in the translation catalog
* i18n - Ships French, Italian, German and Spanish translations and follows the WordPress site language, with English as the fallback for other locales

= 1.0.92 =
* Discovery - Added an OpenAPI 3.1 description of the read-only catalog API at /wp-json/kalicart/v1/openapi (paths, query filters and response shapes for search, products, product/{id}, categories and meta), advertised via <link rel="service-desc"> and as a service-desc link in the API Catalog so generic agents and API tooling can consume the catalog without the KaliCart convention
* Discovery - The discovery document now lists the OpenAPI endpoint under endpoints.openapi

= 1.0.91 =
* Discovery - Added an RFC 9727 API Catalog at /.well-known/api-catalog (an RFC 9264 linkset, served as application/linkset+json) that advertises the catalog API, the MCP endpoint, the discovery document and the UCP profile in the standard vocabulary generic agents and API-readiness probes understand; also linked via <link rel="api-catalog"> in the document head
* Discovery - Added a Content-Signal header (search, ai-input, ai-train) on every Bridge REST response and in the robots.txt block, mirroring the existing crawler_policy so AI usage preferences are declared in the emerging standard format

= 1.0.90 =
* Feature - Added a Model Context Protocol (MCP) server at /wp-json/kalicart/v1/mcp (JSON-RPC 2.0 over HTTP POST) that exposes the read-only catalog as agent tools (search_products, list_products, get_product, list_categories, get_meta); self-contained, no authentication, no external calls, the same data as the REST endpoints over a second transport
* Discovery - The discovery document now advertises the MCP endpoint via capabilities.mcp and endpoints.mcp
* Docs - Documented the MCP endpoint in the readme and the wp-admin Endpoints tab

= 1.0.89 =
* Compatibility - Refuse activation when WooCommerce is not active, on every supported WordPress version. WP 6.5+ already blocks it via the "Requires Plugins" header; this adds an activation-time guard (auto-deactivate + notice) that also covers WP 6.0-6.4 where that header is ignored

= 1.0.88 =
* Fix - Removed the broken "View details" link from the plugins list. WordPress provides a native View details link once the plugin is published on WordPress.org

= 1.0.87 =
* Build - Plugin Check (wp plugin check) now runs automatically on ZIP contents before every release; bump aborts if errors are found

= 1.0.86 =
* Housekeeping - Remove dev-only files from plugin directory: .distignore, README.md, SPEC.md

= 1.0.85 =
* Fix - wp_delete_file() replacing unlink() in .well-known cleanup (Plugin Check compliance)
* Fix - phpcs:ignore NonceVerification on public discovery GET endpoint (no nonce applicable)
* Fix - phpcs:disable/enable around local template variables in admin page (false-positive PrefixAllGlobals)

= 1.0.84 =
* Fix - Escape all inline style output in admin page with esc_attr() and esc_html() (Plugin Check compliance)
* Fix - Rename admin variables to kalicart_bridge prefix (WordPress naming conventions)
* Fix - Replace unlink() with wp_delete_file() in .well-known cleanup
* Fix - sanitize_key() applied to GET input in well-known discovery handler

= 1.0.83 =
* Fix - Plugin URI updated to bridge.kalicart.com (plugin-specific page, distinct from Author URI kalicart.com)

= 1.0.82 =
* Build - ZIP now excludes .git, .gitignore, .distignore, README.md, SPEC.md

= 1.0.81 =
* Housekeeping - Remove internal release-process section from readme.txt (not relevant to end users)

= 1.0.80 =
* Compliance - Remove plugins_api override: the plugin no longer intercepts the WordPress "View details" modal or provides an external download_link; WordPress.org manages updates for directory installs
* Privacy - Agent hints (DOM signals: menu trace, search/category/product JS hints) are now opt-in with default OFF; merchant activates from WP Admin → KaliCart → Settings
* Housekeeping - uninstall.php now removes all 14 plugin options and 4 transients on deletion
* Build - .distignore added for wp dist-archive packaging

= 1.0.79 =
* UX - Admin header brand mark replaced with inline KaliCart SVG logo; wp-admin menu icon updated to the KaliCart glyph
* Fix - Endpoint explorer links the .well-known discovery files via their .json mirrors so test links resolve on every host
* UX - Admin accent color aligned to KaliCart blue (#0070f3)

= 1.0.78 =
* Fix - /.well-known/ discovery also published as physical .json mirrors (kalicart-bridge.json, agent-catalog.json, ucp.json, agent.json), served as application/json on every host including those that serve /.well-known/ as a static location (nginx ACME setups) where the WP rewrite never runs
* Feature - REST endpoint /wp-json/kalicart/v1/ucp exposes the UCP profile, always reachable when /.well-known/ucp is intercepted by the webserver
* Update - discovery, robots.txt and agentic sitemap advertise the reachable .json mirrors and include the UCP profile; rewrite handler accepts the .json form

= 1.0.77 =
* Fix - /.well-known/ucp, /.well-known/kalicart-bridge, /.well-known/agent-catalog now served exclusively by WP rewrite handler (Content-Type: application/json on all stacks including nginx); removed legacy physical extension-less files and Apache-only .htaccess ForceType
* Fix - serve_well_known() reads $wp->query_vars (parse_request API) with $_GET fallback; migration version-gated (kalicart_bridge_wk_version) runs once per version, flushes rewrite rules on update


= 1.0.76 =
* Feature - KaliCart Global indexing consent toggle in Settings, opt-out model (active by default), single option kalicart_bridge_global_consent driving discovery consent flags
* UX - Disabling the consent toggle shows a warning alert

= 1.0.75 =
* API - variants is always an array, never null - list/search return [] for variable products
* API - discovery documents list-context variants behavior (list_context_note)
* API - cache claim aligned with real Cache-Control header
* API - agent_index_url emits real null when not configured
* API - shipping costs numeric when computable, Woo formulas kept as string
* API - shipping zones without regions expose an honest locations_note
* API - .well-known discovery files include plugin version

= 1.0.74 =
* UX - Return policy block updates in real-time (input, save, tab switch)
* UX - Health report refreshed after save
* Fix - Configure link switches to Settings tab correctly

= 1.0.73 =
* Fix - Return policy block color updates in real-time after save

= 1.0.72 =
* UX - Return policy block turns green when configured, orange when missing

= 1.0.71 =
* UX - Refund and Returns Policy URL field moved to top of Settings tab
* UX - Site URL pre-filled, merchant enters only the page slug

= 1.0.70 =
* Fix - Configure link in suggestion correctly switches to Settings tab

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
