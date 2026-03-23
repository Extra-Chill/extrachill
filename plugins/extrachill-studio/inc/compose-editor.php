<?php
/**
 * Compose Editor — Blocks Everywhere integration for Studio.
 *
 * Loads the isolated block editor on the Studio homepage so team
 * members can compose posts using the full Gutenberg experience.
 * The editor mounts inside the Compose tab of the Studio React app.
 *
 * @package ExtraChillStudio
 * @since   0.2.0
 */

namespace ExtraChillStudio;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Load Blocks Everywhere editor assets on the Studio homepage.
 *
 * Uses the generic Frontend handler from Blocks Everywhere (our fork)
 * to load the isolated block editor. The handler's load_editor() method
 * handles all Gutenberg asset loading, REST API setup, and editor
 * initialization. The JS side uses window.blocksEverywhereCreateEditor
 * to mount the editor when the Compose tab renders.
 */
function load_compose_editor() {
	// Only on the front page of the studio site.
	if ( ! is_front_page() && ! is_home() ) {
		return;
	}

	// Require login and team membership.
	if ( ! is_user_logged_in() ) {
		return;
	}

	if ( function_exists( 'ec_is_team_member' ) && ! ec_is_team_member() ) {
		return;
	}

	// Blocks Everywhere must be active with the Frontend handler.
	if ( ! class_exists( 'Automattic\\Blocks_Everywhere\\Handler\\Frontend' ) ) {
		return;
	}

	$handler = new \Automattic\Blocks_Everywhere\Handler\Frontend();

	// Load the editor targeting our compose textarea.
	$handler->load_editor(
		'#ec-studio-compose-content',
		'.ec-studio-compose-editor'
	);
}
add_action( 'wp', __NAMESPACE__ . '\\load_compose_editor' );

/**
 * Configure the block editor for Studio's compose context.
 *
 * @param array $settings Editor settings.
 * @return array Modified settings.
 */
function configure_compose_editor( array $settings ): array {
	// Set editor type so JS can identify Studio context.
	$settings['editorType'] = 'studio';

	// Configure allowed blocks — writing-focused.
	$settings['iso']['blocks']['allowBlocks'] = apply_filters(
		'extrachill_studio_allowed_blocks',
		array(
			'core/paragraph',
			'core/heading',
			'core/image',
			'core/gallery',
			'core/list',
			'core/list-item',
			'core/quote',
			'core/separator',
			'core/embed',
		)
	);

	// Show the block inserter for a richer editing experience.
	$settings['iso']['sidebar']['inserter'] = true;

	// Allow common embed types.
	$settings['iso']['allowEmbeds'] = array(
		'youtube',
		'vimeo',
		'spotify',
		'soundcloud',
		'twitter',
		'instagram',
	);

	// Upload permissions for logged-in team members.
	$settings['editor']['hasUploadPermissions'] = current_user_can( 'upload_files' );

	// Provide Studio-specific REST endpoints.
	$settings['studio'] = array(
		'postsEndpoint' => rest_url( 'wp/v2/posts' ),
		'mediaEndpoint' => rest_url( 'extrachill/v1/media' ),
	);

	return $settings;
}
add_filter( 'blocks_everywhere_editor_settings', __NAMESPACE__ . '\\configure_compose_editor', 30 );

/**
 * Override allowed blocks for Studio context.
 *
 * The blocks_everywhere_allowed_blocks filter runs before our settings
 * filter, so we add our blocks here to ensure they're in the allowlist.
 *
 * @param array $blocks Allowed blocks.
 * @return array
 */
function allowed_blocks_for_studio( array $blocks ): array {
	return array_unique( array_merge( $blocks, array(
		'core/heading',
		'core/image',
		'core/gallery',
		'core/embed',
		'core/list',
		'core/list-item',
		'core/quote',
		'core/separator',
	) ) );
}
add_filter( 'blocks_everywhere_allowed_blocks', __NAMESPACE__ . '\\allowed_blocks_for_studio' );
