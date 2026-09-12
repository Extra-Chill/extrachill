# extrachill-mcp

MCP server for the Extra Chill platform. Exposes the WordPress ability surface to external AI clients — ChatGPT, Gemini, Claude — scoped to the permissions of the connected account.

**Status:** transport complete, authentication pending. See "Authentication" below.

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

`src/Access.php` widens the two meta-abilities from their upstream `manage_options` default to the Extra Chill team tier (`access_roadie`), filterable via `extrachill_mcp_capability`. That gate governs **reachability of the two meta-tools**, not what they may do.

Note what widening it does expose: a caller who can reach `agents/ability-search` can enumerate every registered ability name and JSON schema on the network, whether or not any of them are executable for that caller. Opening this below team tier is a deliberate product decision about information disclosure.

## Authentication

**Not yet implemented — the plugin is not usable by external clients until it is.**

Per-user connection requires OAuth 2.1: ChatGPT rejects bearer tokens outright, and Claude's static-header mode is an organization-shared credential rather than a per-user one. Without it there is no "connected account" for authorization to scope to.

Until that lands, the endpoint is reachable only by an already-authenticated WordPress session with the required capability.

## Requirements

- WordPress 6.9+ (Abilities API in core)
- Agents API plugin — provides `agents/ability-search` and `agents/ability-call`
- PHP 8.1+
- `wordpress/mcp-adapter` ^0.6.1 (Composer)

## Notes

The adapter ships a default server at `/wp-json/mcp/mcp-adapter-default-server` carrying generic `discover-abilities` / `get-ability-info` / `execute-ability` tools, exposed by a static `meta.mcp.public` flag rather than by caller identity. This plugin suppresses it — Extra Chill's surface is mediated through one endpoint with one access policy.

## Filters

| Filter | Purpose |
| --- | --- |
| `extrachill_mcp_capability` | Capability required to reach the meta-tools. Default `access_roadie`. |
| `extrachill_mcp_tools` | Ability names advertised as MCP tools. Default: search + call. |
