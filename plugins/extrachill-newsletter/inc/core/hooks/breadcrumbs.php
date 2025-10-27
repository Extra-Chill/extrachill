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
 * Newsletter breadcrumb root
 *
 * On homepage: just "Extra Chill"
 * On other pages: "Extra Chill › Newsletter"
 *
 * @param string $root_link Default root breadcrumb link HTML
 * @return string Modified root link
 * @since 1.0.0
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
 * @since 1.0.0
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
 * Links to newsletter.extrachill.com (homepage-as-archive).
 *
 * @param string $custom_trail Existing custom trail from other plugins
 * @return string Breadcrumb trail HTML
 * @since 1.0.0
 */
function newsletter_customize_breadcrumbs( $custom_trail ) {
	// Only apply on newsletter.extrachill.com (blog ID 9)
	if ( get_current_blog_id() !== 9 ) {
		return $custom_trail;
	}

	// Only modify on newsletter single posts
	if ( ! is_singular( 'newsletter' ) ) {
		return $custom_trail;
	}

	// Build breadcrumb trail (root already has "Extra Chill › Newsletter")
	$post_title = get_the_title();

	return '<span>' . esc_html( $post_title ) . '</span>';
}
add_filter( 'extrachill_breadcrumbs_override_trail', 'newsletter_customize_breadcrumbs' );
