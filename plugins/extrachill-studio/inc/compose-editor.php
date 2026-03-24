<?php
/**
 * Compose Editor — Blocks Everywhere integration for Studio.
 *
 * Registers a Studio context via the blocks_everywhere_contexts filter
 * so the isolated block editor loads on the Studio homepage for team
 * members. The editor mounts inside the Compose tab of the Studio React app.
 *
 * @package ExtraChillStudio
 * @since   0.2.0
 */

namespace ExtraChillStudio;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the Studio compose editor as a Blocks Everywhere context.
 *
 * @param array $contexts Registered contexts.
 * @return array
 */
function register_compose_context( array $contexts ): array {
	$contexts['studio'] = [
		'type'         => 'studio',
		'textarea'     => '#ec-studio-compose-content',
		'container'    => '.ec-studio-compose-editor',
		'trigger'      => 'wp',
		'condition'    => function () {
			if ( ! is_front_page() && ! is_home() ) {
				return false;
			}

			if ( ! is_user_logged_in() ) {
				return false;
			}

			if ( function_exists( 'ec_is_team_member' ) && ! ec_is_team_member() ) {
				return false;
			}

			return true;
		},
		'editor_setup' => function () {
			// Inject theme CSS into the editor iframe so dark mode
			// and EC design tokens apply inside the editing canvas.
			add_action( 'blocks_everywhere_enqueue_iframe_assets', __NAMESPACE__ . '\\enqueue_iframe_assets' );
		},
	];

	return $contexts;
}
add_filter( 'blocks_everywhere_contexts', __NAMESPACE__ . '\\register_compose_context' );

/**
 * Enqueue theme styles into the Blocks Everywhere editor iframe.
 *
 * The editor runs in an iframe so page-level CSS doesn't reach it.
 * We inject root.css (design tokens + dark mode) and style.css
 * (theme globals) so the editing experience matches the site.
 */
function enqueue_iframe_assets() {
	$theme_dir = get_template_directory();
	$theme_url = get_template_directory_uri();

	// Design tokens + dark mode variables.
	$root_path = $theme_dir . '/assets/css/root.css';
	if ( file_exists( $root_path ) ) {
		if ( ! wp_style_is( 'extrachill-root', 'registered' ) ) {
			wp_register_style(
				'extrachill-root',
				$theme_url . '/assets/css/root.css',
				array(),
				filemtime( $root_path )
			);
		}
		wp_enqueue_style( 'extrachill-root' );
	}

	// Theme global styles.
	$style_path = $theme_dir . '/style.css';
	if ( file_exists( $style_path ) ) {
		if ( ! wp_style_is( 'extrachill-style', 'registered' ) ) {
			wp_register_style(
				'extrachill-style',
				get_stylesheet_uri(),
				array( 'extrachill-root' ),
				filemtime( $style_path )
			);
		}
		wp_enqueue_style( 'extrachill-style' );
	}
}

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
