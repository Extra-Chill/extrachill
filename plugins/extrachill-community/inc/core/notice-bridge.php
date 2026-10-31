<?php
/**
 * bbPress → Theme Notice Bridge
 *
 * Routes bbPress template notices through the theme's unified notice system
 * (extrachill_notices hook in header.php) instead of rendering them inline
 * within bbPress content templates. This gives all notices one location,
 * one visual treatment, and one API.
 *
 * Also removes the "You have super admin privileges" notice — pure noise
 * on a multisite where the admin already knows they're a super admin.
 *
 * @package ExtraChill\Community
 */

defined( 'ABSPATH' ) || exit;

/**
 * Remove the super admin notice and bridge remaining bbPress notices
 * into the theme notice system.
 *
 * Runs on bbp_init so bbPress hooks are already registered.
 */
function extrachill_community_bridge_bbpress_notices() {
	// Remove the useless super admin notice entirely.
	remove_action( 'bbp_template_notices', 'bbp_notice_edit_user_is_super_admin', 2 );

	// Bridge remaining bbPress notices into the theme header notice area.
	add_action( 'extrachill_notices', 'extrachill_community_render_bbpress_notices', 20 );
}
add_action( 'bbp_init', 'extrachill_community_bridge_bbpress_notices' );

/**
 * Capture bbPress template notices and render them through the theme notice system.
 *
 * Fires on extrachill_notices (header.php) so notices appear in the standard
 * header notice area instead of scattered inline within bbPress templates.
 *
 * bbPress notices come in two forms:
 * 1. bbp_errors WP_Error bag (rendered by bbp_template_notices at priority 20)
 * 2. Direct HTML output from individual notice callbacks (user success, pending email)
 *
 * We capture the full output of the bbp_template_notices action, extract the
 * text content, and render it using theme notice markup.
 */
function extrachill_community_render_bbpress_notices() {
	if ( ! function_exists( 'is_bbpress' ) || ! is_bbpress() ) {
		return;
	}

	ob_start();
	do_action( 'bbp_template_notices' );
	$bbp_output = ob_get_clean();

	if ( empty( trim( $bbp_output ) ) ) {
		return;
	}

	// Parse the bbPress notice HTML and re-render through theme notice classes.
	// bbPress uses: .bbp-template-notice, .bbp-template-notice.error, .bbp-template-notice.info
	// Theme uses:   .notice.notice-info, .notice.notice-error, .notice.notice-success
	$doc = new DOMDocument();
	$doc->loadHTML( '<?xml encoding="utf-8" ?>' . $bbp_output, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR );

	$divs = $doc->getElementsByTagName( 'div' );
	foreach ( $divs as $div ) {
		$classes = $div->getAttribute( 'class' );

		// Only process bbPress notice divs.
		if ( false === strpos( $classes, 'bbp-template-notice' ) ) {
			continue;
		}

		// Map bbPress notice type to theme notice type.
		if ( false !== strpos( $classes, 'error' ) || false !== strpos( $classes, 'warning' ) ) {
			$type = 'error';
		} elseif ( false !== strpos( $classes, 'important' ) ) {
			$type = 'info';
		} else {
			$type = 'success';
		}

		// Extract text from <li> elements inside the notice.
		$items = $div->getElementsByTagName( 'li' );
		foreach ( $items as $item ) {
			$text = trim( $item->textContent );
			if ( ! empty( $text ) ) {
				printf(
					'<div class="notice notice-%s"><p>%s</p></div>',
					esc_attr( $type ),
					wp_kses_post( $doc->saveHTML( $item ) )
				);
			}
		}
	}
}
