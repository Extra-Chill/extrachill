<?php
/**
 * Owner's List Pass Page (extrachill#1130)
 *
 * /owner-pass/?code=… : the holder's own view of a venue's or promoter's
 * standing guest list pass (name, which list, QR, code). The link the team
 * copies or emails. A per-event pass opens on that event's /guest-list
 * page; a standing pass belongs to no event, so it gets this small virtual
 * page instead, built like /rsvp-verify/ (inc/core/rsvp-verify-page.php).
 *
 * The code is the bearer secret, exactly like the per-event pass link. A
 * removed or unknown code shows one generic "no longer valid" message.
 *
 * @package ExtraChillEvents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the /owner-pass/ rewrite rule. Flushed through
 * extrachill_events_maybe_flush_router_rewrites().
 *
 * @hook init
 */
function extrachill_events_owner_pass_rewrite_rules(): void {
	if ( ! function_exists( 'ec_is_events_site' ) || ! ec_is_events_site() ) {
		return;
	}

	add_rewrite_tag( '%ec_owner_pass%', '(1)' );
	add_rewrite_rule( '^owner-pass/?$', 'index.php?ec_owner_pass=1', 'top' );
}
add_action( 'init', 'extrachill_events_owner_pass_rewrite_rules' );

/**
 * Whether the current request is the owner's-list pass page.
 *
 * @return bool
 */
function extrachill_events_is_owner_pass_page(): bool {
	return function_exists( 'ec_is_events_site' ) && ec_is_events_site() && '1' === (string) get_query_var( 'ec_owner_pass', '' );
}

/**
 * Keep core from 404ing the virtual page.
 *
 * @hook parse_query
 * @param \WP_Query $query Main query.
 */
function extrachill_events_owner_pass_query_flags( $query ): void {
	if ( ! $query->is_main_query() || is_admin() || '1' !== (string) $query->get( 'ec_owner_pass', '' ) ) {
		return;
	}

	$query->is_404  = false;
	$query->is_page = true;
	$query->is_home = false;
}
add_action( 'parse_query', 'extrachill_events_owner_pass_query_flags' );

/**
 * Answer 200, uncached: the page depends on the code in the URL, and a
 * removed pass must die at once rather than live on in the page cache.
 *
 * @hook pre_handle_404
 * @param bool|null $preempt Existing 404 preemption result.
 * @param \WP_Query $query   Main query.
 * @return bool|null
 */
function extrachill_events_owner_pass_pre_handle_404( $preempt, $query ) {
	if ( '1' !== (string) $query->get( 'ec_owner_pass', '' ) ) {
		return $preempt;
	}

	return extrachill_events_rsvp_verify_answer_uncached();
}
add_filter( 'pre_handle_404', 'extrachill_events_owner_pass_pre_handle_404', 10, 2 );

/**
 * Route the request to the pass template.
 *
 * @hook extrachill_template_archive
 * @param string $template Current template path.
 * @return string
 */
function extrachill_events_owner_pass_template( string $template ): string {
	return extrachill_events_is_owner_pass_page() ? EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/templates/owner-pass.php' : $template;
}
add_filter( 'extrachill_template_archive', 'extrachill_events_owner_pass_template', 25 );

/**
 * @hook document_title_parts
 * @param array $parts Title parts.
 * @return array
 */
function extrachill_events_owner_pass_title( array $parts ): array {
	if ( extrachill_events_is_owner_pass_page() ) {
		$parts['title'] = __( 'Guest List Pass', 'extrachill-events' );
	}

	return $parts;
}
add_filter( 'document_title_parts', 'extrachill_events_owner_pass_title', 1000 );
