=== KaliCart Bridge – Product Feed for ChatGPT & AI Agents ===
Contributors: carthub
Tags: chatgpt, woocommerce, ai agents, agentic commerce, product feed
Requires at least: 6.0
Tested up to: 7.1
Stable tag: 1.0.131
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

= 1.0.131 =
**Contract hardening.** Compact catalog responses now carry enough evidence for an agent to distinguish a real match, a soft-filter no-op, a valid but unobserved value and a silently wrong request.

* New: `fields=summary` always includes `gender`, explicitly `null` when it cannot be inferred. A gender-filtered scan reuses the evidence it already computed instead of inferring it twice.
* New: `filter_effect.gender` reports the complete baseline evaluated after all strict filters, with matched, unknown-retained and excluded counts plus `changed_result_set`. A proven no-op emits `GENDER_FILTER_NO_EFFECT`.
* New: zero-result guidance can identify `VALID_FILTER_VALUE_NOT_OBSERVED`, `VALID_CATEGORY_NO_RESULTS` and `FILTER_COMBINATION_ELIMINATED_RESULTS`, but only from the current query or at most two drop-one probes. More complex or incomplete evidence keeps the generic recovery guidance.
* Fix: all result guidance uses the `code` field. The legacy one-off `guidance_code` spelling is removed, and a specific evidentiary finding takes precedence over generic triage.
* Fix: catalog REST endpoints reject unknown query parameters with HTTP 400 and `search_executed:false`. In particular, `search` on `/catalog/products` now points to `/catalog/search?q=...` with invalid parameters, corrections, the correct endpoint and a copyable suggested URL.
* New: summary prices include `currency` and `encoding: decimal_major_units`. Price-filtered responses echo `query_interpretation`, including the requested range and the interval-overlap matching rule.
* Fix: compact product verification exposes top-level WooCommerce `attributes`; `variants[].attributes` remains the separate variation-level evidence. REST, OpenAPI and MCP describe the same projection.
* Fix: category scope is explicit. `get_meta.categories` is a flat list of populated categories; `list_categories` is the complete hierarchical taxonomy, including empty and Uncategorized nodes.
* Fix: a facet snapshot without a timestamp now reports `computed_at:null` and `freshness_status:unknown`, then queues an asynchronous rebuild. Stale snapshots are also queued for refresh; public metadata requests never perform the full-catalog scan inline.

= 1.0.130 =
**Contract change.** Filter values that were previously tolerated are now rejected. `gender=uomo` used to return results on the REST surface; it no longer does. If you call the Bridge directly, send canonical values.

* Fix: an unrecognised `gender` value used to return results. Because most products have no detectable gender, `gender=kids` and `gender=inventato` both returned the same set — a plausible answer to a filter that had filtered nothing, and no way for an agent to tell. Unknown values are now rejected before any search runs.
* Fix: REST and MCP applied opposite rules to the same input. MCP rejected `uomo` with an enum error, REST accepted it and returned a subset. Both now share one vocabulary and one normaliser.
* Change: no more aliases. The MCP schema promised `uomo` and `rosso` while the runtime rejected them. Facet values are canonical only; `q` remains natural language. Input is trimmed and lowercased — `MALE ` and `male` are the same value written differently, `uomo` and `male` are two different words, and translating the second pair is the agent's job, not the catalog's.
* Change: the MCP schema declares `string` rather than `enum`, so a conforming client no longer blocks a merely mis-cased value before the request reaches the Bridge, and the structured error reaches the agent with `received`, `normalized`, `accepted_values` and `search_executed: false`.
* New: `gender` is the only soft filter, and now says so. A product whose gender cannot be determined is retained as a candidate, not silently mixed in: every result carries `filter_evidence.gender`, and the response carries `gender_summary` with counts over the whole result set. When nothing is confirmed, `NO_CONFIRMED_GENDER_MATCHES` says it. `color`, stock, sale and price stay strict — the asymmetry is deliberate and now documented.
* New: `get_meta` separates `accepted_values`, the contract, from `available_values`, what this catalog currently holds, with the age of that snapshot. An accepted value that is absent returns zero results, never an error.
* Change: removed "always current" and "not available". A response is read from WooCommerce at request time; that is the perimeter, not a promise it matches the storefront. Zero results proves something about the query, not about the catalog.
* Fix: admin styling — nothing below 13px, and the tab bar stacks on narrow screens.

= 1.0.129 =
* Change: the assistants section is now two independent blocks instead of one table. Three columns side by side read as a sequence — crawler, then visit, then order — while the numbers come from systems that share no identifier. Each block now states its own source: what KaliCart recorded, and what WooCommerce attributed.
* New: a block that says plainly what the plugin cannot tell you. "KaliCart's impact on sales: not estimable with the available data." It is always visible, and it is the boundary within which every other number keeps its credibility.
* New: catalog requests are split between automated reads by AI providers and lookups made while a person was using an assistant. Grouping them made the panel claim "ChatGPT asked for your catalog" when ChatGPT's user-facing agent had never touched it — true to the letter, false in substance.
* Change: the catalog figure comes first and store pages follow as context. Pages stay visible — hiding them would be the same selectivity we avoid elsewhere — but they are not what the plugin makes possible.
* Change: colours removed from this section. They were carrying a claim about how proven each number was; with the blocks separated, any colour would rebuild the very link the structure is there to deny.
* Change: the section is marked Beta. It is new and will change; the counts are real requests, what is still uncertain is how much they weigh on orders.
* Fix: the admin tabs now stack on narrow screens instead of overflowing horizontally.

= 1.0.128 =
* Fix: orders and revenue are now counted separately. An order with a recorded source is not income until it has a payment date: bank transfer and cash-on-delivery orders reach a paying status with no payment recorded, so they were inflating the figure. The panel now shows orders, orders with payment recorded, and value net of refunds as three distinct numbers.
* Fix: the assistant totals were capped at 500 orders without saying so, which silently understated the count on busier stores. The report now pages through the whole window.
* Fix: order sources are matched on a normalised host instead of a substring, so a referrer such as `chatgpt.com.evil.example` no longer counts as ChatGPT.
* The payment criterion is now the same one the agent checkout funnel already used, so the two sections of the panel cannot disagree.

= 1.0.127 =
* Change: colours in the assistants table now state a level of proof, not an intensity. Green is used only where something is demonstrated — requests that reached routes which do not exist without the Bridge. Orders from an assistant that also queried your catalog are amber: same period, no proven link. Everything else is grey.
* Removed: the "Confirmed" banner. Both halves of it were true, the link between them was not: the bot queries from the provider's servers, the customer buys from their own browser, and no identifier is shared between the two. A sentence a merchant repeats to others needs a trace, not a coincidence. It will return when an order can carry the catalog's own signature.
* New: a legend spelling out what each colour claims, including that amber proves nothing.
* Fix: the agent checkout funnel now appears only when net paid value is above zero. Counting sessions let it through on a real store showing 1 session and three zeros — exactly what hiding it was meant to prevent.
* Fix: catalog requests count only agent-like clients. Browser traffic on those routes is the shop owner or a testing tool, and was being presented as anonymous agents.
* Code: admin page variables carry the full plugin prefix again, clearing 14 Plugin Check warnings introduced in 1.0.126.
* i18n: new strings translated to Italian, German, Spanish and French.

= 1.0.126 =
* New: "AI assistants and your store" in the Stats tab. One row per assistant, three columns: pages read, KaliCart catalog requests, and orders converted. The first column is what any site gets; the second exists only where the Bridge is installed. Reads the 31 days already recorded, so it is populated the moment you update — no migration.
* New: order attribution from AI assistants, sourced from WooCommerce's own order attribution. A "Confirmed" line appears only when the same assistant both queried your catalog and brought customers who bought in the same window; it never shows on an empty cross-check.
* New: Applebot is now recognised, so Siri and Spotlight visits are counted. Evaluated after Applebot-Extended, which stays distinct: indexing and model training are not the same thing.
* Change: the agent checkout funnel is hidden while empty. Four zeros read as a broken plugin; a line now explains what will appear there when an assistant completes an order on its own.
* i18n: all new strings translated to Italian, German, Spanish and French.

= 1.0.125 =
* Fix: the min_price filter discarded variable products that had purchasable variants inside the requested range. The post-filter compared only the lowest active price, cancelling the range-overlap semantics already declared by the SQL pre-filter. It now compares the whole interval.
* New: price.max_current and price.max_regular are returned on price.type=range, in both the summary listing and the product detail. price.current is the lowest active price across variants; without these fields the interval was readable only inside the formatted price.display string.
* Agent instructions and the CatalogPrice schema document the interval semantics.

= 1.0.124 =
* Compatibility: tested up to WordPress 7.1.

= 1.0.123 =
* Distribution: returns KaliCart Bridge to WordPress.org from the approved 1.0.120 baseline. Versions 1.0.121 and 1.0.122 were distributed externally and are superseded by this release.
* Updates: removes the custom Update URI, standalone updater and external plugin-details override. Future updates are provided by WordPress.org.
* Privacy and federation: restores the explicit opt-in and revocation flow approved in 1.0.120. The upgrade removes only the automatic 1.0.122 lifecycle job and its technical retry state; merchant settings are preserved.
* Compatibility: installs running 1.0.120, 1.0.121 or 1.0.122 converge on the same WordPress.org-compatible package without changes to catalog, feed, MCP or checkout behavior.
* Housekeeping: shortens the directory changelog so WordPress.org can display it without truncation.

= 1.0.122 =
* External-only release: briefly introduced an automatic federation lifecycle and Bridge-hosted plugin-details metadata. It was never published on WordPress.org and is superseded by 1.0.123, which restores explicit merchant consent and the native WordPress.org update flow.

= 1.0.121 =
* External-only release: introduced a standalone HTTPS updater for installations outside WordPress.org. It was never published on WordPress.org and is superseded by 1.0.123; the standalone updater is removed after convergence.

= 1.0.120 =
* Discovery controls: the three storefront-link toggles are independent, accurately described and remain off by default on new installations.
* Catalog accuracy: size is explicitly detail-only and unsupported as a parent-product search filter; agents must verify purchasable product variations.
* Price metadata: catalog ranges use WooCommerce's public product lookup and lowest-sale statistics include only currently active, public sale entities.
* Lifecycle: reactivation preserves merchant settings; disabling or deactivating discovery removes only Bridge-owned .well-known files and disabled routes return 404.
* OpenAPI: price sale scope, variant discount counts and catalog deal statistics are now explicitly typed.

= 1.0.119 =
* Checkout privacy: all checkout-session REST responses now send private no-store headers, preventing reverse proxies from serving stale bearer-session data after cancellation or expiry. Discovery now documents the real pending and cart_loaded states.
* Variable sales: product summaries distinguish some_variants from all_variants, report discounted and priced variation counts, and require selected-variation verification when only particular sizes or colors are discounted. The calculation reuses WooCommerce's existing variation price matrix and adds no per-variation queries.
* Sale statistics: catalog metadata now separates product cards on sale from individually discounted variations while retaining on_sale_total as the product-level compatibility field.
* Catalog accuracy: products without a usable price remain discoverable but sort after priced products, so ascending price pages no longer fill with zero-price placeholders.
* Performance: a missing facet snapshot queues a background rebuild instead of scanning the full catalog during a public request. Saving unrelated settings no longer flushes rewrite rules.
* Lifecycle hardening: deactivation clears recurring jobs; uninstall removes all Bridge options, dynamic facets, scheduled feed work, generated catalog feeds and plugin-owned public discovery mirrors.

= 1.0.118 =
* New: checkout attribution. Orders created from a Bridge checkout session — through either classic checkout or Checkout Block — are linked back to that session. A local 30-day funnel (sessions created, carts loaded, orders linked and net paid value) is shown in the Stats tab of the Bridge dashboard. No data is sent to the cloud.
* Accuracy: net paid value includes only linked orders for which WooCommerce has recorded a payment date, net of refunds. Cash on delivery, bank transfer and cheque orders are excluded unless WooCommerce records an actual payment confirmation.
* Checkout integrity: attribution is written only when the live order still matches the exact product, variation and quantity fingerprint loaded by the Bridge. Cart mutations and partial/failed loads clear attribution; direct variation IDs are normalized correctly; each session can claim at most one order atomically. Reused links return a generic HTTP 410 without exposing the original order.
* Checkout hardening: the opt-in session endpoint now enforces a pre-parser JSON body limit, strict integer inputs, 20-line and aggregate-quantity ceilings, WooCommerce purchase limits, short weighted request limits and a longer storage budget. GET, DELETE and checkout links are also bounded. Proxy forwarding is trusted only from a configurable allowlist and parsed fail-closed.
* Idempotency: concurrent requests with the same Idempotency-Key are serialized through fixed, bounded database buckets. The original public 201 response is replayed without internal attribution fields; conflicting payloads and unavailable originals cannot create a duplicate session.
* MCP hardening: the server now declares only MCP 2025-06-18, rejects JSON-RPC batches and invalid object shapes/types, validates tool schemas without coercion, enforces JSON Content-Type, Origin, protocol header and body bounds before parsing, and weights catalog work in its abuse budget.
* Catalog security and performance: public discovery/catalog work uses proxy-safe weighted limits; expensive derived filters run in bounded batches with an explicit candidate ceiling; variable-product price filters are verified against price.current after a WooCommerce lookup-table prefilter; repeated compact derived queries use one size-bounded short cache.
* Telemetry and maintenance: concurrent local counters no longer lose increments; rejected requests, MCP metadata and checkout paths do not amplify telemetry writes; storefront HTML telemetry is branded-agent-only and bounded by default. Fixed and dynamic security state, claims, legacy sessions and caches are cleaned on expiry/uninstall.

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

= Earlier releases =
* Release notes older than 1.0.110 are omitted from the WordPress.org directory changelog.
