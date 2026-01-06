# ExtraChill API Plugin Core

## Singleton Bootstrap
- `ExtraChill_API_Plugin::get_instance()` loads the plugin once and keeps all REST routes centralized.
- Constructor loads every PHP file within `inc/routes/` via `RecursiveIteratorIterator` so each endpoint can self-register.
- Hooks:
  - `plugins_loaded` → `boot()` includes cross-domain auth helper and fires `extrachill_api_bootstrap` for integrators.
  - `rest_api_init` → `register_routes()` fires `extrachill_api_register_routes`, giving every route file a single action to latch onto.

## Autoloading
- Composer autoloader is loaded if `vendor/autoload.php` exists to expose third-party libraries (Endroid QR Code).
- Constants `EXTRACHILL_API_PATH` and `EXTRACHILL_API_URL` allow other modules to resolve assets relative to the plugin location.

## Cross-Domain Auth Helper
- `inc/auth/extrachill-link-auth.php` appends `SameSite=None; Secure` to WordPress auth cookies so `extrachill.link` can make authenticated REST calls.
- Uses `header_register_callback()` to intercept outgoing `Set-Cookie` headers and patch WordPress auth cookies without changing core auth logic.

## Shop Operations
Centralized endpoints for the artist marketplace on `shop.extrachill.com`.
- `products`: CRUD operations for artist merch.
- `orders`: Artist-scoped order management and fulfillment.
- `stripe-connect`: Onboarding and status for marketplace payouts using the **Separate Charges and Transfers** pattern.
- `shipping-labels`: USPS label purchase via Shippo ($5.00 flat rate domestic).
- `shipping-address`: Artist fulfillment address management stored on `artist_profile`.
- `stripe-webhook`: Platform-level processing for Stripe payout and account events.
- `seo`: Multisite SEO audit endpoints (`/seo/audit`, `/seo/status`, `/seo/continue`).
- `admin`: Administrative lists and management (`/admin/list`, `/admin/team-members`, `/admin/lifetime-membership`).
- `tools`: QR code generation and markdown exports (`/tools/qr-code`, `/tools/export-markdown`).
