<?php
/** Shared docs permissions, hierarchy and source-link helpers. */
defined( 'ABSPATH' ) || exit;

function extrachill_docs_upsert_doc_page_permission_callback(): bool {
	// Cron context: no current user, all checks pass.
	if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
		return true;
	}

	// CLI context: trust the operator.
	if ( defined( 'WP_CLI' ) ) {
		return true;
	}

	return function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
}

function extrachill_docs_resolve_internal_markdown_links( string $markdown, string $parent_slug ): string {
	return (string) preg_replace_callback(
		'/(?<!!)\[([^\]]+)\]\((?![a-z][a-z0-9+.-]*:|\/|#)([^)\s]+\.md)(#[^)\s]+)?\)/i',
		static function ( array $matches ) use ( $parent_slug ): string {
			$target_slug = sanitize_title( pathinfo( $matches[2], PATHINFO_FILENAME ) );
			if ( '' === $target_slug ) {
				return $matches[0];
			}

			$url = home_url( '/' . trim( $parent_slug, '/' ) . '/' . $target_slug . '/' );
			if ( ! empty( $matches[3] ) ) {
				$url .= $matches[3];
			}

			return '[' . $matches[1] . '](' . $url . ')';
		},
		$markdown
	);
}

function extrachill_docs_resolve_or_create_parent_page( string $slug, string $title, string $post_status, bool $dry_run ) {
	$existing = get_page_by_path( $slug, OBJECT, 'page' );
	if ( $existing instanceof \WP_Post ) {
		$is_managed_parent = '1' === (string) get_post_meta( $existing->ID, '_extrachill_docs_platform_parent', true );
		if ( ! $dry_run && $is_managed_parent && $post_status !== $existing->post_status ) {
			$updated = wp_update_post(
				array(
					'ID'          => $existing->ID,
					'post_status' => $post_status,
				),
				true
			);
			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
		}
		return (int) $existing->ID;
	}

	if ( $dry_run ) {
		return 0;
	}

	$parent_id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => $post_status,
			'post_title'   => $title,
			'post_name'    => $slug,
			'post_content' => '',
			'post_parent'  => 0,
		),
		true
	);

	if ( is_wp_error( $parent_id ) ) {
		return $parent_id;
	}

	update_post_meta( (int) $parent_id, '_extrachill_docs_platform_parent', '1' );

	return (int) $parent_id;
}

function extrachill_docs_derive_slug_from_path( string $path ): string {
	$basename = basename( $path );
	$basename = preg_replace( '/\.md$/i', '', $basename );
	return sanitize_title( (string) $basename );
}
