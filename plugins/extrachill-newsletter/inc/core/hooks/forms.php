<?php
/**
 * Newsletter Form Hook Handlers
 *
 * Handles subscription form display via theme action hooks.
 *
 * @package ExtraChillNewsletter
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function extrachill_newsletter_archive_form() {
	if ( ! is_post_type_archive( 'newsletter' ) ) {
		return;
	}

	$settings = get_site_option( 'extrachill_newsletter_settings', array() );
	if ( empty( $settings['enable_archive'] ) ) {
		return;
	}

	wp_enqueue_script( 'extrachill-newsletter' );
	wp_enqueue_style( 'extrachill-newsletter-forms' );

	include EXTRACHILL_NEWSLETTER_TEMPLATES_DIR . 'archive-form.php';
}
add_action( 'extrachill_archive_below_description', 'extrachill_newsletter_archive_form', 10 );
function extrachill_newsletter_homepage_hero_form() {
	$settings = get_site_option( 'extrachill_newsletter_settings', array() );
	if ( empty( $settings['enable_archive'] ) ) {
		return;
	}

	wp_enqueue_script( 'extrachill-newsletter' );
	wp_enqueue_style( 'extrachill-newsletter-forms' );

	include EXTRACHILL_NEWSLETTER_TEMPLATES_DIR . 'archive-form.php';
}
add_action( 'newsletter_homepage_hero', 'extrachill_newsletter_homepage_hero_form', 10 );
