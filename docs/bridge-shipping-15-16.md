# Bridge shipping zones 15–16 — isolated candidate

2026-10-25. Branch `chatgpt/bridge-shipping-15-16`, based on 1.0.149. No installation or release.

The merchant policy and each shippable product now project from the same WooCommerce zone order; zone 0 remains last. They retain zones with no built-in shipping method and expose `unexported_methods` (enabled raw Woo rows absent from the represented built-in methods), `zones_complete`, explicit `rest_of_world`, and typed locations. Flat-rate expressions remain strings; nonempty class costs and the Woo calculation type are retained. Existing fields remain.

Checks: PHP syntax and diff clean; one isolated Woo method fixture passed 9/9 projections. No merchant traffic, DB writes, deployment, or mass sync. A full WordPress runtime and surface check remain before release.
Release ordering: deploy the backward-compatible Global reader before Bridge. This isolated candidate increments KALICART_BRIDGE_CATALOG_DATA_VERSION from 3 to 4 so existing product shipping blocks refresh at each merchant's normal full-sync turn after release. Do not force a network-wide simultaneous read.
