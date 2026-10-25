# Bridge shipping zones 15–16 — isolated candidate

2026-10-25. Branch `chatgpt/bridge-shipping-15-16`, based on 1.0.149. No installation or release.

The merchant policy and each shippable product now project from the same WooCommerce zone order; zone 0 remains last. They retain zones with no built-in shipping method and expose `unexported_methods` (enabled raw Woo rows absent from the represented built-in methods), `zones_complete`, explicit `rest_of_world`, and typed locations. Flat-rate expressions remain strings; nonempty class costs and the Woo calculation type are retained. Existing fields remain.

Static checks: PHP syntax clean; `git diff --check` clean. No merchant traffic, DB writes, deployment, or mass sync. A live WordPress runtime fixture and contract consumer review remain necessary before release.
