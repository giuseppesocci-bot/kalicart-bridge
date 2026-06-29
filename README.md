# KaliCart Bridge

> **Free WooCommerce plugin that makes your product catalog machine-readable for AI shopping agents.**  
> ARC/1.0 reference implementation · No LLM · No cloud · No API key

[![WordPress.org](https://img.shields.io/badge/WordPress.org-plugin-blue)](https://wordpress.org/plugins/kalicart-bridge/)
[![License: GPL v2](https://img.shields.io/badge/License-GPLv2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0)
[![ARC/1.0](https://img.shields.io/badge/spec-ARC%2F1.0-green)](https://bridge.kalicart.com/spec/)

---

## The problem: AI agents default to scraping

When an AI shopping agent visits a WooCommerce store today, its default behavior is to fetch the storefront HTML and scrape product data from it. This is the wrong approach — and in a documented blind test on a live store, the cost was measurable:

| Approach | API calls | Payload | Stock accuracy | Color filtering |
|---|---|---|---|---|
| HTML scraping | 5–6 | ~400 KB | High (dropdowns show real variants) | Manual, from text |
| WordPress REST (`wp/v2/product`) | 2 | ~77 KB (truncated) | Low — `class_list` reports defined sizes, not in-stock ones | None |
| **KaliCart Bridge (ARC/1.0)** | **2** | **~4–5 KB** (≈10 KB incl. discovery) | **High — per-variant quantity with confidence level** | **Native `color=` filter** |

*Blind test: finding a dark-colored women's two-piece swimsuit, size S, under €150, on a live WooCommerce store. The agent was given no hints. It scraped HTML first, then tried the generic WordPress REST API (which produced a false positive on stock), and found the structured `kalicart/v1` catalog only when explicitly pointed at it — even though that namespace was reachable from `/wp-json` the whole time. See [BENCHMARK.md](./BENCHMARK.md) for the full run, including honest caveats (n=1, discovery cost).*

**The signal to do better has always been there.** Every WordPress site already emits `<link rel="https://api.w.org/" href="/wp-json/">` in the HTML `<head>`. An agent that reads the head before scraping finds the REST API namespace in one call, discovers `kalicart/v1`, and filters natively. KaliCart Bridge makes that path correct and complete.

---

## What KaliCart Bridge does

KaliCart Bridge installs on any WooCommerce store and exposes five things:

1. **A discovery document** (`/wp-json/kalicart/v1/discovery`) — a self-describing JSON contract that tells an agent everything it needs: all endpoint URLs, accepted filters, shipping policy, coupon rules, agent instructions. One GET, no documentation required.

2. **A computable catalog** — structured REST endpoints for search, product listing, categories, and per-product detail including live stock per variant, price encoding declaration, purchase readiness, and shipping eligibility.

3. **Agent discovery signals** — `<link rel="kalicart-agent">` in the storefront `<head>`, `.well-known/kalicart-bridge.json`, `.well-known/agent-catalog.json`, `robots.txt` entries, and an agentic sitemap — so agents that land on the storefront can find the API without scraping.

4. **UCP interoperability** — `/.well-known/ucp.json` declares `catalog.search` and `catalog.lookup` capabilities for UCP-compliant agents (ChatGPT, Copilot, Gemini).

5. **Federated catalog opt-in** — one toggle publishes eligible products into [KaliCart Global](https://global.kalicart.com), a read-only multi-merchant index that AI agents can query across stores without knowing any individual merchant in advance.

---

## Architecture

```
AI agent
    │
    ├─ 1. GET global.kalicart.com/v1/global-catalog/search?q=...  ← find merchants + products
    │       (federated index — optional first step)
    │
    ├─ 2. GET {merchant}/wp-json/kalicart/v1/discovery             ← read contract
    │
    ├─ 3. GET {merchant}/wp-json/kalicart/v1/catalog/search        ← native filters
    │       ?q=bikini&color=purple&in_stock=true&max_price=150
    │
    └─ 4. GET {merchant}/wp-json/kalicart/v1/catalog/product/{id}  ← verify variants + stock
            → per-variant quantity, shipping eligibility, purchase readiness
                │
                └─ Checkout always on merchant storefront (KaliCart never processes payments)
```

**Three layers of authority:**
- **KaliCart Global** — discovery and routing across the federated network
- **Merchant bridge** — authoritative price, live stock, variant detail
- **Merchant storefront** — sole authority for checkout and payment

---

## Quick start for merchants

1. Download [`kalicart-bridge-latest.zip`](https://bridge.kalicart.com/download/kalicart-bridge-latest.zip) or install from [WordPress.org](https://wordpress.org/plugins/kalicart-bridge/)
2. Upload and activate from **WP Admin → Plugins → Add New → Upload**
3. WooCommerce must be active
4. All discovery signals are injected automatically on activation
5. Visit **WP Admin → KaliCart** for the catalog health dashboard

That's it. Your catalog is now machine-readable.

---

## Quick start for AI agents

### Step 1 — Discover the API from the HTML head

Every ARC-compliant storefront emits this tag:

```html
<link rel="kalicart-agent" type="application/json"
      href="https://shop.example.com/wp-json/kalicart/v1/discovery" />
```

**Read the `<head>` before scraping.** This single tag gives you the discovery document URL directly.

### Step 2 — Read the discovery document

```
GET https://shop.example.com/wp-json/kalicart/v1/discovery
```

The discovery document is a self-describing contract. It contains:
- All endpoint URLs
- Accepted filter values (categories, colors, genders, price range)
- Shipping policy
- Coupon rules
- `agent_instructions` — ordered steps for correct query construction
- `result_guidance` — embedded in every response, tells you what to do next

### Step 3 — Search with native filters

```
GET /wp-json/kalicart/v1/catalog/search
    ?category=donna-beachwear-resortwear-bikini
    &color=purple
    &in_stock=true
    &max_price=150
```

**Critical rule:** `q` must contain only the bare product noun. Every attribute (color, gender, size, price) must go in its own filter — never stacked into `q`.

```
✓ correct
GET /catalog/search?q=bikini&color=black&gender=female&max_price=150&in_stock=true

✗ wrong — zero results
GET /catalog/search?q=black+women+bikini+under+150
```

### Step 4 — Verify variant stock

Search responses return product-level stock. For variable products (size, color variants), fetch the detail endpoint to get per-variant quantities:

```
GET /wp-json/kalicart/v1/catalog/product/{id}
```

Response includes:
```json
{
  "variants": [
    {
      "attributes": { "pa_taglia": "s" },
      "stock": {
        "in_stock": true,
        "quantity": 1,
        "confidence": "numeric_stock_quantity",
        "agent_note": "Last unit available. Race condition possible — complete checkout immediately."
      }
    }
  ],
  "shipping": {
    "free_shipping_available": true,
    "free_shipping_eligible_by_product_price": true
  }
}
```

### Alternative: use the federated index

To find products without knowing the merchant in advance:

```
GET https://global.kalicart.com/v1/global-catalog/search
    ?q=bikini&color=purple&gender=female&price_max=150&availability=in_stock
```

Results include `merchant_id` and a link to the merchant's bridge for authoritative verification.

---

## The `result_guidance` contract

Every KaliCart Bridge response includes a `result_guidance` field that tells the agent how to proceed:

```json
{
  "result_guidance": {
    "code": "SUMMARY_TRIAGE",
    "next_step": "rank_from_summary_then_verify_one_selected_product",
    "fact_coverage": {
      "complete_for": ["product_identity", "catalog_price", "availability_status"],
      "detail_required_for": ["exact_variants_or_sizes", "stock_precision_beyond_status"]
    },
    "detail_fetch_policy": {
      "verification_url_template": "https://shop.example.com/wp-json/kalicart/v1/catalog/product/{id}"
    }
  }
}
```

This means agents do not need external documentation to know what to do next. The API tells them.

---

## Specification

KaliCart Bridge is the reference implementation of **[ARC/1.0 — Agent-Readable Catalog](https://bridge.kalicart.com/spec/)**, an open specification (CC BY 4.0) for exposing e-commerce catalogs as agent-consumable REST surfaces.

ARC/1.0 defines:
- Three discovery signals (HTML head link, `.well-known/`, `robots.txt`)
- The discovery document contract
- Catalog endpoint requirements
- The product object schema (price encoding, stock confidence levels, variant contract)
- Consent model for federated indexing
- UCP interoperability
- Security rules (read-only, no API keys on public surfaces)

Any e-commerce platform can implement ARC/1.0. KaliCart Bridge does it for WooCommerce.

---

## Why this matters at scale

Every AI agent that scrapes instead of using a structured catalog wastes:
- **~40× more data per task** (~400 KB scraped vs ~10 KB end-to-end including discovery, in the documented test)
- **More API calls** (5–6 vs 2 for the catalog calls)
- **LLM tokens** parsing irrelevant HTML boilerplate
- **Latency** on every user-facing query

The data returned by scraping the generic WordPress REST API is also less accurate: HTML dropdowns show truly available variants, but the `wp/v2` `class_list` field reports all defined sizes regardless of current stock. In the documented test, an agent trusting `class_list` would have reported a size as available when it was sold out.

As agentic commerce grows, the aggregate cost of the scraping default — compute, latency, and wrong answers handed to shoppers — becomes significant and avoidable. ARC/1.0 and KaliCart Bridge exist to change that default.

---

## Links

- **Plugin hub & download**: [bridge.kalicart.com](https://bridge.kalicart.com)
- **ARC/1.0 specification**: [bridge.kalicart.com/spec](https://bridge.kalicart.com/spec/)
- **Documentation**: [bridge.kalicart.com/docs](https://bridge.kalicart.com/docs/)
- **Federated catalog**: [global.kalicart.com](https://global.kalicart.com)
- **WordPress MCP plugin**: [mcp.kalicart.com](https://mcp.kalicart.com)
- **Institutional**: [kalicart.com](https://kalicart.com)
- **WordPress.org**: [wordpress.org/plugins/kalicart-bridge](https://wordpress.org/plugins/kalicart-bridge/)

---

## License

Plugin code: [GPLv2](https://www.gnu.org/licenses/gpl-2.0)  
ARC/1.0 specification text: [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/)  
© 2026 Save The Brain
