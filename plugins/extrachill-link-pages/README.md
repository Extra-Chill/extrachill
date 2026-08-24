# Extra Chill Link Pages

Owner-neutral Link Pages storage and operation runtime for the Extra Chill network.

## Runtime contract

The plugin loads its generic API synchronously from the exact plugin basename
`extrachill-link-pages/extrachill-link-pages.php`. Consumers may validate
`EC_LINK_PAGES_RUNTIME_API_VERSION === '3'` and
`ec_link_pages_runtime_ready() === true` before registering adapters. The API is
therefore available before `plugins_loaded` priority 20.

Compatibility is deliberate: `EC_LINK_PAGE_POST_TYPE` remains the legacy
storage slug `artist_link_page`, and `EC_LINK_PAGE_OWNER_META_KEY` remains
`_ec_link_page_owner_reference`. Existing posts, IDs, slugs, metadata, site
ownership, routes, and rendering remain untouched.

## Owner adapter contract

Owner plugins keep authorization and management operations in an operation
provider, but delegate generic persistence to:

- `ec_read_link_page_persistence( int $link_page_id, array $overrides = array() )`
- `ec_save_link_page_persistence( int $link_page_id, array $data )`
- `ec_save_link_page_persistence_composed( int $link_page_id, array $data, callable $finalizer )`
- `ec_create_owned_link_page( string|array $owner, string $title, string $slug, bool $force = false )`
- `ec_provision_owned_link_page( string|array $owner, string $title, string $slug, bool $force = false, ?callable $precondition = null )`
- `ec_provision_owned_link_page_composed( string|array $owner, string $title, string $slug, callable $finalizer, bool $force = false, ?callable $precondition = null )`

The create primitive validates the canonical owner, rejects an occupied slug,
verifies that WordPress did not suffix the requested slug, assigns the owner,
and deletes the new post if assignment fails. Existing owner records are
returned unless `$force` is true. Owner adapters may maintain reciprocal legacy
metadata around this primitive, but generic storage does not know that policy.
The provisioning primitive serializes by canonical owner and returns
`array( 'link_page_id' => int, 'created' => bool )`. Its optional precondition
runs inside the owner lock immediately before lookup or creation; the historical
create wrapper continues returning only the integer ID.

On multisite, canonical storage resolves in this order: a valid
`EC_LINK_PAGE_STORAGE_BLOG_ID` configuration constant, the
`ec_link_page_storage_blog_id` filter, then the persisted network option. The
constant supports fresh network activation before host plugins can register
runtime filters.

The composed save finalizer receives `( $link_page_id, $persistence )`; the
composed provision finalizer receives `( $link_page_id, $owner_reference )` for
new, force-replaced, and existing exact owner pages. Finalizers return exactly
`true` or `WP_Error`. Both run under the exact Link Page lock before any generic
success hook. On failure, generic save metadata is restored or a newly created
page is removed; an existing page remains unchanged. The finalizer owns
compensation for state outside the generic Link Page storage snapshots.

Public display is registered with
`ec_register_link_page_public_projection_provider( $name, $callback, $priority = 10 )`.
The callback receives a local context containing `link_page_id`, parsed `owner`,
`owner_reference`, `public_url`, and request scalar data. It returns `null` when
it does not own the reference, otherwise one strict projection:

```php
array(
	'display_title'    => 'Required title',
	'bio'              => '',
	'profile_img_url'  => '',
	'social_links'     => array(),
	'social_renderer'  => null, // callable( $links, $position, $context ): string
	'management_url'   => '',
	'body_attributes'  => array(),
	'seo'              => array(),
	'tracking_url'     => '',
	'components'       => array(), // before_header, after_header, after_links, footer
	'assets'           => null, // callable( $context, $projection ): true|WP_Error
)
```

Component slots contain ordered callback arrays. Provider and component calls
are context-checked and exceptions fail closed. A live provider takes precedence;
when no provider is loaded, `ec_save_link_page_public_projection_snapshot()` and
`ec_read_link_page_public_projection_snapshot()` provide the versioned,
owner-checksummed, serializable fallback. The public renderer makes no HTTP
request to discover owner data.

The Artist companion must validate API version `3`, add the new function
signatures, register a public provider, and delegate its existing management
callbacks to generic persistence. It must stop loading its old public runtime
before both plugins run together. Historical Artist-named query variables, body
attributes, and the `extrachill_artist_link_page_minimal_head` hook remain only
as documented compatibility identifiers; the companion supplies its historical
body attributes and optional domain components.

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

Successful activation persists the explicitly resolved canonical storage blog
in the network option `ec_link_page_storage_blog_id`. Runtime resolution checks
an explicit filter first and then that validated option. Network activation with
neither source fails closed. Activation and deactivation touch only that storage
site and restore the caller's exact multisite context. Query, switch, callback,
context-restoration, and runtime-contract failures are exposed as `WP_Error`,
logged, published through `ec_link_pages_runtime_error`, and shown in site and
network admin notices. Activation terminates on such an error so WordPress does
not report a broken runtime as successfully activated.

Deactivation unregisters the standalone-owned legacy storage type before
flushing affected sites, so standalone rewrites are removed rather than
re-created. During the rolling transition, Artist Platform fallback may resume
on the next request. That fallback must re-establish its rewrites through Artist
Platform's existing activation or rewrite lifecycle; this plugin does not load
or invoke the fallback during deactivation. Deactivation deliberately preserves
the canonical storage option because fallback code may still own records there;
silently deleting it could fork subsequent storage ownership.
