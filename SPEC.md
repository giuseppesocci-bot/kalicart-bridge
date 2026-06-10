# ARC/1.0 — Agent-Readable Catalog

**Canonical specification:** https://bridge.kalicart.com/spec/
**Version:** ARC/1.0 · Published 2026-06-10 · Status: Stable
**Steward:** Save The Brain (KaliCart) · Spec license: CC BY 4.0
**Reference implementation:** KaliCart Bridge ≥ 1.0.76 for WooCommerce (this plugin, GPLv2)

ARC defines how an e-commerce storefront exposes its catalog as a machine-readable,
read-only REST surface that autonomous AI agents can discover, trust and consume —
no scraping, no authentication, no vendor lock-in.

## Conformance levels

| Level | Name | Requires |
|---|---|---|
| ARC-READ | Readable catalog | Discovery signal + discovery document + `search`/`products`/`product`/`categories` endpoints, unauthenticated |
| ARC-TRUST | Trustworthy catalog | ARC-READ + quality quarantine + explicit consent flags + freshness + honest stock confidence |
| ARC-CHECKOUT | Checkout handoff | ARC-TRUST + optional checkout sessions (payment stays on the merchant storefront) |

## Core contract (summary)

1. **Discovery** — `<link rel="kalicart-agent">` in the storefront head; `/.well-known/agent.json`,
   `/.well-known/agent-catalog`; optional robots.txt and agent sitemap signals.
2. **Discovery document** — single self-describing JSON: `endpoints{}`, honest `capabilities{}`,
   `authentication {required:false}`, `price_format`, `variation_discovery`, boundary truths
   (shipping/return policy) as structured data, `ucp_profile_url`.
3. **Catalog endpoints** — read-only GET JSON: `catalog/search` (filters enumerated by
   `catalog/meta`), `catalog/products` (paginated: `total/page/per_page/total_pages`),
   `catalog/product/{id}`, `catalog/categories`, `catalog/meta`.
4. **Product object** — prices in `decimal_major_units` (declared); `price.current` is always
   the merchant catalog price (coupons never replace it); UCP `availability_status`;
   `stock.confidence` (`numeric_stock_quantity` / `availability_status_only` / `variant_dependent`);
   `variants[]` always an array — `[]` for variable products in lists, full via product detail;
   `quarantine {in_quarantine, score, flags[]}`; `purchase_readiness`; `barcodes[]`; provenance.
5. **Consent** — federated indexing consent published by the merchant in the discovery document
   (robots.txt model): `crawler_policy.allow_global_indexing`, `intent_flags.federated_search_source`.
   Indexers MUST honor the flags and identify with an honest User-Agent.
6. **Checkout sessions (optional)** — `POST checkout/session` returns `cart_url`/`checkout_url`;
   the human pays on the merchant storefront. ARC surfaces never process payments.
7. **UCP interop** — `/.well-known/ucp` with `catalog.search` + `catalog.lookup`;
   `availability_status`, `list_price`, `variants[]` aligned with UCP.
8. **Security** — read-only, `mutations:false`, no API keys on public surfaces, SSRF-guarded
   federated probes, no third-party calls from the merchant server as a side effect of reads.

The full normative text (RFC 2119 keywords, field tables, examples) lives at the canonical URL.
Feedback and implementation reports: https://github.com/giuseppesocci-bot/kalicart-bridge/issues

