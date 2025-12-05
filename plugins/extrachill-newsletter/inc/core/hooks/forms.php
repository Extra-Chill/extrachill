<?php
/**
 * Newsletter Form Hook Handlers
 *
 * Handles subscription form display on newsletter archive pages.
 * Uses the generic extrachill_render_newsletter_form action.
 *
 * @package ExtraChillNewsletter
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Display newsletter form on archive page below description
 */
function extrachill_newsletter_archive_form() {
	if ( ! is_post_type_archive( 'newsletter' ) ) {
		return;
	}

	do_action( 'extrachill_render_newsletter_form', 'archive' );
}
add_action( 'extrachill_archive_below_description', 'extrachill_newsletter_archive_form', 10 );

/**
 * Display newsletter form after single post content
 */
function extrachill_newsletter_after_post_content() {
	if ( ! is_single() ) {
		return;
	}
	do_action( 'extrachill_render_newsletter_form', 'content' );
}
add_action( 'extrachill_after_post_content', 'extrachill_newsletter_after_post_content' );

/**
 * Display newsletter form in homepage hero section (newsletter site only)
 */
function extrachill_newsletter_homepage_hero_form() {
	do_action( 'extrachill_render_newsletter_form', 'archive' );
}
add_action( 'newsletter_homepage_hero', 'extrachill_newsletter_homepage_hero_form', 10 );
