<?php
/**
 * Newsletter Post Meta Customization
 *
 * Customizes post meta display for newsletter post type.
 *
 * @package ExtraChillNewsletter
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Display "Sent on {date}" for newsletter posts
 *
 * Published status assumes newsletter has been sent.
 */
function newsletter_custom_post_meta( $output, $post_id, $post_type ) {
	if ( $post_type !== 'newsletter' ) {
		return $output; // Use default for all other post types
	}

	// Published = Sent (core assumption)
	$sent_date = get_the_date( 'F j, Y', $post_id );

	return sprintf(
		'<div class="below-entry-meta newsletter-meta"><div class="below-entry-meta-left"><div class="meta-top-row">Sent on <time class="entry-date published" datetime="%s">%s</time></div></div></div>',
		esc_attr( get_the_date( 'c', $post_id ) ),
		esc_html( $sent_date )
	);
}
add_filter( 'extrachill_post_meta', 'newsletter_custom_post_meta', 10, 3 );
