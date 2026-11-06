# Internal loopback user authentication

Cross-site REST calls run in-process when the target route or ability is loaded on the current site. When it is not, `ec_cross_site_rest_request()` falls back to an HTTP loopback request against the target site, so the target can bootstrap its own plugins. That fallback carries the calling user as a signed assertion in `X-EC-Internal-*` headers.

## Assertion format

| Header | Value |
|---|---|
| `X-EC-Internal-User` | User ID being asserted |
| `X-EC-Internal-Timestamp` | Unix time the assertion was minted |
| `X-EC-Internal-Nonce` | 128-bit random hex nonce |
| `X-EC-Internal-Signature` | `HMAC-SHA256( "ec-internal-v2:<user>:<timestamp>:<nonce>", key )` |

The key is derived for this purpose only: `HMAC-SHA256( "ec-cross-site-internal-v2", material )`. The material is `EC_CROSS_SITE_INTERNAL_SECRET` when that constant is defined in the shared `wp-config.php`, otherwise `wp_salt( 'auth' )`. The salt is never used directly as an HMAC key. Every PHP worker on the network must see the same configuration.

## Verification

`ec_cross_site_authenticate_internal_request()` (on `rest_authentication_errors`) accepts an assertion only when all of the following hold:

1. The request comes from localhost. This is a supplemental restriction, not the authority.
2. The nonce is well-formed and the signature matches.
3. The timestamp is within five minutes of now, in either direction.
4. The user exists.
5. The assertion is consumed atomically, as a unique `_ec_internal_replay_<sha256>` row in the main site's options table. A second use of the same assertion, or any storage failure, is denied.

Expired replay markers are removed in bounded batches of 100. `ec_cross_site_verify_signature()` stays read-only, so downstream permission checks (for example the booking attachment download route) can re-verify the same request without consuming it again.

Assertions without a nonce (the pre-v2 format) are rejected, so senders and verifiers must be deployed together. They ship in the same plugin.

## Known limit

An assertion authorizes a user, not one exact method, path and body. Binding the full request would require coordinated changes to the API's multipart booking forwarder and to the attachment download signature check.

## Tests

`php tests/internal-loopback-auth-smoke.php`, run by `composer test`.
