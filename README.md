# KaliCart Bridge

**Makes your WooCommerce catalog machine-readable for AI shopping agents.**

No LLM. No cloud. No API key. No external service.

→ [bridge.kalicart.com](https://bridge.kalicart.com) · [Documentation](https://bridge.kalicart.com/docs/)

---

## What it does

AI shopping agents — ChatGPT, Gemini, Claude, and specialized buyers — don't browse your storefront. They query structured APIs. If your WooCommerce store doesn't have one, agents move on.

KaliCart Bridge installs in two minutes and exposes your live catalog as a normalized REST API with everything an agent needs to reason about your products: prices, stock, shipping policy, active coupons, purchase readiness, and UCP-compatible structured fields.

A `<link rel="kalicart-agent">` injected in your site `<head>` tells any agent where to start. From there, a discovery document explains exactly how to search your catalog, what filters are available, and how to interpret the data.

---

## Endpoints

| Endpoint | Description |
|---|---|
| `GET /wp-json/kalicart/v1/discovery` | Entry point — capabilities, filters, policy, UCP profile |
| `GET /wp-json/kalicart/v1/catalog/search` | Search with filters: q, category, gender, color, on_sale, in_stock, min_price, max_price |
| `GET /wp-json/kalicart/v1/catalog/products` | Paginated product list |
| `GET /wp-json/kalicart/v1/catalog/product/{id}` | Single product with full variations[] |
| `GET /wp-json/kalicart/v1/catalog/categories` | Category tree with search_url_template per node |
| `GET /wp-json/kalicart/v1/catalog/meta` | Accepted filters and price range |
| `GET /wp-json/kalicart/v1/catalog/health` | Catalog health dashboard (requires manage_woocommerce) |
| `POST /wp-json/kalicart/v1/checkout/session` | Create checkout session (optional, off by default) |
| `GET /.well-known/kalicart-bridge` | Bridge discovery file |
| `GET /.well-known/ucp` | UCP profile (catalog.search + catalog.lookup) |

---

## What agents get per product

```json
{
  "id": 42,
  "name": "Classic Oxford Shirt",
  "price": {
    "current": 89.00,
    "regular": 110.00,
    "on_sale": true,
    "discount_pct": 19.1,
    "currency": "EUR",
    "encoding": "decimal_major_units",
    "display": "89,00 €",
    "vat_included": true,
    "price_type": "STATIC"
  },
  "stock": {
    "in_stock": true,
    "availability_status": "in_stock",
    "quantity": 14,
    "quantity_tracked": true,
    "backorder_allowed": false
  },
  "variants": [],
  "barcodes": [{ "type": "EAN", "value": "1234567890123" }],
  "list_price": 110.00,
  "metadata": {
    "purchase_readiness": "direct_cart_possible",
    "stock_confidence": "numeric_stock_quantity",
    "bridge_version": "1.0.66"
  }
}
```

---

## Agent discovery signals

- `<link rel="kalicart-agent">` in `<head>`
- `/.well-known/kalicart-bridge` and `/.well-known/ucp` discovery files
- `Allow: /wp-json/kalicart/` in `robots.txt`
- `/kalicart-sitemap.xml` linked from the WP sitemap index
- Structured links injected on search, zero-results, category, and product pages

---

## Normalization engine

- **Price** — regular, sale, current, discount %, currency, variable product min/max ranges
- **Stock** — status, quantity (if managed), backorder policy, UCP-standard availability_status
- **Gender** — inferred from `pa_gender`, category paths, tags, product name (IT/EN/FR/DE/ES keywords)
- **Color** — mapped to 13 color families via `pa_color`/`pa_colore` and product metadata
- **Size** — detected from `pa_size`/`pa_taglia`, type auto-detected (clothing S/M/L, numeric EU, shoes EU half-sizes)
- **Shipping** — zones with methods, costs, locations, free shipping threshold
- **Coupons** — only coupons with computable product value exposed; `applicable_at` field

---

## Catalog health dashboard

Products scored 0–100. Deductions: NO_TITLE (−25), NO_DESCRIPTION (−30), NO_CATEGORY (−30), ZERO_PRICE (−25), NO_IMAGE (−8), NO_SKU (−4). Products with blocking issues are quarantined and excluded from agent responses.

---

## Checkout sessions (optional)

When enabled, agents can create multi-product checkout sessions returning `cart_url` and `checkout_url`. Sessions expire after 30 minutes. No OAuth, no PII, no payment on the agent side.

---

## UCP compatibility

- `/.well-known/ucp` declares `dev.ucp.shopping.catalog.search` and `dev.ucp.shopping.catalog.lookup`
- `stock.availability_status` uses UCP-standard values
- `variants[]` always present on every product
- `list_price` for UCP-compatible strikethrough pricing
- `barcodes[]` (EAN, GTIN, UPC)
- `price.encoding: decimal_major_units`

---

## Installation

1. Download the latest ZIP from [bridge.kalicart.com](https://bridge.kalicart.com)
2. Upload to `/wp-content/plugins/` or install via **WP Admin → Plugins → Add New → Upload**
3. Activate
4. Navigate to **KaliCart** in the admin menu
5. Your catalog is live at `yourdomain.com/wp-json/kalicart/v1/discovery`

**Requirements:** WordPress 6.0+, WooCommerce 7.0+, PHP 8.0+

---

## Privacy

Runs entirely on your server. No data sent to KaliCart or any third party. No account required. No API key.

---

## License

GPLv2 or later — see [LICENSE](LICENSE)
