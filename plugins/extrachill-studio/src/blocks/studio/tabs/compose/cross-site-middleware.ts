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
 * ## Why this is NOT gated by a lifecycle-toggled global (regression #106)
 *
 * The original implementation gated the rewrite on a module-level `active`
 * boolean that the Compose pane flipped `true` on React mount and `false` on
 * unmount. That was racy: any compose write (autosave, media upload, or the
 * final Submit-for-Review) that dispatched while the flag was `false` — before
 * the mount effect ran, or after the unmount cleared it — silently escaped to
 * Studio-local `/wp/v2/posts|media` and landed on blog 12. With a real
 * submission (69 uploads + autosaves + a submit) the odds of *something*
 * firing outside the window were high; a stranded post + 69 attachments on
 * blog 12 is exactly what happened (#106).
 *
 * The gate is now split into two race-free mechanisms, either of which forces
 * a rewrite:
 *
 *   1. **Explicit per-request marker.** The Compose pane tags every request it
 *      originates (autosave, submit, save-draft, draft load, content refetch)
 *      with an `X-EC-Studio-Compose` header (see {@link markComposeRequest}).
 *      A marked request is ALWAYS rewritten — the routing decision is attached
 *      to the request itself, so there is no timing window at all.
 *
 *   2. **Live-instance reference count.** The block editor's OWN internal calls
 *      (core autosave store writes, `uploadMedia()` uploads) are made by core,
 *      not the Compose pane, so we cannot mark them at their call site. Instead
 *      the Compose pane registers a live editor instance for its entire
 *      lifetime ({@link registerComposeInstance}). While at least one compose
 *      instance is live, compose-route traffic rewrites by default.
 *
 * The routing decision is made by apiFetch middleware at DISPATCH time, not at
 * completion — so a request that is still in-flight when the pane unmounts was
 * already rewritten when it was created. The only escape the old boolean had
 * (a compose write dispatching while the flag read `false`) cannot occur here:
 * compose writes only originate while an instance is live (refcount ≥ 1), and
 * the marker forces a rewrite independently of the count.
 *
 * ## Preserving Socials' LOCAL writes
 *
 * apiFetch is a single global shared by every Studio tab, and the Socials tab
 * legitimately POSTs `/wp/v2/posts` to the LOCAL Studio site to create a
 * Studio-local social draft. Because the refcount defaults compose routes to
 * "rewrite" while a compose instance is live, Socials must OPT OUT explicitly:
 * it tags its local writes with `X-EC-Studio-Local` (see
 * {@link markLocalRequest}). A locally-marked request is NEVER rewritten,
 * regardless of the refcount — so Socials always reaches blog 12 even when the
 * Compose pane is mounted in a background tab. This is deterministic: the two
 * markers are mutually exclusive and each is attached at the originating call
 * site, so no shared mutable timing state decides where a write lands.
 *
 * Non-compose routes (core editor bootstrap preloads, taxonomies, user
 * lookups) are never matched by {@link rewritePath} and always pass through so
 * the editor still boots against the local Studio site.
 */

import apiFetch from '@wordpress/api-fetch';
import type { APIFetchMiddleware, APIFetchOptions } from '@wordpress/api-fetch';

/** Studio-local proxy route prefix that forwards to main. */
const PROXY_PREFIX = '/extrachill/v1/studio/compose';

/**
 * Request header a Compose-pane call attaches to force a rewrite to main.
 * Set via {@link markComposeRequest} on every apiFetch the pane originates.
 */
export const COMPOSE_MARKER_HEADER = 'X-EC-Studio-Compose';

/**
 * Request header a caller attaches to force a LOCAL (Studio blog 12) write,
 * opting out of the compose rewrite even while a compose instance is live.
 * Set via {@link markLocalRequest} — used by the Socials tab.
 */
export const LOCAL_MARKER_HEADER = 'X-EC-Studio-Local';

/** Whether the middleware has been registered with apiFetch (once per page). */
let registered = false;

/**
 * Number of live Compose editor instances. While > 0, compose-route traffic
 * rewrites to main by default (unless the request opts out with the local
 * marker). A reference count — not a lifecycle boolean — so overlapping
 * mounts/unmounts (React strict-mode double-invoke, tab churn) can never leave
 * the gate stuck open or closed.
 */
let liveInstances = 0;

/**
 * Read a header value from apiFetch options case-insensitively.
 *
 * @param options apiFetch options.
 * @param name    Header name to look up.
 * @return The header value, or undefined when absent.
 */
function readHeader( options: APIFetchOptions, name: string ): string | undefined {
	const headers = options.headers;
	if ( ! headers || typeof headers !== 'object' ) {
		return undefined;
	}
	const wanted = name.toLowerCase();
	for ( const key of Object.keys( headers as Record< string, string > ) ) {
		if ( key.toLowerCase() === wanted ) {
			return ( headers as Record< string, string > )[ key ];
		}
	}
	return undefined;
}

/**
 * Attach the compose marker header to apiFetch options.
 *
 * Every request the Compose pane originates should be wrapped with this so it
 * is ALWAYS rewritten to main, independent of the live-instance count.
 *
 * @param options apiFetch options.
 * @return New options with the compose marker header set.
 */
export function markComposeRequest< T extends APIFetchOptions >( options: T ): T {
	return {
		...options,
		headers: {
			...( options.headers as Record< string, string > | undefined ),
			[ COMPOSE_MARKER_HEADER ]: '1',
		},
	};
}

/**
 * Attach the local marker header to apiFetch options.
 *
 * Callers that must write to the LOCAL Studio site (blog 12) even while a
 * Compose instance is live — e.g. the Socials tab creating a social draft —
 * wrap their options with this so the middleware never rewrites them.
 *
 * @param options apiFetch options.
 * @return New options with the local marker header set.
 */
export function markLocalRequest< T extends APIFetchOptions >( options: T ): T {
	return {
		...options,
		headers: {
			...( options.headers as Record< string, string > | undefined ),
			[ LOCAL_MARKER_HEADER ]: '1',
		},
	};
}

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
 * Decide whether a given apiFetch request should be rewritten to main.
 *
 * Rewrite when EITHER:
 *   - the request carries the compose marker (pane-originated call), OR
 *   - at least one Compose instance is live (covers the block editor's own
 *     internal autosave/upload calls, which we cannot mark at their source).
 *
 * Never rewrite when the request carries the local marker — that is an
 * explicit opt-out (Socials) that must reach blog 12 even while Compose is
 * mounted. The local marker wins over the live-instance default, so the two
 * tabs never fight over a shared boolean.
 *
 * @param options apiFetch options for the request.
 * @return True when the request should be rewritten to main.
 */
function shouldRewrite( options: APIFetchOptions ): boolean {
	if ( readHeader( options, LOCAL_MARKER_HEADER ) ) {
		return false;
	}
	if ( readHeader( options, COMPOSE_MARKER_HEADER ) ) {
		return true;
	}
	return liveInstances > 0;
}

/**
 * Register the cross-site compose apiFetch middleware (idempotent).
 *
 * Registers the rewrite middleware with apiFetch exactly once per page. The
 * middleware only rewrites when {@link shouldRewrite} says so, so registering
 * it has no effect on other tabs' unmarked, no-instance-live requests.
 */
function registerMiddlewareOnce(): void {
	if ( registered ) {
		return;
	}
	registered = true;

	const middleware: APIFetchMiddleware = ( options: APIFetchOptions, next ) => {
		if ( ! shouldRewrite( options ) ) {
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
 * Register a live Compose editor instance.
 *
 * The Compose pane calls this once, synchronously, from its first mount effect
 * — before the block editor is created and before any compose request can
 * dispatch — and calls the returned disposer on unmount. While the count is
 * above zero, the block editor's own internal `/wp/v2/posts|media` calls are
 * rewritten to main. Reference-counted so overlapping instances (or React
 * strict-mode's double-invoke) can never leave the gate stuck.
 *
 * Registering the middleware lazily here keeps the whole mechanism dormant
 * until Compose is actually used.
 *
 * @return A disposer that decrements the live-instance count.
 */
export function registerComposeInstance(): () => void {
	registerMiddlewareOnce();
	liveInstances += 1;

	let disposed = false;
	return () => {
		if ( disposed ) {
			return;
		}
		disposed = true;
		liveInstances = Math.max( 0, liveInstances - 1 );
	};
}
