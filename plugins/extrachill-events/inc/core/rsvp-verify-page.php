<?php
/**
 * RSVP Verify Page
 *
 * The QR scan target: a virtual page (no physical WP page) at /rsvp-verify/
 * that resolves a pass code and, for an authorized host only
 * (extrachill_events_user_can_manage_event()), shows the attendee's name
 * and a one-tap Redeem. Owns its own rewrite plumbing, mirroring the
 * established pattern in inc/core/router-pages.php and
 * inc/core/discovery-pages.php — each virtual page owns its own tag/rule
 * rather than sharing one central registry.
 *
 * Non-enumerating by design: an invalid code, a valid code viewed by an
 * unauthorized user, and a logged-out visitor all render the identical
 * generic message (see templates/rsvp-verify.php) — a scan by anyone other
 * than the event's own host learns nothing.
 *
 * @package ExtraChillEvents
 * @since 0.70.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the /rsvp-verify/ rewrite rule.
 *
 * No dedicated flush-once mechanism here: inc/core/router-pages.php already
 * owns exactly that job for this plugin's virtual pages
 * (extrachill_events_maybe_flush_router_rewrites()) — this rule is folded
 * into its existing version-bump rather than duplicating a second,
 * independent site-wide flush_rewrite_rules() call. Check what already
 * exists before adding new infrastructure.
 *
 * @hook init
 */
function extrachill_events_rsvp_verify_rewrite_rules(): void {
	if ( ! function_exists( 'ec_is_events_site' ) || ! ec_is_events_site() ) {
		return;
	}

	add_rewrite_tag( '%ec_rsvp_verify%', '(1)' );
	add_rewrite_rule( '^rsvp-verify/?$', 'index.php?ec_rsvp_verify=1', 'top' );
}
add_action( 'init', 'extrachill_events_rsvp_verify_rewrite_rules' );

/**
 * Whether the current request is the RSVP verify page.
 *
 * @return bool
 */
function extrachill_events_is_rsvp_verify_page(): bool {
	return function_exists( 'ec_is_events_site' ) && ec_is_events_site() && '1' === (string) get_query_var( 'ec_rsvp_verify', '' );
}

/**
 * Prevent core from 404ing the virtual verify page before the template
 * filter runs (there is no matched post/term for this synthetic route).
 *
 * @hook parse_query
 * @param \WP_Query $query Main query.
 */
function extrachill_events_rsvp_verify_query_flags( $query ): void {
	if ( ! $query->is_main_query() || is_admin() ) {
		return;
	}
	if ( '1' !== (string) $query->get( 'ec_rsvp_verify', '' ) ) {
		return;
	}

	$query->is_404  = false;
	$query->is_page = true;
	$query->is_home = false;
}
add_action( 'parse_query', 'extrachill_events_rsvp_verify_query_flags' );

/**
 * Exempt the verify route from page caching and answer its 200.
 *
 * Split out from the pre_handle_404 callback itself so this — the actual
 * interesting behavior — is unit-testable without a real \WP_Query: this
 * page's whole output depends on WHO is viewing and WHICH code is in the
 * query string, so extrachill-cache's page cache (which keys anonymous 200
 * GET responses by full URL including the query string — see
 * wp-content/plugins/extrachill-cache/inc/cache-store.php — for a day)
 * would otherwise cache the first logged-out visitor's result (including a
 * "could not be verified" miss) and serve it to every later anonymous
 * visitor of that exact ?code= URL for the cache's TTL. Both constants
 * matter: DONOTCACHEPAGE is what extrachill-cache's own page-cache.php
 * actually checks before storing a response; nocache_headers() covers
 * browsers/any intermediate proxy on top of that.
 *
 * @return bool Always true — this is only called once the route is
 *              confirmed to be the verify page.
 */
function extrachill_events_rsvp_verify_answer_uncached(): bool {
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	nocache_headers();

	status_header( 200 );
	return true;
}

/**
 * @hook pre_handle_404
 * @param bool|null $preempt Existing 404 preemption result.
 * @param \WP_Query $query   Main query.
 * @return bool|null
 */
function extrachill_events_rsvp_verify_pre_handle_404( $preempt, $query ) {
	if ( '1' !== (string) $query->get( 'ec_rsvp_verify', '' ) ) {
		return $preempt;
	}

	return extrachill_events_rsvp_verify_answer_uncached();
}
add_filter( 'pre_handle_404', 'extrachill_events_rsvp_verify_pre_handle_404', 10, 2 );

/**
 * Route the request to the verify-page template.
 *
 * @hook extrachill_template_archive
 * @param string $template Current template path.
 * @return string
 */
function extrachill_events_rsvp_verify_template( string $template ): string {
	if ( extrachill_events_is_rsvp_verify_page() ) {
		return EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/templates/rsvp-verify.php';
	}

	return $template;
}
add_filter( 'extrachill_template_archive', 'extrachill_events_rsvp_verify_template', 25 );

/**
 * @hook document_title_parts
 * @param array $parts Title parts.
 * @return array
 */
function extrachill_events_rsvp_verify_title( array $parts ): array {
	if ( extrachill_events_is_rsvp_verify_page() ) {
		$parts['title'] = __( 'Verify RSVP Pass', 'extrachill-events' );
	}

	return $parts;
}
add_filter( 'document_title_parts', 'extrachill_events_rsvp_verify_title', 1000 );
