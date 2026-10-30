# Cross-Site Routing

The Extra Chill network is one WordPress multisite install with ten active sites (see the table below). Any REST route or ability registered on one site can, in principle, be reached from any other — but "reached" means five different things depending on what's being resolved and what already knows where to send it. This document is the map between them.

## The site-key vocabulary

Every mechanism below speaks the same site keys, defined once in `inc/core/blog-ids.php`:

| Site key | Domain | Blog ID |
|---|---|---|
| `main` | extrachill.com | 1 |
| `community` | community.extrachill.com | 2 |
| `shop` | shop.extrachill.com | 3 |
| `artist` | artist.extrachill.com | 4 |
| `events` | events.extrachill.com | 7 |
| `newsletter` | newsletter.extrachill.com | 9 |
| `docs` | docs.extrachill.com | 10 |
| `wire` | wire.extrachill.com | 11 |
| `studio` | studio.extrachill.com | 12 |
| `link_pages` | extrachill.link | 13 |

See `docs/blog-id-helpers.md` for the full helper reference (`ec_get_blog_id()`, `ec_get_site_url()`, `ec_get_blog_slug_by_id()`). One additional helper matters specifically for routing: `ec_get_all_site_ids()` returns every active blog ID from `get_sites()` dynamically, not just the ones with a known site key — useful for "touch every site" operations, but note that a site without an entry in `ec_get_blog_ids()` has no site key to route to or attribute ownership to.

## The five mechanisms

| Mechanism | Answers | Input | Cost |
|---|---|---|---|
| `ec_get_route_site_affinity()` | Which site owns this REST route? | A route path prefix | In-memory, hand-maintained map |
| `ec_get_ability_site_affinity()` / `ec_get_network_abilities()` | Which site owns this ability? | An ability name | Cached network-wide index, built by touching every site once per day |
| `ExtraChillNetwork\Editor\BlogResolver::resolve()` | Which blog does *this specific ability call* target? | Caller-supplied `blog_id` in ability input | Free — trusts the caller |
| `ec_cross_site_rest_request()` | How do I actually get there? | A resolved site key | In-process (cheap) or HTTP loopback (an extra FPM worker) |
| The site-key registry (`ec_get_blog_ids()`, `ec_get_site_url()`, `ec_get_all_site_ids()`, `ec_get_blog_slug_by_id()`) | What blog ID / URL / slug corresponds to a site key? | A site key or blog ID | Free — static map or one `get_sites()` call |

The first two answer **"where"**; `BlogResolver` answers **"where, because I was told"**; the dispatcher answers **"how"**; the registry is the shared vocabulary all four others speak. A consumer resolving affinity always ends by calling the dispatcher — affinity resolution and dispatch are deliberately separate concerns.

### 1. Route affinity — `ec_get_route_site_affinity()`

```php
ec_get_route_site_affinity( '/extrachill/v1/events/upcoming' ); // => 'events'
```

Route prefixes partition cleanly by site (every `extrachill/v1/events/*` route belongs to the events site), so this is a small, hand-maintained map filtered through `ec_route_site_affinity_map`. `extrachill-api`'s route-affinity middleware (`inc/middleware/route-affinity.php`) is the consumer: it runs on `rest_pre_dispatch`, checks the current route against the map, and forwards to the owning site when the current site doesn't match. See "Registering new affinity" below for how a consumer adds a prefix.

Use this when you're routing an **incoming REST request** by its path and the route family is entirely owned by one site.

### 2. Ability affinity — `ec_get_ability_site_affinity()` / `ec_get_network_abilities()`

```php
ec_get_ability_site_affinity( 'extrachill/add-venue' ); // => 'events'
ec_get_ability_site_affinity( 'extrachill/get-user-profile' ); // => null (local, or ambiguous)
```

Ability names do not partition by prefix the way routes do — measured on the live network, 129 `extrachill/` abilities are registered on both `main` and `events` while 120 are `events`-only, inside the *same* namespace. A static map would rot silently. Instead this is a real index, built by asking every other site what it has registered and cached network-wide. Full mechanics, including why the build requires touching every site's own PHP bootstrap and what that costs, are documented in `inc/core/ability-site-affinity.php`.

Three outcomes, all explicit rather than guessed:

- **Registered locally** → `null`. No affinity needed; call the ability directly.
- **Registered on exactly one other (known) site** → that site's key.
- **Registered nowhere in the index, or on more than one site with no override** → `null`. An ambiguous or unknown ability is not a guess this primitive makes for you — see "Registering new affinity" for how to resolve a genuine multi-owner case.

Use this when a consumer needs to invoke an ability by name and doesn't already know which site owns it — the case `BlogResolver` explicitly does not cover (below).

### 3. Explicit target — `BlogResolver::resolve()`

```php
$blog_id = \ExtraChillNetwork\Editor\BlogResolver::resolve( $input, $default_blog_id );
```

Every editor ability across the network accepts an optional `blog_id` in its input. `BlogResolver::resolve()` reads it, falling back to a caller-supplied default or the current blog. This is **not discovery** — it only works when the caller already knows the target, e.g. an editor UI operating on a post it already knows lives on `community`. It answers a narrower question than ability affinity: "where does *this specific call* go," not "where does *this ability generally live*." Ability affinity exists precisely because most cross-site consumers (the network-wide MCP endpoint that motivated this document, for one) don't have that input to read.

### 4. The dispatcher — `ec_cross_site_rest_request()`

```php
ec_cross_site_rest_request( 'events', 'POST', '/wp-abilities/v1/abilities/extrachill/add-venue/run', array( 'body' => $params ) );
```

Once a site key is resolved (by any of the three mechanisms above, or known ahead of time), this is the only way to actually get there. It never resolves affinity itself — it only executes. See "Dispatch: in-process vs. HTTP loopback" and "Identity preservation" below for how it works and why both paths preserve the calling user.

### 5. The site-key registry

`ec_get_blog_ids()`, `ec_get_site_url()`, `ec_get_blog_slug_by_id()`, and `ec_get_all_site_ids()` are the vocabulary every other mechanism speaks — a site key in, a blog ID/URL/slug out (or the reverse). See `docs/blog-id-helpers.md` for the complete reference. The one routing-specific note: `ec_get_all_site_ids()` is the only one of these that reflects sites *without* a known site key (e.g. immediately after a new site is created but before a deploy adds its slug to `ec_get_blog_ids()`) — every other helper, and both affinity mechanisms, silently skip a site they can't name.

## Dispatch: in-process vs. HTTP loopback

`ec_cross_site_rest_request()` has two transports:

- **In-process (default).** `switch_to_blog( $target )` then `rest_do_request()`. Zero HTTP overhead, no extra FPM worker. Correct for any route or ability whose handler depends only on **network-activated** plugins and blog-scoped options/queries — which is most of `extrachill/v1`.
- **HTTP loopback.** `wp_remote_request()` to `https://127.0.0.1` with the target's `Host` header, which spins up a fresh PHP-FPM worker that bootstraps the target site's **entire** plugin stack independently.

The reason both exist: `switch_to_blog()` changes the database/options context but does **not** load a target site's per-site-activated plugins into the current PHP process — those plugins' `init`/`rest_api_init` hooks already ran (or didn't) once, when this process first bootstrapped. A route or ability registered only by a per-site plugin (e.g. `extrachill-events`, active only on `events`) is simply not defined in any other site's process, no matter how many times you `switch_to_blog()` into it.

Loopback is selected:

- **Automatically**, when the in-process attempt returns `rest_no_route` (the target route genuinely doesn't exist in this process — a missing route is loud, so the automatic fallback is safe).
- **Automatically**, for the `/wp-abilities/v1/abilities/{name}/run` shape specifically, when `wp_has_ability()` is false locally — this avoids the abilities registry logging a "not found" notice before falling through to the correct 404, and skips straight to the site that actually has it.
- **Explicitly**, via the `ec_cross_site_use_http_loopback` filter, whenever a consumer knows a route or ability's handler needs the target's own bootstrap but the request would otherwise succeed silently with the *wrong* (incomplete, in-process) result rather than 404. **This is the case ability-index building hits on every remote site**: `GET /wp-abilities/v1/abilities` is a core route that exists in every process, so an in-process call would return 200 with an incomplete list instead of `rest_no_route` — there's no automatic signal to fall back on. `ec_fetch_remote_ability_names()` in `inc/core/ability-site-affinity.php` forces the filter for exactly this reason, following the same pattern as `extrachill-api`'s route-affinity middleware forwarding `/events/*` routes and `NetworkStats\Providers\CommunityStatsProvider`'s community-stats delegation.

Loopback costs an extra FPM worker per call (see issue #11) — the deciding question for a consumer choosing whether to force it is always "does this handler's *correctness*, not just its existence, depend on the target's own bootstrap."

## Identity preservation

Both transports exist specifically so that **per-user permission scoping keeps working across a cross-site hop** — a request forwarded from `community` to `events` still runs as the same user, with the same capabilities, on the target site.

- **In-process**: `ec_cross_site_rest_request_in_process()` captures `get_current_user_id()` *before* calling `switch_to_blog()`, calls `wp_set_current_user()` for the desired user inside the target blog context (capability checks need the user record loaded against the target site's roles), dispatches, then restores the original user *and* blog in a `finally` block — regardless of whether the dispatched request errored.
- **HTTP loopback**: there is no shared PHP state to carry across a real HTTP hop, so the caller instead signs an `X-EC-Internal-User` header (plus timestamp and HMAC signature, using the network-shared `AUTH_SALT`) via `ec_cross_site_build_auth_headers()`. The target verifies the signature and localhost origin in `ec_cross_site_authenticate_internal_request()` (hooked on `rest_authentication_errors`) and calls `wp_set_current_user()` if valid.

Either way, the target site's permission callbacks see the real calling user, not an anonymous or service identity — which is what makes it safe for a route or ability to apply its normal `current_user_can()` checks regardless of which site the request originated on. (Service assertions, documented separately in `docs/service-assertions.md`, are the deliberate exception: they authorize one exact machine-to-machine operation *without* a user identity, for cases where impersonating a user would be wrong.)

The ability-affinity index build is the one system-level exception to "forward the calling user": listing another site's abilities requires `current_user_can( 'read' )` (a core requirement, not one this plugin adds), but index building runs in contexts with no real calling user to forward — a cron-triggered rebuild, or a cache miss on an anonymous page view. It authenticates as a network super admin instead (see `ec_ability_affinity_system_user_id()`), which is read-only, non-sensitive, and grants `current_user_can()` everywhere on the network via `is_super_admin()` regardless of blog membership — the same idiom `extrachill-artist-platform` already uses for its own internal provisioning calls.

## Registering new affinity

### Routes

Add a prefix to the `ec_route_site_affinity_map` filter:

```php
function my_plugin_add_route_affinity( $affinity_map ) {
	$affinity_map['/extrachill/v1/my-feature/'] = 'my-site-key';
	return $affinity_map;
}
add_filter( 'ec_route_site_affinity_map', 'my_plugin_add_route_affinity' );
```

`extrachill-api`'s `inc/middleware/route-affinity.php` is the worked example — it registers several API-owned route families this way (`extrachill_api_add_artist_route_affinity()`), then hooks `rest_pre_dispatch` to forward requests to the resolved site when the current site doesn't match.

### Abilities

Ability ownership is **discovered automatically** — there's no map to hand-maintain. The only thing a consumer registers is an override, for the two cases automatic discovery can't resolve on its own: an ability genuinely registered on more than one site that needs a definitive answer anyway, or forcing a specific owner ahead of the next cache rebuild.

```php
function my_plugin_ability_affinity_overrides( $overrides ) {
	// extrachill/get-user-profile exists on several sites; prefer artist
	// as the canonical owner for this consumer's purposes.
	$overrides['extrachill/get-user-profile'] = 'artist';
	return $overrides;
}
add_filter( 'ec_ability_site_affinity_overrides', 'my_plugin_ability_affinity_overrides' );
```

Overrides are applied on every read of `ec_get_network_abilities()`, not baked into the cached index — changing the filter takes effect immediately, with no cache flush required. Once a site key is resolved (automatically or by override), dispatch it the same way as any other cross-site call:

```php
$site_key = ec_get_ability_site_affinity( 'extrachill/add-venue' );
if ( $site_key ) {
	$result = ec_cross_site_rest_request(
		$site_key,
		'POST',
		'/wp-abilities/v1/abilities/extrachill/add-venue/run',
		array( 'body' => $params )
	);
}
```

The index itself never needs manual registration when a new ability is added to a per-site plugin — the next cache rebuild (daily, or immediately on that plugin's activation/deactivation or a site add/remove) picks it up. See `inc/core/ability-site-affinity.php` for the full cache invalidation contract.
