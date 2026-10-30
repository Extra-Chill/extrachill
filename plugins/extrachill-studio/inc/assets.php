<?php
/**
 * Asset registration helpers.
 *
 * @package ExtraChillStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check whether the Studio compose editor needs to load.
 *
 * Shared condition used by both the editor enqueue and jQuery filter.
 *
 * @return bool
 * @since 0.2.7
 */
function extrachill_studio_compose_editor_is_active() {
	if ( ! is_front_page() && ! is_home() ) {
		return false;
	}

	if ( ! is_user_logged_in() ) {
		return false;
	}

	if ( ! current_user_can( 'manage_options' ) && function_exists( 'ec_is_team_member' ) && ! ec_is_team_member() ) {
		return false;
	}

	if ( ! class_exists( 'Automattic\\Blocks_Everywhere\\Engine' ) ) {
		return false;
	}

	return true;
}

/**
 * Enqueue Gutenberg editor dependencies for the Compose tab.
 *
 * Blocks Everywhere needs the full Gutenberg stack on the frontend.
 * These must be enqueued during wp_enqueue_scripts so they load in
 * wp_head — if deferred until load_editor() fires on the wp hook,
 * the header-group deps miss the wp_head output window.
 *
 * Mirrors the pattern used by extrachill-community for bbPress editing.
 *
 * @return void
 * @since 0.2.5
 */
function extrachill_studio_enqueue_editor_dependencies() {
	if ( ! extrachill_studio_compose_editor_is_active() ) {
		return;
	}

	wp_enqueue_editor();
}
add_action( 'wp_enqueue_scripts', 'extrachill_studio_enqueue_editor_dependencies', 110 );

/**
 * Prevent jQuery from being dequeued when the compose editor is active.
 *
 * The theme dequeues jQuery on the frontend for performance. Blocks
 * Everywhere and wp_enqueue_editor() both depend on jQuery, so we
 * must keep it when the compose editor loads.
 *
 * @param bool $should_dequeue Whether jQuery should be dequeued.
 * @return bool
 * @since 0.2.7
 */
function extrachill_studio_filter_dequeue_jquery( $should_dequeue ) {
	if ( extrachill_studio_compose_editor_is_active() ) {
		return false;
	}

	return $should_dequeue;
}
add_filter( 'extrachill_dequeue_jquery_frontend', 'extrachill_studio_filter_dequeue_jquery' );
