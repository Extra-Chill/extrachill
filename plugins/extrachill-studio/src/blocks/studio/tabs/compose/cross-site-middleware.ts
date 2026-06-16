/**
 * Cross-site apiFetch middleware for the Studio compose pane.
 *
 * Studio (blog 12) is a pure tool surface; blog posts drafted in the Compose
 * pane must be BORN ON MAIN extrachill.com (blog 1) so editorial review and
 * publishing happen where editors actually look. See
 * Extra-Chill/extrachill-studio#75.
 *
 * The compose pane and the Blocks Everywhere block editor both talk to core
 * REST routes (`/wp/v2/posts`, `/wp/v2/posts/<id>/autosaves`, `/wp/v2/media`)
 * through `@wordpress/api-fetch`. Rather than fork the editor or rewrite every
 * call site, we install a single apiFetch middleware that transparently
 * rewrites those paths onto Studio-local proxy routes
 * (`/extrachill/v1/studio/compose/*`). Each proxy route forwards the request
 * to main via `ec_cross_site_rest_request( 'main', ... )` (in-process,
 * under the user's auth).
 *
 * Critically, this also catches the block editor's own inline media uploads
 * (`uploadMedia()` POSTs to `/wp/v2/media` via apiFetch), so inserted images
 * land in main's media library — not Studio's — avoiding the cross-site
 * attachment migration problem.
 *
 * Scope — this matters: apiFetch is a single global, shared by every Studio
 * tab. The rewrite must apply ONLY while the Compose pane is active, because
 * other tabs legitimately hit the same core routes against the LOCAL Studio
 * site (e.g. the Socials tab POSTs `/wp/v2/posts` to create a Studio-local
 * social draft). So the middleware is registered once but stays INERT until
 * the Compose pane activates it on mount, and deactivates it on unmount. When
 * inactive it passes every request through untouched. Even when active it only
 * rewrites the specific compose routes below; all other traffic (core editor
 * bootstrap preloads, taxonomies, user lookups) passes through so the editor
 * still boots against the local Studio site.
 */

import apiFetch from '@wordpress/api-fetch';
import type { APIFetchMiddleware, APIFetchOptions } from '@wordpress/api-fetch';

/** Studio-local proxy route prefix that forwards to main. */
const PROXY_PREFIX = '/extrachill/v1/studio/compose';

/** Whether the middleware has been registered with apiFetch (once per page). */
let registered = false;

/**
 * Whether rewriting is currently active. Gated to the Compose pane's lifetime
 * so other Studio tabs' core-route calls reach the local Studio site.
 */
let active = false;

/**
 * Split an apiFetch path into its route and query-string parts.
 *
 * @param path Full apiFetch path, e.g. `/wp/v2/posts?status=draft&author=5`.
 * @return Tuple of [route, query] where query includes the leading `?` or ''.
 */
function splitPath( path: string ): [ string, string ] {
	const queryIndex = path.indexOf( '?' );
	if ( queryIndex === -1 ) {
		return [ path, '' ];
	}
	return [ path.slice( 0, queryIndex ), path.slice( queryIndex ) ];
}

/**
 * Rewrite a core REST route onto its Studio compose proxy equivalent.
 *
 * Returns the rewritten path, or null when the route is not one we proxy
 * (in which case the request is left untouched).
 *
 * Handled routes:
 *   - /wp/v2/posts                      → /extrachill/v1/studio/compose/posts
 *   - /wp/v2/posts/<id>                 → /extrachill/v1/studio/compose/posts/<id>
 *   - /wp/v2/posts/<id>/autosaves       → /extrachill/v1/studio/compose/posts/<id>/autosaves
 *   - /wp/v2/media                      → /extrachill/v1/studio/compose/media
 *   - /wp/v2/media/<id>                 → /extrachill/v1/studio/compose/media/<id>
 *
 * @param path Full apiFetch path including any query string.
 * @return Rewritten path, or null when no rewrite applies.
 */
function rewritePath( path: string ): string | null {
	const [ route, query ] = splitPath( path );
	// Normalize leading slash so both `/wp/v2/...` and `wp/v2/...` match.
	const normalized = route.startsWith( '/' ) ? route : `/${ route }`;

	// /wp/v2/posts/<id>/autosaves
	const autosaveMatch = normalized.match( /^\/wp\/v2\/posts\/(\d+)\/autosaves$/ );
	if ( autosaveMatch ) {
		return `${ PROXY_PREFIX }/posts/${ autosaveMatch[ 1 ] }/autosaves${ query }`;
	}

	// /wp/v2/posts/<id>
	const postMatch = normalized.match( /^\/wp\/v2\/posts\/(\d+)$/ );
	if ( postMatch ) {
		return `${ PROXY_PREFIX }/posts/${ postMatch[ 1 ] }${ query }`;
	}

	// /wp/v2/posts (collection: list + create)
	if ( normalized === '/wp/v2/posts' ) {
		return `${ PROXY_PREFIX }/posts${ query }`;
	}

	// /wp/v2/media/<id> (single attachment hydrate after upload / re-resolve)
	const mediaItemMatch = normalized.match( /^\/wp\/v2\/media\/(\d+)$/ );
	if ( mediaItemMatch ) {
		return `${ PROXY_PREFIX }/media/${ mediaItemMatch[ 1 ] }${ query }`;
	}

	// /wp/v2/media (uploads via POST + Media Library browse grid via GET)
	if ( normalized === '/wp/v2/media' ) {
		return `${ PROXY_PREFIX }/media${ query }`;
	}

	return null;
}

/**
 * Register the cross-site compose apiFetch middleware (idempotent).
 *
 * Registers the rewrite middleware with apiFetch exactly once per page. The
 * middleware is INERT until {@link setComposeCrossSiteActive} turns it on, so
 * registering it has no effect on other tabs' requests.
 */
function registerMiddlewareOnce(): void {
	if ( registered ) {
		return;
	}
	registered = true;

	const middleware: APIFetchMiddleware = ( options: APIFetchOptions, next ) => {
		if ( ! active ) {
			return next( options );
		}

		const path = typeof options.path === 'string' ? options.path : '';
		if ( path ) {
			const rewritten = rewritePath( path );
			if ( rewritten ) {
				return next( { ...options, path: rewritten } );
			}
		}

		return next( options );
	};

	apiFetch.use( middleware );
}

/**
 * Turn cross-site rewriting on or off.
 *
 * The Compose pane calls this with `true` on mount and `false` on unmount so
 * the rewrite only applies while the writer is in the Blog tab. Registering
 * the middleware lazily here keeps the whole mechanism dormant until Compose
 * is actually used.
 *
 * @param next Whether rewriting should be active.
 */
export function setComposeCrossSiteActive( next: boolean ): void {
	registerMiddlewareOnce();
	active = next;
}
