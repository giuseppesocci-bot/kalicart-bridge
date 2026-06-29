# A Blind Test — How an AI Agent Searches a Live WooCommerce Store

> **A single documented run comparing three approaches an AI agent can take to find a product on a live WooCommerce store running KaliCart Bridge.**  
> Task: find a dark-colored women's two-piece swimsuit, size Small, under €150.  
> Store: a live WooCommerce store with ~1,000 products running KaliCart Bridge 1.0.109.

---

## Why this is called a blind test, not a benchmark

This is **n=1**: one task, one store, one agent session. It is reported here because what the agent *did before being told what to do* is the interesting result — not a statistical average. The character counts below are approximate (they depend on the fetch method; in this run the browser extension was disconnected and the agent fell back to plain HTTP fetches rather than clean page navigation). Treat the numbers as an order-of-magnitude illustration, not a precise measurement. The methodology is fully reproducible (see the end of this document); running it 3–5 times would turn it into a proper benchmark.

The single most important finding is qualitative and does not depend on exact byte counts:

> **The agent scraped HTML first. It tried the generic WordPress REST API second. It found the purpose-built `kalicart/v1` catalog only when a human explicitly told it to look — even though that namespace was reachable from `/wp-json` from the very first request.**

---

## Task definition

**User query (natural language):** *"Find a women's two-piece dark swimsuit, size Small, under €150. Facts only, no guesses."*

**Required verifications before reporting a result:**
- Product is a bikini (two-piece)
- Color is dark (black, navy, purple, brown, etc.)
- Size Small is currently available (not just defined in the catalog)
- Price is under €150
- Shipping cost confirmed

---

## What the agent actually did (unprompted order)

1. **Scraped the storefront HTML** — homepage, then the bikini category, then individual product pages.
2. **Looked for `llms.txt`** — not present.
3. **Tried the generic WordPress REST API** (`wp/v2/product`) — returned truncated JSON and a false positive on size availability.
4. **Used `kalicart/v1` only after being told to** — at which point it found the correct answer in 2 calls with no false positives.

The sections below detail each approach.

---

## Approach 1 — HTML scraping

### Method
Fetch the storefront homepage, navigate to the beachwear category, apply filters via URL parameters, fetch individual product pages.

### Calls and payloads (approximate)

| Call | URL | Payload | Purpose |
|---|---|---|---|
| 1 | `/` (homepage) | ~85 KB | Initial orientation |
| 2 | `/donna/beachwear-resortwear/bikini/` | ~95 KB | Category listing |
| 3 | `/donna/beachwear-resortwear/bikini/?min_price=0&max_price=150` | ~90 KB | Price filter |
| 4 | `/product/...-viola-lucido/` | ~78 KB | Product detail — confirmed S available |
| 5 | `/product/...-drappeggiato-marrone/` | ~76 KB | Second candidate — only L available |

**Total: ~5 calls · ~420 KB**

### What scraping gets right
- Product page dropdowns show **only truly available size variants** — the most accurate source for per-variant stock at the point of scraping
- Works on any WooCommerce store regardless of plugins installed

### What scraping gets wrong
- No native filtering for color — must read all products and identify dark colors from text/images manually
- No native filtering for size availability — must visit individual product pages
- Payloads are dominated by navigation menus, footer HTML, scripts, and CSS references — most of each response is irrelevant to the task
- Fragile: theme changes can break selectors

### Result
Found: **Miss Bikini Bikini a Triangolo Con Rouches Viola Lucido** — €120, viola (dark purple), size S confirmed available, free shipping (order > €79). Correct, but at the cost of five heavy fetches.

---

## Approach 2 — WordPress REST API (`wp/v2/product`)

### Method
Use the public WordPress REST API to fetch products by category. No authentication required.

### Calls and payloads

| Call | URL | Payload | Notes |
|---|---|---|---|
| 1 | `/wp-json/wp/v2/product?product_cat=1113&per_page=100` | ~77 KB (truncated) | All bikini products — response cut mid-JSON |

**Total: ~2 calls (plus the namespace discovery call) · ~77 KB**

### Problems encountered

**JSON truncation:** The response was cut mid-JSON in this run. The `_fields` parameter (`_fields=id,title,class_list`) did not reduce the payload as expected, and full `content`/`excerpt` HTML inflated every record. Whether the cut was server-side or a client-side fetch limit was not conclusively determined — but the practical outcome was a response that did not parse as valid JSON and required a regex fallback to extract `title` and `class_list`.

**False positive on size (the core problem):** The `class_list` field contains all taxonomy terms associated with a product, including every size attribute *defined* for it — not the sizes currently *in stock*. A product with `pa_taglia-s` in `class_list` has a size S defined in its attribute set, not necessarily available to buy.

Concrete false positive observed: the Marrone bikini showed `pa_taglia-s` in `class_list`. Verification on the product page revealed only size L was actually available. An agent trusting `class_list` would have reported size S as available — incorrect. This is a structural property of the `wp/v2` data model, not a one-off glitch: `class_list` is not a reliable source for current per-variant stock.

**No color filter, no price filter:** Neither is exposed by `wp/v2`. Color must be inferred from the title (unreliable for style-name colors like "Tortora" or "Ecorce"); all products must be downloaded and price-filtered client-side.

### Result
Identified the correct product, but also produced the size false positive above, and required a separate product-page call to confirm shipping.

---

## Approach 3 — KaliCart Bridge (ARC/1.0)

### Method
Use the native catalog API with structured filters, starting from the discovery document.

### Calls and payloads

| Call | URL | Payload | Purpose |
|---|---|---|---|
| 1 | `/wp-json/kalicart/v1/catalog/search?category=donna-beachwear-resortwear-bikini&color=purple&in_stock=true&max_price=150` | ~1.8 KB | Filtered search — 1 result |
| 2 | `/wp-json/kalicart/v1/catalog/product/408517` | ~2.4 KB | Variant verification + shipping |

**Total: 2 calls · ~4.2 KB** (catalog calls only; see "Honest accounting of discovery cost" below)

### What the search response returned (call 1)

```json
{
  "products": [
    {
      "id": 408517,
      "name": "Miss Bikini Bikini a Triangolo Con Rouches Viola Lucido",
      "price": { "current": 120, "display": "120,00 €", "on_sale": false },
      "stock": { "in_stock": true },
      "type": "variable"
    }
  ],
  "total": 1
}
```

One result. Exact match. No false positives.

### What the detail response returned (call 2)

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
    },
    {
      "attributes": { "pa_taglia": "m" },
      "stock": { "in_stock": true, "quantity": 1 }
    }
  ],
  "shipping": {
    "free_shipping_available": true,
    "free_shipping_eligible_by_product_price": true,
    "free_shipping_thresholds": [79]
  }
}
```

Size S: confirmed in stock, quantity 1. Shipping: free (product price €120 > threshold €79). All required verifications complete in 2 calls, with no false positives.

---

## Honest accounting of discovery cost

The 2-call / ~4.2 KB figure for KaliCart **assumes the agent already knows the `kalicart/v1` endpoint.** A cold agent does not. The fair comparison adds the discovery a cold agent must perform:

```
GET /wp-json                              (~5 KB — list namespaces, see kalicart/v1)
GET /wp-json/kalicart/v1/discovery        (~3 KB — read the contract)
GET /wp-json/kalicart/v1/catalog/search   (~1.8 KB)
GET /wp-json/kalicart/v1/catalog/product/{id} (~2.4 KB)
≈ 4 calls, ≈ 12 KB end-to-end from a cold start
```

So the honest headline is **~400 KB (scraping) vs ~10–12 KB (KaliCart, including discovery) — roughly an order of magnitude less data**, and materially more accurate. The "100×" you get by comparing only the two catalog calls against full-page scraping is real for those two calls, but it is not the cold-start cost, and this document does not claim it as the headline.

---

## Summary comparison

| Metric | HTML scraping | wp/v2 REST API | KaliCart Bridge (ARC/1.0) |
|---|---|---|---|
| **API calls** | ~5 | ~2 | **2** (4 incl. discovery) |
| **Total payload** | ~420 KB | ~77 KB | **~4.2 KB** (~12 KB incl. discovery) |
| **Order-of-magnitude vs scraping** | 1× | ~5× smaller | **~35–40× smaller end-to-end** |
| **Native price filter** | ✗ | ✗ | ✓ |
| **Native color filter** | ✗ | ✗ | ✓ |
| **Native stock filter** | ✗ | ✗ | ✓ |
| **Per-variant stock accuracy** | ✓ (dropdown) | ✗ (false positive) | ✓ (numeric quantity) |
| **Shipping confirmed** | Extra call | Extra call | ✓ (in product detail) |
| **Response parses as valid JSON** | N/A | ✗ (truncated) | ✓ |
| **False positive on size in this run** | No | Yes (Marrone S) | No |
| **Agent instructions embedded** | ✗ | ✗ | ✓ (`result_guidance`) |

---

## A note on token cost

Exact token usage was not instrumented in this run, and a naive characters×0.75 estimate overstates how repetitive HTML boilerplate tokenizes. The agent itself estimated its scraping session at "tens of thousands" of tokens. The defensible claim is therefore directional, not precise: **processing ~400 KB of HTML costs roughly an order of magnitude more tokens than processing ~10–12 KB of structured JSON for the same task — with worse accuracy.** As agentic commerce grows, that per-query difference, multiplied across many queries, becomes a significant and avoidable cost in compute, latency, and correctness.

---

## A note on the color filter

The `color=purple` filter returned exactly 1 result — the correct one. Color families are extracted from WooCommerce attribute taxonomies and product names. Products where the color is encoded as a non-standard style name ("Tortora", "Ecorce", "Damascato") return `colors: []` and are not matched by the color filter.

In this run, 4 of the in-stock bikinis under €150 had `colors: []`. None of them were dark-colored products that should have matched `color=purple` or `color=black`, so the color filter produced no false negatives for this query — but that is not guaranteed in general. For queries where completeness matters more than precision, the recommended pattern is to query without `color=` and filter client-side on `name`, and for `colors: []` products, use `images[].src` for visual color identification with a multimodal agent. KaliCart cannot invent a color the merchant never structured; it does expose the product image so the agent can determine it visually.

---

## Reproducibility

This blind test was conducted on a live WooCommerce store; exact product availability will differ at the time of reproduction. The methodology (call sequence, endpoints, filter parameters) is fully reproducible on any store running KaliCart Bridge ≥ 1.0.101.

To reproduce:
1. Install KaliCart Bridge on a WooCommerce store
2. Give an agent a concrete shopping task with no hints about the API
3. Observe which data source it reaches for first
4. Then run the three call sequences above and compare payload sizes and stock accuracy against your own HTML scraping baseline

---

*Blind test conducted June 2026 · KaliCart Bridge v1.0.109 · ARC/1.0 · n=1, reproducible*  
*Plugin: [bridge.kalicart.com](https://bridge.kalicart.com) · Spec: [bridge.kalicart.com/spec](https://bridge.kalicart.com/spec/)*
