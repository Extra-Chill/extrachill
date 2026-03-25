<?php
/**
 * Settings Page Content Renderer (block bridge)
 *
 * Keeps the existing /settings page route, but renders the new
 * headless Gutenberg block instead of the legacy PHP form.
 *
 * @package ExtraChillCommunity
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function extrachill_community_render_settings_content() {
	if ( ! is_page( 'settings' ) ) {
		return;
	}

	if ( ! is_user_logged_in() ) {
		auth_redirect();
		return;
	}

	echo render_block( array( 'blockName' => 'extrachill/user-settings' ) );
}
add_action( 'extrachill_after_page_content', 'extrachill_community_render_settings_content', 5 );
