# Extra Chill MCP abilities

Extra Chill MCP registers two meta-abilities, both in the `extrachill-mcp` category (registered by `src/Abilities/CategoryRegistration.php`). They are the only two tools the MCP server at `/wp-json/extrachill-mcp/v1/mcp` advertises by default (`McpServer::TOOLS`, filterable with `extrachill_mcp_tools`).

Both wrap the Agents API substrate's local-only `agents/ability-search` and `agents/ability-call` with network-wide discovery and dispatch across Extra Chill sites. The plugin's own domain logic is limited to routing.

Both abilities use the same permission callback, `Access::permission_callback()` (`src/Access.php`), which delegates to `Access::is_authorized()`:

1. A signed-in user is required.
2. The `extrachill_mcp` rollout tier must allow that user, through `ec_feature_available()` (admin → team → public). The fallback is the `access_studio` capability when Extra Chill Users is not loaded.
3. The result then passes through the `extrachill_mcp_is_authorized` filter. Only a strict `true` admits.

This gate controls whether a user can reach the meta-tools at all. It does not authorize the target ability: every target ability still runs its own `permission_callback` as the connected user.

See the [README](../README.md) for the authorization model and OAuth setup.

### `extrachill/ability-search`

- **Purpose:** Searches the abilities exposed on every Extra Chill network site and returns one ranked, deduplicated list. Each result says which sites expose the ability.
- **Input:**
  - `query` (string, optional, default `""`):
    - plain terms match any word in the name, label, description or category;
    - `+term` marks a required term;
    - `select:a/b,c/d` picks exact ability names.
  - `category` (string, optional): exact category filter.
  - `limit` (integer, optional, default 10, range 1–50).
- **Output:** `{ query, count, abilities, sites_queried, errors }`.
  - Each entry in `abilities` is `{ name, summary, required_fields, site, sites }`.
  - The top 3 results, or every `select:` result, also carry a compact `input_schema`.
  - `errors` maps a site key to a message for each site that could not be queried.
- **How it searches:**
  - **Local site:** reads the in-process registry. Only abilities with `show_in_rest: true` or `meta.mcp.public: true` are included (`Abilities\Exposure`).
  - **Other sites:** each remote site is listed through core `GET /wp-abilities/v1/abilities` over a forced HTTP loopback (`NetworkDispatch::list_abilities()`).
  - **Ranking:** a name match outscores a label match, which outscores a description match. Abilities present on every site are down-weighted, and an exact name match wins.
  - Appearing in the results is discovery only. It does not mean the caller can run the ability.
- **Permission:** `Access::permission_callback()` (see above).
- **Exposure:** `show_in_rest` true (annotations: readonly, idempotent). MCP tool on the Extra Chill MCP server. No `extrachill-api` route or `wp extrachill` command found.
- **Side effects:** cross-site HTTP loopback requests to every other network site. It records nothing.

### `extrachill/ability-call`

- **Purpose:** Runs a registered ability by name on whichever network site owns it, passing JSON parameters.
- **Input:**
  - `name` (string, required): the ability to run, for example `extrachill/add-venue`.
  - `site` (string, optional): network site key, for example `main`. Required when the ability is registered on more than one site.
  - `parameters` (object, optional, default `{}`): passed to the target ability.
- **Output:** `{ name, site, result }`.
  - On a local dispatch, it is the substrate `agents/ability-call` response plus `site`. That response also has `parameters` (with sensitive values redacted) and `parameters_redacted`.
  - On a remote dispatch, it is `{ name, site, result }` only. Parameters are left out, because no local schema is available to redact them.
- **Errors:**
  - `extrachill_mcp_ability_call_missing_name`.
  - `extrachill_mcp_ability_call_recursion`: the ability tried to call itself.
  - `extrachill_mcp_ability_call_ambiguous_owner`: registered on several sites with no `site` given. Includes `candidate_sites`.
  - `extrachill_mcp_ability_call_unknown_site`.
  - `extrachill_mcp_ability_call_site_mismatch`: the explicit `site` does not register the ability.
  - `extrachill_mcp_network_unavailable`.
  - `extrachill_mcp_ability_not_exposed` (403): the local target is neither `show_in_rest` nor `mcp.public`.
  - Remote errors keep the upstream code, with a readable message and `site` added.
- **How it routes:**
  - The owner is resolved with `NetworkDispatch::resolve_owner()` (`ec_get_ability_site_affinity()`).
  - When the owner is remote, the call goes to that site's `/wp-abilities/v1/abilities/{name}/run` through `ec_cross_site_rest_request()`. The acting user is preserved, and an explicit `site` forces the HTTP loopback transport.
  - When the owner is local or unknown, the call goes through the substrate's `agents/ability-call`.
- **Permission:** `Access::permission_callback()` (see above). The target ability's own `permission_callback` then runs on the target site as the acting user. `Access` also widens the substrate's `agents_ability_call_permission` to the same audience, for exposed abilities only.
- **Exposure:** `show_in_rest` true (annotations: not idempotent). MCP tool on the Extra Chill MCP server. No `extrachill-api` route or `wp extrachill` command found.
- **Side effects:**
  - Whatever the target ability does.
  - Records the resolved target name and site in `Usage\InvocationContext`, so the `mcp_tool_invoked` usage event names the real ability rather than `ability-call`.
