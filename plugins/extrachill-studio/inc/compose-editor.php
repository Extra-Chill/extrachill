<?php
/**
 * Compose Editor — Blocks Everywhere integration for Studio.
 *
 * Registers a Studio context via the blocks_everywhere_contexts filter
 * so Blocks Everywhere loads on the Studio homepage for team members.
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
 * Resolve main extrachill.com's maximum upload size in bytes.
 *
 * Compose images are written to MAIN (blog 1), so the editor's client-side
 * size check must validate against main's allowance — not Studio's. Resolved
 * inside main's blog context so PHP ini + WP filters for the destination site
 * apply. Falls back to the local limit if multisite helpers are unavailable.
 *
 * @since 0.16.0
 *
 * @return int Max upload size in bytes.
 */
function extrachill_studio_compose_main_max_upload_size(): int {
	if ( ! function_exists( 'ec_get_blog_id' ) ) {
		return (int) wp_max_upload_size();
	}

	$main_blog_id = (int) ec_get_blog_id( 'main' );
	if ( $main_blog_id <= 0 ) {
		return (int) wp_max_upload_size();
	}

	switch_to_blog( $main_blog_id );
	$max = (int) wp_max_upload_size();
	restore_current_blog();

	return $max;
}

/**
 * Register the Studio compose editor as a Blocks Everywhere context.
 *
 * @param array $contexts Registered contexts.
 * @return array
 */
function register_compose_context( array $contexts ): array {
	$contexts['studio'] = array(
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

			if ( ! current_user_can( 'manage_options' ) && function_exists( 'ec_is_team_member' ) && ! ec_is_team_member() ) {
				return false;
			}

			return true;
		},
		'editor_setup' => function ( $engine ) {
			// Add .gutenberg-support body class so BE's scoped toolbar/component
			// dark mode styles in style-index.min.css apply.
			if ( is_object( $engine ) && is_callable( array( $engine, 'body_class' ) ) ) {
				add_filter( 'body_class', array( $engine, 'body_class' ) );
			}

			// BE renders inline (shouldIframe=false), not in an iframe.
			// Theme root.css variables are available on the host page, but
			// the editor wrapper needs explicit mapping for dark mode and
			// proper sizing.
			add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\enqueue_editor_inline_styles', 50 );
		},
	);

	return $contexts;
}
add_filter( 'blocks_everywhere_contexts', __NAMESPACE__ . '\\register_compose_context' );

/**
 * Enqueue inline styles for the compose editor.
 *
 * BE renders inline (shouldIframe=false), so host-page CSS
 * variables from root.css are available. We add editor-specific
 * styles for proper sizing, dark mode background, and WP component
 * variable mapping inside .editor-styles-wrapper.
 */
function enqueue_editor_inline_styles() {
	$css = <<<'CSS'
/* Studio compose editor — inline editor theming. */
.ec-studio-compose-editor .editor-styles-wrapper {
	background-color: var(--background-color);
	color: var(--text-color);
	font-family: var(--font-family-body);
	font-size: var(--font-size-body, 1.125rem);
	line-height: 1.6;
	min-height: 350px;
	padding: var(--spacing-md);
}

.ec-studio-compose-editor .editor-styles-wrapper p {
	color: var(--text-color);
}

.ec-studio-compose-editor .editor-styles-wrapper a {
	color: var(--link-color);
}

.ec-studio-compose-editor .editor-styles-wrapper h1,
.ec-studio-compose-editor .editor-styles-wrapper h2,
.ec-studio-compose-editor .editor-styles-wrapper h3 {
	font-family: var(--font-family-heading);
	color: var(--text-color);
}

.ec-studio-compose-editor .editor-styles-wrapper blockquote {
	border-left: 3px solid var(--accent);
	padding-left: var(--spacing-md);
	color: var(--muted-text);
}

/* Placeholder styling */
.ec-studio-compose-editor .block-editor-default-block-appender__content {
	color: var(--muted-text);
}
CSS;

	wp_add_inline_style( 'extrachill-root', $css );
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
	$settings['blocksEverywhere']['blocks']['allowBlocks'] = apply_filters(
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

	// Show the block inserter in the shared sidebar mode, but detach the
	// rendered sidebar into Studio's dedicated compose sidebar slot.
	$settings['blocksEverywhere']['sidebar']['inserter'] = true;
	$settings['blocksEverywhere']['sidebar']['detached'] = array(
		'target'      => '.ec-studio-compose-sidebar__slot',
		'className'   => 'ec-studio-compose-sidebar__content',
		'persistent'  => true,
		'defaultView' => 'inserter',
	);

	// Allow common embed types.
	$settings['blocksEverywhere']['allowEmbeds'] = array(
		'youtube',
		'vimeo',
		'spotify',
		'soundcloud',
		'twitter',
		'instagram',
	);

	// Provide MIME types so the Media tab in the inserter can show
	// existing uploads filtered by type (images, video, audio).
	$settings['editor']['allowedMimeTypes'] = get_allowed_mime_types();

	// Enable the editor's image upload affordances (upload button, drag-and-
	// drop onto the canvas, the Media Library tab's upload). Blocks Everywhere
	// gates ALL of this — including whether it runs wp_enqueue_media() — on
	// editor.hasUploadPermissions, and defaults it to unset (falsy). Without
	// this flag the writer gets no working way to add an image at all, which
	// is the confusing part of the experience.
	//
	// Gate on the WP core capability. Team members have `upload_files` on both
	// Studio and main (the destination), so the editor enables uploads and the
	// cross-site media proxy's write to main succeeds under the same user's
	// caps. The proxy route enforces the capability again server-side, so this
	// flag only governs UI affordances, never trust.
	$settings['editor']['hasUploadPermissions'] = current_user_can( 'upload_files' );

	// Set the client-side upload size limit so the editor rejects oversized
	// images instantly — before any upload starts — with a clear message,
	// rather than letting them fail opaquely at the server.
	//
	// Two reasons this is set explicitly here:
	//   1. In this Blocks Everywhere frontend context, core resolves
	//      maxUploadFileSize to 0, which DISABLES client-side size validation
	//      entirely (the guard is `if ( maxUploadFileSize && ... )`). Setting a
	//      real value re-enables it.
	//   2. Compose images are written to MAIN (blog 1), so the limit that
	//      matters is main's wp_max_upload_size(), not Studio's. We resolve it
	//      in main's context so the client-side check matches the server-side
	//      enforcement in the compose media proxy route.
	$settings['maxUploadFileSize'] = (int) extrachill_studio_compose_main_max_upload_size();

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
