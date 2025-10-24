<?php
/**
 * Newsletter Breadcrumb Customization
 *
 * Customizes breadcrumb display for newsletter single posts.
 *
 * @package ExtraChillNewsletter
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Customize breadcrumbs for newsletter single posts
 *
 * Provides "Newsletter → Post Title" breadcrumb format with link to homepage
 * since newsletters have no archive URL (homepage serves as archive).
 *
 * @since 1.0.0
 * @param string $custom_trail Existing custom trail from other filters
 * @return string Modified breadcrumb trail
 */
function newsletter_customize_breadcrumbs( $custom_trail ) {
	// Only modify on newsletter single posts
	if ( ! is_singular( 'newsletter' ) ) {
		return $custom_trail;
	}

	// Link to newsletter homepage (which serves as archive)
	$newsletter_home = 'https://newsletter.extrachill.com';
	$post_title = get_the_title();

	return '<a href="' . esc_url( $newsletter_home ) . '">Newsletter</a> → <span>' . esc_html( $post_title ) . '</span>';
}
add_filter( 'extrachill_breadcrumbs_override_trail', 'newsletter_customize_breadcrumbs' );
