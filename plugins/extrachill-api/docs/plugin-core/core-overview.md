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
- `inc/auth/extrachill-link-auth.php` adds `SameSite=None; Secure` to WordPress auth cookies so extrachill.link can make authenticated REST calls.
- Uses `header_register_callback()` to intercept every `Set-Cookie` header before they leave WordPress, ensuring compatibility without touching core auth logic.
