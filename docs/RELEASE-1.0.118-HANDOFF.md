# KaliCart Bridge 1.0.118 — Maintainer handoff

## Release status

- GitHub `main`: commit `1969488655d2f19ab2a2840cf46dcbb4f1561dbc`.
- Public download: `https://bridge.kalicart.com/download/kalicart-bridge-latest.zip` and the versioned 1.0.118 ZIP serve SHA-256 `72b861f67e9d136c694ec1a14c1c27127d3b0d8f5e755faf7e55945e5d1dcac7`.
- WordPress.org: trunk revision 3605556; tag `1.0.118` revision 3605560.

## Product boundary

Bridge exposes a WooCommerce catalog through structured discovery, catalog, UCP and MCP surfaces. Checkout is opt-in and disabled by default. It creates a WooCommerce checkout session and loads its cart, but it does not process payment or bypass WooCommerce checkout.

The product direction is now reliability, security and performance. Do not add a public surface without a demonstrated merchant or agent need and a bounded operational design.

## 1.0.118 security model

- Public catalog, discovery, UCP/OpenAPI, MCP and checkout endpoints share a proxy-safe, per-client and global rate guard. `X-Forwarded-For` is trusted only through the configured allowlist.
- Costs are weighted: expensive catalog views and MCP operations consume more quota than simple reads.
- Checkout validates content type, body size, item shape, quantities and WooCommerce purchasable limits before creating a session.
- `Idempotency-Key` is bounded and concurrent-safe. The same key and payload replays the original public `201`; a changed payload returns `409`; a replay never creates a second session.
- Checkout links have one atomic conversion claim. Reuse does not disclose the original order. Session, claim and idempotency state have expiry, cleanup and uninstall coverage.
- Classic checkout and Checkout Block attribution use WooCommerce APIs and exact cart/order fingerprints. A changed cart is not attributed.
- MCP accepts only the supported protocol shape, rejects malformed/batch requests and does not surface internal exceptions.
- Telemetry is local, bounded and does not record rejected requests, MCP metadata or checkout paths as agent traffic.

## 1.0.118 performance model

- Derived catalog filters are batched and have an explicit candidate ceiling; no path uses unbounded `posts_per_page = -1`.
- Product-price filtering prefilters with WooCommerce's lookup table and verifies final `price.current`, including variable products.
- Compact derived catalog responses use a short, size-bounded cache: 8 entries and 512 KiB total by default.
- Funnel counters and limiter state use atomic compare-and-swap/lock patterns so concurrent requests do not lose increments.

On Project2209, a representative derived filter fell from roughly 0.97 s to 0.24 s over HTTP. The catalog engine measurement was approximately 19 ms cold and 0.04 ms warm. Treat these as regression baselines, not universal promises.

## Verification completed

- PHP lint and Git whitespace checks.
- WordPress Plugin Check: zero errors. Remaining warnings are documented direct/atomic database queries, existing WooCommerce meta/tax query notices and non-distributive Markdown in the source repository.
- Project2209 tests: rate guard, catalog hardening, MCP hardening, checkout transport/idempotency, checkout attribution, classic checkout, Checkout Block, variation handling and failed cart loading.
- Concurrency: eight simultaneous idempotent checkout requests created one session; twelve simultaneous funnel increments retained all twelve.
- The test site was restored after testing: checkout disabled, temporary products/orders removed, no residual session, claim, idempotency or rate-limit state.

## Known non-blocking follow-up

A checkout link opened through top-level cross-site navigation can replace an existing cart. It cannot complete payment or create an order on its own and is visible to the shopper. If real traffic shows this is a problem, require a same-site POST plus nonce confirmation when the existing cart differs.

## Release discipline

For future work, start from a reproduced security, performance, concurrency or compatibility issue. Define a bounded resource model, add a deterministic test, test on a realistic catalog, and release only after the same package is verified on GitHub, Bridge download and WordPress.org.
