<?php
/**
 * Synced Page Guard
 *
 * Locks down direct WP-admin edits on pages that were created by the
 * sync ability (any page with _source_repo meta). Edits must round-trip
 * through the source repo's docs/user/*.md file so git stays canonical.
 *
 * Override capability:
 *   `override_synced_docs` — administrators can grant this cap to
 *   themselves or another role when an emergency edit is needed in WP
 *   directly. Default: granted to no role. Granting it does not change
 *   the sync behavior; the next sync run will overwrite the manual edit
 *   if the source markdown still differs.
 *
 * @package ExtraChillDocs
 * @since   0.5.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Filter map_meta_cap to block edit/delete/publish on synced pages.
 *
 * Runs for every capability check on every post. Short-circuits cheaply
 * when the requested cap is not one we care about or the post is not a
 * synced doc page.
 *
 * @since 0.5.0
 *
 * @param string[] $caps    Required primitive capabilities.
 * @param string   $cap     Meta capability being mapped.
 * @param int      $user_id Current user.
 * @param array    $args    Additional context (args[0] is usually the post ID).
 * @return string[]
 */
function extrachill_docs_guard_synced_pages( $caps, $cap, $user_id, $args ) {
	$guarded_caps = array( 'edit_post', 'delete_post', 'publish_post' );

	if ( ! in_array( $cap, $guarded_caps, true ) ) {
		return $caps;
	}

	if ( empty( $args[0] ) ) {
		return $caps;
	}

	$post_id = (int) $args[0];
	if ( $post_id <= 0 ) {
		return $caps;
	}

	$post = get_post( $post_id );
	if ( ! $post instanceof \WP_Post || 'page' !== $post->post_type ) {
		return $caps;
	}

	$source_repo = (string) get_post_meta( $post_id, '_source_repo', true );
	if ( '' === $source_repo ) {
		return $caps;
	}

	// Page is synced. Require the override capability.
	$caps[] = 'override_synced_docs';

	return $caps;
}
add_filter( 'map_meta_cap', 'extrachill_docs_guard_synced_pages', 10, 4 );

/**
 * Render an admin notice on the page editor for synced pages.
 *
 * Tells the editor where the canonical source lives and links to the
 * markdown file in GitHub. Non-actionable for users without the override
 * cap; informational for users with it.
 *
 * @since 0.5.0
 * @return void
 */
function extrachill_docs_synced_page_admin_notice(): void {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || 'page' !== $screen->id ) {
		return;
	}

	$post = get_post();
	if ( ! $post instanceof \WP_Post ) {
		return;
	}

	$source_repo = (string) get_post_meta( $post->ID, '_source_repo', true );
	$source_path = (string) get_post_meta( $post->ID, '_source_path', true );

	if ( '' === $source_repo || '' === $source_path ) {
		return;
	}

	$github_url = sprintf( 'https://github.com/%s/blob/main/%s', $source_repo, $source_path );

	// 'override_synced_docs' is a custom capability granted to docs editors; the sniff cannot see it.
	$can_override = current_user_can( 'override_synced_docs' ); // phpcs:ignore WordPress.WP.Capabilities.Unknown

	$message_class = $can_override ? 'notice-info' : 'notice-warning';
	$prefix        = $can_override
		? __( 'This page is synced from GitHub.', 'extrachill-docs' )
		: __( 'This page is synced from GitHub and cannot be edited here.', 'extrachill-docs' );

	$suffix = $can_override
		? __( 'Direct edits will be overwritten on the next sync. Edit the source markdown file in GitHub for permanent changes.', 'extrachill-docs' )
		: __( 'Edit the source markdown file in GitHub. Your change will appear here after the next sync.', 'extrachill-docs' );

	printf(
		'<div class="notice %1$s"><p><strong>%2$s</strong> %3$s</p><p><a href="%4$s" target="_blank" rel="noopener noreferrer">%5$s</a></p></div>',
		esc_attr( $message_class ),
		esc_html( $prefix ),
		esc_html( $suffix ),
		esc_url( $github_url ),
		/* translators: %s: source file path in the docs repository. */
		esc_html( sprintf( __( 'Open %s on GitHub →', 'extrachill-docs' ), $source_path ) )
	);
}
add_action( 'admin_notices', 'extrachill_docs_synced_page_admin_notice' );
