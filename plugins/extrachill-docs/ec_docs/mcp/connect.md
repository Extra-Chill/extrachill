# Connecting Your AI Client

Extra Chill runs an MCP (Model Context Protocol) server. Connect your own AI client and it can search and act across the network as you — scoped to your own account, with no Extra Chill-hosted chat interface in between.

To connect, see [extrachill.com/mcp](https://extrachill.com/mcp). This page covers what you can do once connected, and how access is decided.

## How the surface works

The server advertises two tools — `ability-search` and `ability-call` — rather than one tool per capability. Your client searches for what it needs, then calls it by name.

This keeps the advertised tool count constant no matter how large the platform grows. The network spans ten sites and several hundred capabilities; listing them individually would consume most of a context window before you asked anything.

Search results are tagged with the site that owns each capability. Calls are routed there automatically — you connect to one endpoint, not ten.

## How permissions work

Connecting grants your AI client the ability to act **as your account** — the same permissions you have when signed in, and no more.

Every call is authorized individually, on the site that owns the thing being acted on. A venue booking check resolves the booking, finds its venue, and confirms you hold a grant for that venue — per call. There is no separate MCP permission layer to get out of sync with the rest of the platform.

Consent is all-or-nothing rather than a menu of scopes. Your client either acts as you or it does not.

## What you can reach

Depends on your account. Broadly:

### Any signed-in member

- Your profile, links, email, password, and notification preferences
- Subscriptions to artists, venues, festivals, and scenes
- Marking shows you are attending, and importing concert history
- Submitting event sources and venue claims
- Booking inquiries to venues
- Requesting artist or artist-dispatch access

### Artists

Everything above, plus the artist profiles and link pages you own. Artist capabilities resolve per resource — you reach your own, not everyone's.

### Venue operators and promoters

Booking, settlement, and reporting capabilities for venues you hold a grant on. Grants are per venue and per action, so finance operations can be granted separately from general venue access.

### Extra Chill team

Editorial and events administration, the platform wiki (read and write), analytics, and operational diagnostics.

## Destructive operations

Capabilities that delete or overwrite are annotated as destructive, and MCP clients use those annotations to ask before acting. Approve deliberately — your client is acting as you, and an approval is a real change to real data.

## Connecting from a server

Two OAuth grants are supported, and which one your client uses is decided by where it runs, not by preference.

**Authorization code with PKCE** is for clients on the same machine as the browser. The client opens a loopback listener, the browser is sent to `127.0.0.1` after consent, and the code lands. That only works because the browser and the listener share a machine.

**Device authorization (RFC 8628)** is for everything else — an agent on a VPS, a bot in a container, a CLI on a box you reach over SSH. The client requests a device code and a short user code, the user opens `https://auth.extrachill.com/device` in any browser anywhere, enters the code and approves, and the client polls `https://auth.extrachill.com/token` until the grant lands. Nothing has to route back to the client, so there is nothing to tunnel.

- **Device authorization endpoint:** `https://auth.extrachill.com/device_authorization`
- **Verification page:** `https://auth.extrachill.com/device`
- **Grant type:** `urn:ietf:params:oauth:grant-type:device_code`
- **Register with:** `grant_types: ["urn:ietf:params:oauth:grant-type:device_code", "refresh_token"]` and an empty `response_types`
- **Poll interval:** honour the `interval` in the response; polling faster returns `slow_down` and the interval grows

The tell that a client picked the wrong grant: authorization completes, consent is approved, and the final URL is `http://127.0.0.1:<port>/...?code=...&state=...` followed by `ERR_CONNECTION_REFUSED`. Every step on the server succeeded. The browser simply cannot reach a listener on a different machine. Switch the client to the device grant; no server-side change will fix it.

## Revoking access

Go to [your settings](https://community.extrachill.com/settings), then Security → Connected Apps. Revoking stops the client renewing its access immediately. A session already in progress can continue for up to fifteen minutes before its current token expires.