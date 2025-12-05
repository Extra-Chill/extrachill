<?php
/**
 * Newsletter Breadcrumb Customization
 *
 * Customizes breadcrumb display for newsletter single posts.
 *
 * @package ExtraChillNewsletter
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Newsletter breadcrumb root
 *
 * On homepage: just "Extra Chill"
 * On other pages: "Extra Chill › Newsletter"
 *
 * @param string $root_link Default root breadcrumb link HTML
 * @return string Modified root link
 * @since 0.1.0
 */
function newsletter_breadcrumb_root( $root_link ) {
	// Only apply on newsletter.extrachill.com (blog ID 9)
	if ( get_current_blog_id() !== 9 ) {
		return $root_link;
	}

	// On homepage, just "Extra Chill" (trail will add "Newsletter")
	if ( is_front_page() ) {
		return '<a href="https://extrachill.com">Extra Chill</a>';
	}

	// On other pages, include "Newsletter" in root
	return '<a href="https://extrachill.com">Extra Chill</a> › <a href="' . esc_url( home_url() ) . '">Newsletter</a>';
}
add_filter( 'extrachill_breadcrumbs_root', 'newsletter_breadcrumb_root' );

/**
 * Newsletter homepage breadcrumb trail
 *
 * Displays just "Newsletter" (no link) on the homepage to prevent "Archives" suffix.
 * Priority 5 to run before the single post breadcrumb function.
 *
 * @param string $custom_trail Existing custom trail from other plugins
 * @return string Breadcrumb trail HTML
 * @since 0.1.0
 */
function newsletter_breadcrumb_trail_homepage( $custom_trail ) {
	// Only apply on newsletter.extrachill.com (blog ID 9)
	if ( get_current_blog_id() !== 9 ) {
		return $custom_trail;
	}

	// Only on front page (homepage)
	if ( is_front_page() ) {
		return '<span>Newsletter</span>';
	}

	return $custom_trail;
}
add_filter( 'extrachill_breadcrumbs_override_trail', 'newsletter_breadcrumb_trail_homepage', 5 );

/**
 * Newsletter breadcrumb format for single posts
 *
 * @param string $custom_trail Existing custom trail from other plugins
 * @return string Empty string
 * @since 0.1.0
 */
function newsletter_customize_breadcrumbs( $custom_trail ) {
	if ( get_current_blog_id() !== 9 ) {
		return $custom_trail;
	}

	if ( ! is_singular( 'newsletter' ) ) {
		return $custom_trail;
	}

	return '';
}
add_filter( 'extrachill_breadcrumbs_override_trail', 'newsletter_customize_breadcrumbs' );

/**
 * Override back-to-home link label for newsletter pages
 *
 * Changes "Back to Extra Chill" to "Back to Newsletter" on newsletter pages.
 * Uses theme's extrachill_back_to_home_label filter.
 * Only applies on blog ID 9 (newsletter.extrachill.com).
 *
 * @param string $label Default back-to-home link label
 * @param string $url   Back-to-home link URL
 * @return string Modified label
 * @since 0.1.0
 */
function newsletter_back_to_home_label( $label, $url ) {
	// Only apply on newsletter.extrachill.com (blog ID 9)
	if ( get_current_blog_id() !== 9 ) {
		return $label;
	}

	// Don't override on homepage (homepage should say "Back to Extra Chill")
	if ( is_front_page() ) {
		return $label;
	}

	return '← Back to Newsletter';
}
add_filter( 'extrachill_back_to_home_label', 'newsletter_back_to_home_label', 10, 2 );
