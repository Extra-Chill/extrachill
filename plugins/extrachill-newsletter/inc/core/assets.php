<?php
/**
 * Asset Management
 *
 * Centralized asset loading with conditional enqueuing and filemtime() cache busting.
 *
 * @package ExtraChillNewsletter
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function newsletter_enqueue_frontend_assets() {
	// Forms CSS - loaded globally for navigation and other forms
	$forms_css_path = EXTRACHILL_NEWSLETTER_PLUGIN_DIR . 'assets/css/newsletter-forms.css';
	if ( file_exists( $forms_css_path ) ) {
		wp_enqueue_style(
			'extrachill-newsletter-forms',
			EXTRACHILL_NEWSLETTER_PLUGIN_URL . 'assets/css/newsletter-forms.css',
			array(),
			filemtime( $forms_css_path )
		);
	}

	// Sidebar CSS - loaded globally since sidebar appears on all pages
	$sidebar_css_path = EXTRACHILL_NEWSLETTER_PLUGIN_DIR . 'assets/css/sidebar.css';
	if ( file_exists( $sidebar_css_path ) ) {
		wp_enqueue_style(
			'extrachill-newsletter-sidebar',
			EXTRACHILL_NEWSLETTER_PLUGIN_URL . 'assets/css/sidebar.css',
			array(),
			filemtime( $sidebar_css_path )
		);
	}

	// Newsletter page CSS - loaded only on newsletter/festival wire pages
	$load_newsletter_css = ( get_current_blog_id() === 9 && is_front_page() ) ||
	                       is_post_type_archive( 'newsletter' ) ||
	                       is_singular( 'newsletter' ) ||
	                       is_post_type_archive( 'festival_wire' ) ||
	                       is_singular( 'festival_wire' );

	if ( $load_newsletter_css ) {
		$newsletter_css_path = EXTRACHILL_NEWSLETTER_PLUGIN_DIR . 'assets/css/newsletter.css';
		if ( file_exists( $newsletter_css_path ) ) {
			wp_enqueue_style(
				'extrachill-newsletter',
				EXTRACHILL_NEWSLETTER_PLUGIN_URL . 'assets/css/newsletter.css',
				array( 'extrachill-newsletter-forms' ),
				filemtime( $newsletter_css_path )
			);
		}
	}

	// Main JavaScript
	$newsletter_js_path = EXTRACHILL_NEWSLETTER_PLUGIN_DIR . 'assets/js/newsletter.js';
	if ( file_exists( $newsletter_js_path ) ) {
		wp_enqueue_script(
			'extrachill-newsletter',
			EXTRACHILL_NEWSLETTER_PLUGIN_URL . 'assets/js/newsletter.js',
			array( 'jquery' ),
			filemtime( $newsletter_js_path ),
			true
		);

		wp_localize_script(
			'extrachill-newsletter',
			'newsletterParams',
			array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'newsletter_nonce' => wp_create_nonce( 'newsletter_nonce' ),
				'newsletter_popup_nonce' => wp_create_nonce( 'newsletter_popup_nonce' ),
				'subscribe_to_sendy_home_nonce' => wp_create_nonce( 'subscribe_to_sendy_home_nonce' ),
				'newsletter_content_nonce' => wp_create_nonce( 'newsletter_content_nonce' ),
				'newsletter_footer_nonce' => wp_create_nonce( 'newsletter_footer_nonce' ),
			)
		);
	}
}
add_action( 'wp_enqueue_scripts', 'newsletter_enqueue_frontend_assets' );
function newsletter_enqueue_admin_assets( $hook ) {
	global $post_type;
	if ( 'newsletter' !== $post_type ) {
		return;
	}

	$admin_css_path = EXTRACHILL_NEWSLETTER_PLUGIN_DIR . 'assets/css/admin.css';
	if ( file_exists( $admin_css_path ) ) {
		wp_enqueue_style(
			'extrachill-newsletter-admin',
			EXTRACHILL_NEWSLETTER_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			filemtime( $admin_css_path )
		);
	}
}
add_action( 'admin_enqueue_scripts', 'newsletter_enqueue_admin_assets' );

/**
 * Force theme archive CSS on newsletter homepage
 *
 * Newsletter homepage (blog ID 9) uses archive layout but is_front_page() prevents normal loading.
 */
function newsletter_enqueue_theme_archive_css() {
	if ( get_current_blog_id() === 9 && is_front_page() ) {
		$archive_css = get_template_directory() . '/assets/css/archive.css';
		if ( file_exists( $archive_css ) ) {
			wp_enqueue_style(
				'extrachill-archive',
				get_template_directory_uri() . '/assets/css/archive.css',
				array(),
				filemtime( $archive_css )
			);
		}
	}
}
add_action( 'wp_enqueue_scripts', 'newsletter_enqueue_theme_archive_css', 20 );
