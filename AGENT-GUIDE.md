# Agent Guide — KaliCart Bridge

> **How AI agents should interact with ARC/1.0 compliant WooCommerce catalogs.**  
> This document describes the correct discovery-first workflow, common mistakes, and the full pattern from first contact to variant verification.

---

## The core principle: discover before you scrape

When an AI agent encounters a WooCommerce storefront, the instinct is to fetch the page HTML and extract product data from it. **This is the wrong first move.**

Every WordPress site emits a machine-readable API pointer in its `<head>`:

```html
<link rel="https://api.w.org/" href="https://shop.example.com/wp-json/" />
```

And every ARC/1.0 compliant store additionally emits:

```html
<link rel="kalicart-agent" type="application/json"
      href="https://shop.example.com/wp-json/kalicart/v1/discovery" />
```

These tags are present on every page, at the top of the `<head>`, before any product content. An agent that reads the head before scraping finds the full API in one call and never needs to touch the HTML again.

**Rule:** On any WordPress or WooCommerce domain, always check `<head>` link relations and `/.well-known/` before fetching page content.

---

## Discovery checklist (run once per domain)

When you first encounter a domain, run this sequence exactly once:

```
1. GET /.well-known/kalicart-bridge.json     → ARC-compliant store confirmed
   (or /.well-known/agent-catalog.json)
   (or read <link rel="kalicart-agent"> from <head>)

2. GET /wp-json/kalicart/v1/discovery        → full contract: endpoints, filters, shipping, agent_instructions

3. GET /wp-json/kalicart/v1/catalog/meta     → accepted filter vocabulary: categories, colors, price range
   (only if you need to enumerate valid filter values)
```

After step 2, you have everything needed to query the catalog correctly. You do not need to read the merchant's HTML, sitemap, or any other page.

**Total: 1–2 calls. Total payload: ~5–10 KB.**

---

## Full agent workflow

### Phase 1 — Domain probe

```
GET /.well-known/kalicart-bridge.json
```

Response signals ARC compliance and gives you the discovery document URL. If this returns 404, fall back to checking `<head>` link tags. If neither is present, the store is not ARC-compliant — proceed with standard REST API discovery (`GET /wp-json` to list namespaces).

### Phase 2 — Read the discovery document

```
GET /wp-json/kalicart/v1/discovery
```

This is the contract. Key fields to read:

| Field | What to do with it |
|---|---|
| `endpoints` | All catalog URLs — use these, never construct URLs manually |
| `public_catalog.search_filters` | Accepted filter parameters and allowed values |
| `public_catalog.query_construction` | Critical rules for `q` parameter |
| `merchant_shipping_policy` | Declarative shipping zones and free-shipping thresholds |
| `coupon_policy.price_rule` | Coupons are checkout savings, never replace `price.current` |
| `agent_instructions` | Ordered steps — read and follow |

### Phase 3 — Search with native filters

```
GET /wp-json/kalicart/v1/catalog/search
    ?q={bare_product_noun}
    &category={slug}
    &color={family}
    &gender={male|female|unisex}
    &in_stock=true
    &min_price={n}
    &max_price={n}
    &per_page={n}
```

**The most important rule — `q` must be bare:**

```
✓ correct
?q=bikini&color=purple&gender=female&max_price=150&in_stock=true

✓ correct
?q=sneakers&brand=nike&gender=male&max_price=100

✗ wrong — attributes stacked in q → 0 results or wrong results
?q=purple+women+bikini+under+150

✗ wrong — Italian utterance pasted directly into q
?q=bikini+donna+viola+sotto+150+euro
```

The `q` parameter is matched against product names in the index. Stacking attributes creates an AND across name tokens and collapses recall to near zero. Put every attribute in its own structured filter.

### Phase 4 — Triage search results

Search responses default to `fields=summary` (slim per-item projection) and return:

```json
{
  "products": [
    {
      "id": 408517,
      "name": "Miss Bikini Bikini a Triangolo Con Rouches Viola Lucido",
      "price": { "current": 120, "display": "120,00 €", "on_sale": false },
      "stock": { "in_stock": true },
      "categories": ["donna-beachwear-resortwear-bikini"],
      "type": "variable",
      "url": "https://shop.example.com/product/..."
    }
  ],
  "total": 1,
  "result_guidance": {
    "code": "SUMMARY_TRIAGE",
    "next_step": "rank_from_summary_then_verify_one_selected_product",
    "fact_coverage": {
      "complete_for": ["product_identity", "catalog_price", "availability_status"],
      "detail_required_for": ["exact_variants_or_sizes", "stock_precision_beyond_status", "shipping"]
    },
    "detail_fetch_policy": {
      "verification_url_template": "/wp-json/kalicart/v1/catalog/product/{id}"
    }
  }
}
```

Read `result_guidance` — it tells you exactly what the current response covers and what requires a detail fetch. Do not invent data for fields listed in `detail_required_for`.

### Phase 5 — Verify the selected product

After ranking candidates from the summary, fetch detail for **one product only** — the one you intend to present to the user:

```
GET /wp-json/kalicart/v1/catalog/product/{id}
```

By default this returns a compact verification record. Append `?fields=full` only when you need descriptions or images. The verification response adds:
- `variants[]` — every size/color variant with individual `in_stock`, `quantity`, and `confidence`
- `shipping` — per-product free-shipping eligibility
- `active_coupons[]` — applicable coupon codes with verification notes
- `purchase_readiness` — whether direct cart add is possible or variant selection is required

**Do not fetch detail for every candidate.** Rank from summary, then verify once.

---

## Reading stock correctly

Stock in KaliCart Bridge has explicit confidence levels. Always read `confidence` before reporting availability:

| `confidence` value | Meaning | Agent rule |
|---|---|---|
| `numeric_stock_quantity` | Exact unit count tracked by merchant | Report quantity; warn if 1 ("last unit") |
| `availability_status_only` | Merchant does not track quantity | Report as available; do not invent a number |
| `variant_dependent` | Variable product — select a variant first | Fetch `/catalog/product/{id}` variants before reporting |

**Example from a real product:**

```json
{
  "attributes": { "pa_taglia": "s" },
  "stock": {
    "in_stock": true,
    "quantity": 1,
    "confidence": "numeric_stock_quantity",
    "agent_note": "Last unit available. Race condition possible — complete checkout immediately."
  }
}
```

The `agent_note` field carries actionable instructions. Read it.

---

## Reading price correctly

```json
{
  "price": {
    "encoding": "decimal_major_units",
    "current": 120,
    "display": "120,00 €",
    "on_sale": false,
    "vat_included": false
  }
}
```

- `price.current` is always the catalog price. Use this for all commerce reasoning.
- `price.display` is the formatted string for presentation.
- **Never compute a coupon into the price.** Present the catalog price; offer the coupon code as a conditional saving at checkout.
- For variable products: `price.type` is `"range"`. Do not quote a final price until a variant is selected.

---

## Color filtering

The `color` filter accepts canonical English family names:

`red` · `blue` · `green` · `black` · `white` · `grey` · `brown` · `yellow` · `orange` · `pink` · `purple` · `multi`

Italian aliases are also accepted: `viola` = `purple`, `nero` = `black`, `blu` = `blue`, etc.

**Limitation:** Color families are extracted from product names and attributes. Products whose color is encoded only as a style name (e.g. "Ecorce", "Tortora") or a SKU code may return `colors: []` and will not match a color filter. Mitigation strategy:

1. First query with `color=` filter for the most direct results
2. For completeness, also query without `color=` and filter client-side on `name` for color-adjacent terms
3. For products with `colors: []`, the product detail includes `images[].src` — visual color identification from the product image is a valid fallback for a multimodal agent

---

## Shipping reasoning

The discovery document contains a declarative snapshot of the merchant's shipping policy:

```json
{
  "merchant_shipping_policy": {
    "free_shipping_available": true,
    "free_shipping_thresholds": [79],
    "zones": [
      {
        "name": "Italia",
        "methods": [
          { "method_id": "free_shipping", "min_amount": 79 },
          { "method_id": "flat_rate", "cost": 4.90 }
        ]
      }
    ]
  }
}
```

Each product detail also carries a `shipping` field indicating whether the product price alone triggers free shipping:

```json
{
  "shipping": {
    "free_shipping_available": true,
    "free_shipping_eligible_by_product_price": true,
    "free_shipping_thresholds": [79]
  }
}
```

**Rule:** Use this data for agent reasoning. Exact destination-specific cost is WooCommerce checkout authority — do not guarantee a shipping price before checkout.

---

## What NOT to do

### Do not scrape the storefront HTML to build a product catalog

Scraping HTML returns:
- Incomplete stock data — dropdowns may hide unavailable variants, but parsed HTML attributes do not
- No native filtering — you must download all products and filter manually
- Far larger payloads (roughly an order of magnitude more data per task)
- Fragile selectors that break on theme updates

When an ARC discovery signal is present, scraping is always the wrong choice.

### Do not use `wp/v2/product` as a catalog source

The WordPress REST API (`/wp-json/wp/v2/product`) returns product post data, not commerce data. Its `class_list` field reports all defined size attributes regardless of current stock status — a product can show `pa_taglia-s` in `class_list` while size S is actually sold out. This is a known false-positive source. Use `/wp-json/kalicart/v1/catalog/*` instead.

### Do not stack attributes into `q`

```
✗ wrong
?q=bikini+viola+donna+small+meno+di+150+euro
```

The search index matches product names, not natural language queries. Use structured filters.

### Do not report stock for a variable product before checking variants

A `stock.in_stock: true` at the product level means at least one variant is in stock. Size S may be sold out while size L is available. Always fetch `/catalog/product/{id}` and check `variants[]` before confirming a specific size is available.

### Do not invent a shipping price

Shipping cost depends on destination, cart contents, and active coupons. Report the policy ("free over €79") not a guaranteed amount.

---

## Using KaliCart Global (federated index)

When the user has not specified a merchant, search across the federated network first:

```
GET https://global.kalicart.com/v1/global-catalog/search
    ?q=bikini
    &color=purple
    &gender=female
    &price_max=150
    &availability=in_stock
```

Results include `merchant_id` and a `data_access` URL pointing to that merchant's bridge. Use the bridge URL for authoritative verification — never re-scrape the merchant's storefront.

**Global is a discovery and routing layer.** Price, stock, and checkout authority always reside at the merchant.

---

## Well-known paths (RFC 8615)

ARC-compliant stores serve these stable paths for agent bootstrapping — no HTML parsing required:

| Path | Content |
|---|---|
| `/.well-known/kalicart-bridge.json` | KaliCart Bridge discovery pointer |
| `/.well-known/agent-catalog.json` | Alias |
| `/.well-known/ucp.json` | UCP profile (ChatGPT, Copilot, Gemini) |

**Check `.well-known/` before checking `<head>`** — it works even when the agent does not have an HTML parser.

---

## Summary: the correct agent pattern

```
IF domain has /.well-known/kalicart-bridge.json OR <link rel="kalicart-agent">:
    GET /wp-json/kalicart/v1/discovery                   # 1 call, ~3 KB
    GET /wp-json/kalicart/v1/catalog/search?q=...        # 1 call, ~2–5 KB
    GET /wp-json/kalicart/v1/catalog/product/{id}        # 1 call, ~2–3 KB (selected product only)
    TOTAL: ~3 calls, ~10 KB, accurate per-variant stock

ELSE IF /wp-json namespaces include kalicart/v1:
    same as above — the plugin is installed even if .well-known is not configured

ELSE:
    use /wp-json/wp/v2/product with caution (no price/stock filters, truncated responses)
    or scrape HTML as last resort — but check <head> for API signals first
```

---

*This guide covers ARC/1.0 as implemented by KaliCart Bridge ≥ 1.0.101 (current release 1.0.123).*<br>
*Full specification: [bridge.kalicart.com/spec](https://bridge.kalicart.com/spec/)*<br>
*Full documentation: [bridge.kalicart.com/docs](https://bridge.kalicart.com/docs/)*
*Maintainer release handoff: [docs/RELEASE-1.0.118-HANDOFF.md](docs/RELEASE-1.0.118-HANDOFF.md).*
