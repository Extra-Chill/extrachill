# Extra Chill Link Pages

Owner-neutral Link Pages storage and operation runtime for the Extra Chill network.

## Runtime contract

The plugin loads its generic API synchronously from the exact plugin basename
`extrachill-link-pages/extrachill-link-pages.php`. Consumers may validate
`EC_LINK_PAGES_RUNTIME_API_VERSION === '1'` and
`ec_link_pages_runtime_ready() === true` before registering adapters. The API is
therefore available before `plugins_loaded` priority 20.

Compatibility is deliberate: `EC_LINK_PAGE_POST_TYPE` remains the legacy
storage slug `artist_link_page`, and `EC_LINK_PAGE_OWNER_META_KEY` remains
`_ec_link_page_owner_reference`. Existing posts, IDs, slugs, metadata, site
ownership, routes, and rendering remain untouched.

The CPT's structural registration settings and English labels remain unchanged.
The standalone plugin intentionally owns label translation through the
`extrachill-link-pages` text domain; this is a translation-ownership change,
not a storage, capability, rewrite, or public-routing change.

## Deployment order

1. Deploy the Artist Platform compatibility handoff while its bundled fallback remains available.
2. Install and activate Extra Chill Link Pages on the same site or network scope.
3. On that activation request, this plugin accepts already-loaded fallback symbols and avoids duplicate CPT registration.
4. On subsequent requests, Artist Platform detects the exact active basename, defers the generic runtime and CPT, then registers its external adapters at `plugins_loaded` priority 20.

This rolling handoff intentionally does not add a hard `Requires Plugins`
header to Artist Platform yet: WordPress would prevent deploying that
compatibility build before this new plugin exists. After the rolling transition
is complete, Artist Platform can declare `Requires Plugins: extrachill-link-pages`.

## Multisite lifecycle

Network activation and deactivation enumerate site IDs in bounded pages of 100,
switch into each site, flush that site's rewrite rules, and restore the caller's
exact multisite context. Sites initialized after network activation are flushed
after WordPress completes their core initialization. Query, switch, callback,
context-restoration, and runtime-contract failures are exposed as `WP_Error`,
logged, published through `ec_link_pages_runtime_error`, and shown in site and
network admin notices. Activation terminates on such an error so WordPress does
not report a broken runtime as successfully activated.

Deactivation unregisters the standalone-owned legacy storage type before
flushing affected sites, so standalone rewrites are removed rather than
re-created. During the rolling transition, Artist Platform fallback may resume
on the next request. That fallback must re-establish its rewrites through Artist
Platform's existing activation or rewrite lifecycle; this plugin does not load
or invoke the fallback during deactivation.
