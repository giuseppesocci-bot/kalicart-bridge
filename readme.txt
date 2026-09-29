=== KaliCart Bridge – Agentic Commerce Catalog, ChatGPT Product Feed & MCP ===
Contributors: carthub
Tags: agentic commerce, chatgpt, product feed, mcp, ucp
Requires at least: 6.0
Tested up to: 7.1
Stable tag: 1.0.139
Requires PHP: 8.0
WC requires at least: 7.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Agent-readable WooCommerce catalog: product feed checked against the OpenAI spec, MCP server, catalog API and UCP Catalog via KaliCart Global.

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
* No data sent anywhere outside your server by default — the optional Federated Catalog and provider-specific distribution channels require separate explicit actions (see "External services" below)
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

1. **Configure and generate.** Optionally set your return policy URL and target countries (derived from your WooCommerce selling locations) and, for own-label stores, a brand fallback. The plugin validates every row against the current OpenAI Product Feed specification - neither looser nor stricter: required fields are required, optional fields are included only when known and valid, products without image or brand are excluded and counted, and a failed run never destroys the last valid feed.
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

Not unless you choose to. By default the plugin runs entirely on your server and sends nothing externally. Activating the optional Federated Catalog sends your store's public URL to KaliCart Global. A provider-specific distribution channel is a second, separate opt-in: its tamper-evident receipt contains the public URL and authorization metadata and hashes, but never the administrator identity or localized consent text. No customer, order, payment or credential data is sent. See "External services" below, and the privacy notice at https://bridge.kalicart.com/privacy/.

= Do I need a KaliCart account? =

No. This plugin is fully standalone and free.

= What is the Federated Catalog? =

The Federated Catalog is an optional discovery network operated by KaliCart Global. The Bridge makes your catalog readable by agents that already reach your domain; the Federated Catalog makes it discoverable by agents that do not know your store yet. It is opt-in, separate from the local Bridge endpoints, and revocable at any time.

There are two discovery paths. Local signals — `.well-known` files, robots.txt entries, the `rel="kalicart-agent"` head link and the badge — help an agent that lands on your own domain find the Bridge. They do not feed the federated index by themselves. The federated index is a separate cross-merchant index: after you activate it, KaliCart Global reads your already-public `/discovery` and `/catalog/*` endpoints, and the public catalog files this plugin writes on your site, and lets agents search across participating stores.

End to end: from WP Admin → KaliCart Bridge you activate the Federated Catalog; the plugin sends only your public site URL; KaliCart Global reads the public catalog (from the catalog API or from the public catalog files); matching results route agents back to your store. Authoritative price, availability and checkout stay on your WooCommerce site, served live by your Bridge. If you revoke consent, your catalog leaves federated results while direct agent access to your store remains active.

= Is this UCP or ACP compatible? =

ACP: the plugin builds the OpenAI-compatible product feed for ChatGPT product discovery (see "ChatGPT Product Discovery Feed" above).

UCP: the plugin does not publish a UCP profile on your store, because it does not implement the UCP binding on your site. If you join the Federated Catalog, KaliCart Global serves your products to UCP shopping agents in the UCP Catalog format (specification 2026-08-25), with price and availability read live from your Bridge when an agent opens a product and your store answers in time. There is nothing extra to configure.

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

This plugin works fully standalone. It connects to one external service **only after an explicit administrator action** in WP Admin → KaliCart Bridge. Federated Catalog participation and each provider-specific distribution authorization are separate choices; a provider channel cannot be authorized until the Federated Catalog is active.

**Service:** KaliCart Global (https://dashboard.kalicart.com)

**When data is sent:** When an administrator activates or revokes the Federated Catalog, requests its external-visibility status, or explicitly grants or revokes a named federated distribution channel. After a provider receipt exists, opening the plugin page requests its processing status using the public site URL and consent ID. A provider receipt that could not be delivered is sent again after about 1 minute, 5 minutes, 30 minutes, 2 hours and 12 hours, then once a day until KaliCart Global has received or rejected it (retries use WP-Cron). While the Federated Catalog is active, the plugin also proves this installation's identity once and then sends a signed status signal about once a day; it sends a signed leaving signal when the plugin is deactivated or deleted, and a signed consent-off signal when federation is revoked. Nothing is sent merely because the plugin is installed or activated.

**What is sent:** Federated Catalog activation, revocation and visibility checks send the site's public URL (e.g. https://yourstore.com). A provider authorization receipt additionally sends its consent ID, provider, purpose, action, UTC timestamp, plugin and terms versions, consent locale, and SHA-256 evidence-chain hashes. The administrator's WordPress user ID and localized consent text remain only in the store's local evidence log. The identity signals send the site's host, a random installation ID, this installation's public key, a one-time verification code, the consent state and the consent text version, the plugin, WordPress and PHP versions, the status of the public catalog files (sequence number, file address and hashes, product count, whether complete, generation time) and whether the store is in WooCommerce "coming soon" or WordPress maintenance mode; when the plugin itself hit a fatal error, a normalized error code, the time and a short non-reversible hash - never the error message, file paths or stack traces. The private key never leaves the site. No customer, order, payment, credential or API-key data is transmitted.

**What the service does:** The URL tells KaliCart Global your store wishes to be discovered. KaliCart Global periodically reads your already-public catalog and includes it in federated agent search. For a separately authorized provider channel, KaliCart Global stores the minimal receipt and verifies the matching authorization against the store's public discovery document before the channel can become eligible. It only reads public catalog data; it never writes to your store. Since 1.0.139 it can also read the catalog from public files this plugin writes on your site, in `/.well-known/kalicart/` (or in `wp-content/uploads/kalicart/` when that folder cannot be written): the same public catalog data as the catalog API, without exact stock quantities. The files are updated when products are edited and deleted when the Federated Catalog is revoked or the plugin is deactivated or deleted. In its answer to the status signal, KaliCart Global reports how it reads the catalog; the plugin stores that report locally and shows it only in the plugin page and in Site Health. If the plugin folder is removed outside WordPress, the catalog files remain on the site, and KaliCart Global does not use them without a current signed status.

**Retention:** KaliCart Global keeps the current state while the store takes part; events and reading reports for 90 days, then only anonymous daily totals without site address, URL or IP address; server logs containing IP addresses for 30 days. 30 days after revocation or removal, the catalog, its copies, the events and the current identity state are deleted. Only a minimal revocation record (site address and revocation date, so that the store is not listed again by mistake) and the history of the installation's signing keys (public keys and verification dates, kept as a security record) remain. The Federated Catalog consent receipt stays in the store's WordPress database until the plugin is deleted and is not sent to KaliCart Global.

**Privacy notice:** https://bridge.kalicart.com/privacy/
**Terms / documentation:** https://bridge.kalicart.com/docs/

== Changelog ==

= 1.0.140 =
**A channel authorization reaches KaliCart Global even when the first delivery fails, and the dashboard is shorter and easier to read.**

* Fix: the receipt of a distribution channel authorization was retried only twice, after one and five minutes, and then stayed "awaiting delivery" for good. It is now sent again after about 30 minutes, 2 hours and 12 hours, then once a day until KaliCart Global has received or rejected it (retries use WP-Cron). Receipts left waiting by earlier versions are sent again without a new click: at the next visit to an admin screen or at the daily status signal, whichever comes first.
* Change: once active, the Federated Catalog and the distribution channel each take one compact row: status, text version, date and the revoke button. Proof and export are under "Authorization details and proof".
* Change: the external visibility check runs when you click it. It shows four results (access from outside, Bridge detected, store identity, last check), each colored by its outcome, and when access is limited it names the cause.
* Fix: after an update the dashboard could show new markup with old styles, because the styles were cached by plugin version. Admin styles and scripts now change address when their file changes.
* Fix: an empty "Consent ID" row no longer appears; texts on the Federated Catalog banner have more contrast.
* No consent text changes: existing authorizations and their receipts stay valid.
* Translated in Italian, German, Spanish and French.

= 1.0.139 =
**KaliCart Global can read the catalog even when the catalog API is not reachable from outside.**

* New: public catalog files. While the Federated Catalog is active, the plugin writes the public catalog as static files in `/.well-known/kalicart/` (or in `wp-content/uploads/kalicart/` when that folder cannot be written), updated when products are edited and once a day. They contain the same public data as the catalog API, without exact stock quantities, and are deleted when the Federated Catalog is revoked or the plugin is deactivated or deleted. Nothing is written without the Federated Catalog.
* Change: the Federated Catalog consent text (version 1.1) says that KaliCart Global also reads these public files and lists exactly what the daily status signal contains. Same data, same recipient: stores that already joined keep their consent. New activations record a local receipt (text version, time, user, text hashes) in a tamper-evident chain; the receipt stays on the site.
* New: the ChatGPT distribution channel can be chosen in the same screen as the Federated Catalog, with an unchecked box. It is still a separate authorization with its own receipt, and its text is unchanged.
* New: Site Health reports, as "recommended" at most, when the status signal has not been delivered for more than 3 days, when the catalog files cannot be written, are incomplete or are old, and when KaliCart Global reports that it cannot read the catalog. Each result says what was measured and what happens by itself.
* The report from KaliCart Global is data: only known fields are kept and it never triggers an action on the site.
* Deleting the plugin also deletes the local consent receipts.
* Translated in Italian, German, Spanish and French.

= Earlier releases =
* The complete release history is in changelog.txt, included with the plugin.
