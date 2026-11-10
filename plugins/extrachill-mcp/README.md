# extrachill-mcp

MCP server for the Extra Chill platform. Exposes the WordPress ability surface to external AI clients — ChatGPT, Gemini, Claude — scoped to the permissions of the connected account.

**Status:** live. Transport and per-user OAuth 2.1 authentication are both shipped. See "Authentication" below.

## What this plugin is

A transport adapter. It owns no tools, no domain logic, and no data access of its own.

Extra Chill registers abilities across its feature plugins — editorial, artists, events, venues and bookings, community, shop, newsletter. The Agents API substrate already provides two canonical meta-abilities over that registry:

```
agents/ability-search    Search registered abilities by name, category, keywords
agents/ability-call      Invoke a registered ability by name with JSON parameters
```

This plugin advertises exactly those two as MCP tools and stops there.

```
/wp-json/extrachill-mcp/v1/mcp
```

Two tools, regardless of how many abilities exist. Adding a capability to the platform means registering an ability in the plugin that owns that domain — this server needs no change to expose it.

## Why only two tools

Flat tool lists do not survive contact with a real ability registry. Extra Chill has several hundred registered abilities; advertising them individually would blow out the context window of every client that connected, and would grow worse with each feature shipped.

Search-then-call keeps the advertised surface constant. It is the same shape as `mcp-context-wporg`'s `load-provider` / `execute-tool`, except the registry being searched is the WordPress Abilities API rather than a hand-maintained provider catalog.

## Authorization

**Exposure is not authorization.** The two gates are independent and it matters that they stay that way.

`WP_Ability::execute()` calls `check_permissions( $input )` on the target ability before running it, in WordPress core. Every route to an ability — REST, WP-CLI, MCP, `agents/ability-call` — passes through that check. `agents/ability-call` therefore cannot reach anything the caller could not already reach by other means.

This holds for input-dependent authorization too. Venue booking abilities resolve `booking_id` to a venue, then check that venue's grant for the requested action, per call. A connected account sees its own venues and nothing else, with no MCP-specific policy involved.

`src/Access.php` gates **reachability of the two meta-tools**, not what they may do. The decision, in `Access::is_authorized()`:

1. **A signed-in account is required.** Always. Every MCP request carries a bearer token from the OAuth flow below, so "public" means "any account", never "anyone".
2. **The rollout tier.** The `extrachill_mcp` feature is registered with extrachill-users' `ec_feature_available()` ladder (`admin` → `team` → `public`) at a `public` ceiling. The live tier is the network option `ec_feature_tier_extrachill_mcp`, clamped to the ceiling. While that option is unset it defaults to `team`, so deploying the raised ceiling changes nothing; opening the MCP is an explicit operator step:

   ```sh
   # read the current value (unset prints bool(false); the tier then resolves to team)
   wp --url=https://auth.extrachill.com eval 'var_dump( get_site_option( "ec_feature_tier_extrachill_mcp" ), ec_feature_tier( "extrachill_mcp" ) );'
   # open the MCP to every signed-in account
   wp site option update ec_feature_tier_extrachill_mcp public
   ```

3. **The entitlement seam.** `extrachill_mcp_is_authorized` filters the tier's decision per account (`bool $authorized, WP_User $user`). Return `false` to require an entitlement such as a paid plan; return `true` to admit a named account during a narrower rollout. Only a strict `true` admits, and the filter never runs for a signed-out caller.

Without extrachill-users the gate falls back to the `access_studio` capability (the team tier).

The local-site hop runs the substrate's `agents/ability-call`, so `Access` also widens `agents_ability_call_permission` to the same audience, for exposed abilities only (`Abilities\Exposure`). It does **not** widen `agents_ability_search_permission`: `extrachill/ability-search` reads the registry directly through `Exposure`, while the substrate search lists hidden server-side primitives too.

Opening the tier exposes the names and schemas of every **exposed** ability (`show_in_rest` or `mcp.public`) to every account. Execution is still the target ability's own `permission_callback`, run as that account. An ability that should not be callable by any signed-in account must say so in its own permission check, and should not set `show_in_rest` if it is not meant for external callers at all.

## Authentication

Per-user connection runs on OAuth 2.1, served by [`wp-native-auth`](https://github.com/Extra-Chill/wp-native/tree/main/plugins/wp-native-auth). Per-user is the requirement, not a preference: ChatGPT rejects bearer tokens outright, and Claude's static-header mode is an organization-shared credential rather than a per-user one. Neither yields a "connected account" for authorization to scope to.

| | |
| --- | --- |
| Endpoint | `https://auth.extrachill.com/wp-json/extrachill-mcp/v1/mcp` |
| Transport | streamable HTTP |
| Grant | authorization code with PKCE (S256) |
| Client registration | Client ID Metadata Documents, or Dynamic Client Registration (RFC 7591) |
| Scope | `account` |
| Client authentication | none — public clients only |

Command-line clients take the endpoint directly, for example:

```sh
claude mcp add --transport http extrachill https://auth.extrachill.com/wp-json/extrachill-mcp/v1/mcp
```

then run `/mcp` inside Claude Code and choose the server to sign in through the browser. End-user setup steps for the app connectors live in `plugins/extrachill-docs/docs/user/connect.md`.

A spec-compliant client needs the endpoint and nothing else. The `401` carries the RFC 9728 `WWW-Authenticate` challenge, which points at `/.well-known/oauth-protected-resource`, which names the authorization server. Discovery walks itself from there.

That challenge is emitted only when `wp-native-auth` is active — `src/Server/McpServer.php` guards on `wp_native_auth_oauth_protected_resource_url()`. Without it the endpoint still returns `401`, but bare, and a client has to guess where to authorize.

### Headless clients

Authorization ends in a redirect to the client's `redirect_uri`, which for a CLI client means a loopback listener. If the client runs somewhere without a browser — a VPS, a container, a CI runner, an agent sandbox — the browser resolves `127.0.0.1` to the operator's machine rather than the client's, and the authorization code never arrives.

The flow is correct on both ends; the two halves are just on different hosts. Bridge it with an SSH tunnel to the client's callback port, or copy the failed redirect URL from the browser back to the client host. The durable fix is a device grant, tracked in [Extra-Chill/wp-native#91](https://github.com/Extra-Chill/wp-native/issues/91).

## Requirements

- WordPress 6.9+ (Abilities API in core)
- Agents API plugin — provides `agents/ability-search` and `agents/ability-call`
- `wp-native-auth` — OAuth 2.1 authorization server and the RFC 9728 challenge
- PHP 8.1+
- `wordpress/mcp-adapter` ^0.6.1 (Composer)

## Notes

The adapter ships a default server at `/wp-json/mcp/mcp-adapter-default-server` carrying generic `discover-abilities` / `get-ability-info` / `execute-ability` tools, exposed by a static `meta.mcp.public` flag rather than by caller identity. This plugin suppresses it — Extra Chill's surface is mediated through one endpoint with one access policy.

## Filters

| Filter | Purpose |
| --- | --- |
| `extrachill_mcp_is_authorized` | Per-account entitlement seam after the rollout tier. Receives `bool $authorized, WP_User $user`; only a strict `true` admits. Never runs for signed-out callers. |
| `ec_feature_ceilings` | extrachill-users registry; this plugin registers `extrachill_mcp` at `public`. |
| `extrachill_mcp_tools` | Ability names advertised as MCP tools. Default: search + call. |
