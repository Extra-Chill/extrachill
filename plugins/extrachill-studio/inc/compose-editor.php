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

			if ( ! current_user_can( 'manage_options' ) && function_exists( 'ec_is_team_member' ) && ! ec_is_team_member() ) {
				return false;
			}

			return true;
		},
		'editor_setup' => function ( $engine ) {
			// Add .gutenberg-support body class so BE's scoped toolbar/component
			// dark mode styles in style-index.min.css apply.
			add_filter( 'body_class', [ $engine, 'body_class' ] );

			// The IBE renders inline (shouldIframe=false), not in an iframe.
			// Theme root.css variables are available on the host page, but
			// the editor wrapper needs explicit mapping for dark mode and
			// proper sizing.
			add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\enqueue_editor_inline_styles', 50 );
		},
	];

	return $contexts;
}
add_filter( 'blocks_everywhere_contexts', __NAMESPACE__ . '\\register_compose_context' );

/**
 * Enqueue inline styles for the compose editor.
 *
 * The IBE renders inline (shouldIframe=false), so host-page CSS
 * variables from root.css are available. We add editor-specific
 * styles for proper sizing, dark mode background, and WP component
 * variable mapping inside .editor-styles-wrapper.
 */
function enqueue_editor_inline_styles() {
	$css = <<<'CSS'
/* Studio compose editor — inline editor theming.
   The parent .ec-studio-compose-editor (style.css) provides the outer
   border and radius. The iso-editor sits flush inside it. */
.ec-studio-compose-editor .iso-editor {
	min-height: 400px;
}

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

/* Toolbar theming */
.ec-studio-compose-editor .interface-interface-skeleton__header {
	background-color: var(--card-background);
	border-bottom: 1px solid var(--border-color);
}

/* Placeholder styling */
.ec-studio-compose-editor .block-editor-default-block-appender__content {
	color: var(--muted-text);
}

/* Footer */
.ec-studio-compose-editor .interface-interface-skeleton__footer {
	background-color: var(--card-background);
	border-top: 1px solid var(--border-color);
	font-size: var(--font-size-sm);
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

	// Show the block inserter in the shared sidebar mode, but detach the
	// rendered sidebar into Studio's dedicated compose sidebar slot.
	$settings['iso']['sidebar']['inserter'] = true;
	$settings['iso']['sidebar']['detached'] = array(
		'target'    => '.ec-studio-compose-sidebar__slot',
		'className' => 'ec-studio-compose-sidebar__content',
		'persistent' => true,
		'defaultView' => 'inserter',
	);

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

	// Provide MIME types so the Media tab in the inserter can show
	// existing uploads filtered by type (images, video, audio).
	$settings['editor']['allowedMimeTypes'] = get_allowed_mime_types();

	// Provide Studio-specific REST endpoints.
	$settings['studio'] = array(
		'postsEndpoint' => rest_url( 'wp/v2/posts' ),
		'mediaEndpoint' => rest_url( 'extrachill/v1/media' ),
	);

	// Expose IBE's More Menu so writers can toggle fullscreen mode and
	// keyboard shortcuts. Default to non-fullscreen on boot — users opt in
	// via the menu (or Ctrl/Cmd+Shift+Alt+F). Fullscreen-state CSS lives
	// in style.css under html.is-fullscreen-mode and hides Studio chrome
	// that sits outside the editor skeleton (title input, toolbar, save
	// actions, detached sidebar). See Phase 1 scoping in MEMORY.md.
	$settings['iso']['moreMenu']                                = array(
		'fullscreen' => true,
	);
	$settings['iso']['defaultPreferences']                      = isset( $settings['iso']['defaultPreferences'] ) && is_array( $settings['iso']['defaultPreferences'] )
		? $settings['iso']['defaultPreferences']
		: array();
	$settings['iso']['defaultPreferences']['fullscreenMode']    = false;

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
