# Cross-Site Service Assertions

Cross-site service assertions authorize one configured source runtime to call one exact target REST operation without creating or impersonating a WordPress user. They are separate from cookie, nonce, Application Password, signed-user, and route-forwarding authentication. A verified assertion leaves the current user unchanged, including user `0`.

## Why This Contract Exists

WordPress cookie authentication, REST nonces, Application Passwords, and the `determine_current_user` and `rest_authentication_errors` extension points all resolve user principals. They do not represent a bounded non-user principal. The existing cross-site signed-user headers likewise require a positive user ID. Route forwarding binds an internal hop to a target request, but it also forwards anonymous traffic and therefore is not authority.

The installed persistent object cache implements atomic add with a backend compare-and-set operation. Its drop-in can, however, fall back to request-local memory when that backend is unavailable. Service assertion replay protection therefore uses the stronger primitive already provided by WordPress storage: the target site's options table has a unique `option_name` key, and one `INSERT IGNORE` atomically claims a nonce across workers. No new table, REST route, or user record is introduced.

## Registration

Consumers register source and target grants through separate filters:

- `ec_cross_site_service_assertion_source_grants`
- `ec_cross_site_service_assertion_target_grants`

Each filter returns a list of grants with this generic shape:

```php
array(
	'service_id'     => SERVICE_ID,
	'scope'          => OPERATION_SCOPE,
	'source_site_id' => SOURCE_BLOG_ID,
	'target_site_id' => TARGET_BLOG_ID,
	'target_host'    => TARGET_HOST,
	'method'         => 'POST',
	'route'          => '/namespace/v1/resource/action',
	'active_key_id'  => CURRENT_KEY_ID, // Required only when minting.
	'keys'           => array(
		CURRENT_KEY_ID  => CURRENT_SECRET,
		PREVIOUS_KEY_ID => PREVIOUS_SECRET,
	),
)
```

IDs and scopes are opaque. Secrets must contain at least 32 bytes and should come from deployment configuration rather than source control. Source registration selects `active_key_id`; target registration accepts every listed key. Rotation adds a new key at both sides, switches the source active key, then removes the old target key after the maximum assertion lifetime. Removing a key revokes it immediately. Removing a grant revokes its service, scope, source, and target tuple.

The source and target registrations are deliberately independent because a fresh target HTTP runtime can load a different site-specific plugin set. Both sides must register the same exact operation and key material. Network does not infer the caller from loaded plugins.

## Source API

Use the normal cross-site request helper with an explicit assertion request:

```php
$result = ec_cross_site_rest_request(
	TARGET_SITE_KEY,
	'POST',
	'/namespace/v1/resource/action',
	array(
		'user_id'           => 0,
		'body'              => $body,
		'service_assertion' => array(
			'service_id' => SERVICE_ID,
			'scope'      => OPERATION_SCOPE,
		),
	)
);
```

An assertion request always uses the HTTP transport so the target site's runtime is freshly bootstrapped. `ec_cross_site_build_service_assertion_headers()` is the lower-level minting API when a caller already owns the transport. Minting fails unless the current blog, resolved target blog and host, method, exact resolved route, service ID, and scope match a source grant. Custom request headers cannot override generated assertion fields.

Calls that omit `service_assertion` retain their existing in-process or filtered HTTP behavior. Positive-user signed headers can coexist with service assertions, but service claims never set or replace the WordPress user.

## Canonical Payload

The HMAC-SHA256 input is JSON with keys in this fixed order:

1. `version`
2. `service_id`
3. `key_id`
4. `scope`
5. `source_site_id`
6. `target_site_id`
7. `target_host`
8. `method`
9. `route`
10. `query_digest`
11. `body_digest`
12. `issued_at`
13. `expires_at`
14. `nonce`

JSON uses unescaped slashes. Method and host are normalized to uppercase and lowercase respectively. Query parameters are round-tripped through the transport's `http_build_query()` representation, then associative keys are recursively sorted while list order is retained. Bodies are interpreted as the JSON value transmitted by the helper; associative keys are recursively sorted while list order is retained. Each canonical value is JSON-encoded and SHA-256 hashed. The nonce is 16 cryptographically random bytes encoded as lowercase hexadecimal.

## Target API

Verification runs on `rest_pre_dispatch` at priority `1`, before route permission callbacks. If any assertion header is present, the complete assertion must verify; partial or invalid fields return a generic error rather than falling through as anonymous traffic.

After successful verification, a route owner reads claims with:

```php
$claims = ec_cross_site_verified_service_context( $request );
if ( OPERATION_SCOPE !== ( $claims['scope'] ?? '' ) ) {
	return new WP_Error( 'rest_forbidden', 'Access denied.', array( 'status' => 403 ) );
}
```

The context is held in a `WeakMap` keyed by the exact `WP_REST_Request`; it is not copied into request parameters, globals visible to callers, or the current user. Claims are exposed only after localhost, syntax, version, source grant, target site and host, method, route, query, body, time, key, signature, and replay checks succeed.

## Expiry And Replay

Assertions live for at most 60 seconds. Issued-at values may be at most five seconds in the future to tolerate small clock differences. The target rejects expired, overlong, or inverted windows.

After all other checks pass, the target atomically inserts a replay marker derived from the version, service ID, key ID, source site, target site, and nonce. Exactly one concurrent request can create the marker. A scheduled cleanup removes it after expiry; delayed or failed cleanup can leave harmless non-autoloaded rows but cannot reopen authority. Since expired assertions fail before replay consumption, retained markers only consume storage.

A database write error or unavailable storage returns a generic `503` and no verified context. A duplicate insert returns a generic replay denial. The system does not fall back to request-local cache and therefore does not claim single-use behavior during a shared-cache outage. Operators should monitor failed storage writes and delayed cleanup jobs; availability fails closed while confidentiality and replay safety are preserved.

## Threat Model

The contract rejects assertion-shaped remote traffic, partial headers, invalid signatures, expired assertions, future assertions outside the allowed skew, replay, unregistered services or scopes, removed keys, and copies targeting another site, host, method, route, query, or body. Route forwarding alone provides no service context. Error codes and messages contain no keys, secrets, signatures, assertions, or request data.

Trust is limited to configured code running on the exact registered source site with access to an active secret. A compromised source runtime can exercise only its configured exact grants. Domain-level idempotency remains the target owner's responsibility; each legitimate retry must use a fresh transport assertion.
