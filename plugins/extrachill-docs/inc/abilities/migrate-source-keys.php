<?php
/** Idempotent source-key adoption and legacy URL retirement. Never creates pages. */
defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_docs_register_migrate_source_keys' );
function extrachill_docs_register_migrate_source_keys(): void {
	if ( ! function_exists( 'wp_register_ability' ) ) { return; }
	wp_register_ability( 'extrachill-docs/migrate-source-keys', array(
		'label' => 'Migrate documentation source keys',
		'description' => 'Plan or apply monorepo keys and legacy redirects. Dry-run by default.',
		'category' => 'extrachill-docs',
		'input_schema' => array( 'type' => 'object', 'properties' => array( 'apply' => array( 'type' => 'boolean', 'default' => false ) ) ),
		'output_schema' => array( 'type' => 'object', 'properties' => array( 'success' => array( 'type' => 'boolean' ), 'rows' => array( 'type' => 'array' ) ) ),
		'permission_callback' => 'extrachill_docs_upsert_doc_page_permission_callback',
		'execute_callback' => 'extrachill_docs_execute_migrate_source_keys',
	) );
}

/** Translate only known legacy identities; unknown keys are left alone. */
function extrachill_docs_monorepo_source_path( string $repo, string $path, array $map ): string {
	foreach ( $map as $entry ) {
		$component = $entry['repo'];
		if ( $repo === 'Extra-Chill/' . basename( $component ) ) {
			if ( 'plugins/extrachill-docs' === $component && 'ec_docs/mcp/connect.md' === $path ) {
				return $component . '/docs/user/connect.md';
			}
			return $component . '/' . ltrim( $path, '/' );
		}
	}
	return $path;
}

/** Read retired posts and taxonomy relationships directly: neither is registered. */
function extrachill_docs_legacy_source_posts(): array {
	global $wpdb;
	return $wpdb->get_results( "SELECT p.ID, p.post_name, t.slug AS platform FROM {$wpdb->posts} p LEFT JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID LEFT JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'ec_doc_platform' LEFT JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE p.post_type = 'ec_doc' AND p.post_status NOT IN ('trash', 'auto-draft') ORDER BY p.ID" );
}

/** Trash without requiring a registered post type (and never permanently delete). */
function extrachill_docs_trash_legacy_post( int $id ): bool {
	global $wpdb;
	$post = get_post( $id );
	if ( ! $post || 'ec_doc' !== $post->post_type || 'trash' === $post->post_status ) { return false; }
	update_post_meta( $id, '_wp_trash_meta_status', $post->post_status );
	update_post_meta( $id, '_wp_trash_meta_time', time() );
	$changed = $wpdb->update( $wpdb->posts, array( 'post_status' => 'trash' ), array( 'ID' => $id, 'post_type' => 'ec_doc', 'post_status' => $post->post_status ), array( '%s' ), array( '%d', '%s', '%s' ) );
	clean_post_cache( $id );
	return 1 === $changed;
}

function extrachill_docs_execute_migrate_source_keys( array $input ): array {
	$apply = ! empty( $input['apply'] );
	$map = extrachill_docs_load_platform_map();
	$rows = array();
	$pages = get_posts( array( 'post_type' => 'page', 'post_status' => array( 'publish', 'private', 'draft', 'pending' ), 'posts_per_page' => -1 ) );
	$keys = array();
	foreach ( $pages as $page ) {
		$repo = (string) get_post_meta( $page->ID, '_source_repo', true );
		$old = (string) get_post_meta( $page->ID, '_source_path', true );
		$path = extrachill_docs_monorepo_source_path( $repo, $old, $map );
		if ( '' === $old && '' === $repo ) {
			foreach ( extrachill_docs_plan_plugin_docs( $map, WP_PLUGIN_DIR ) as $source ) {
				if ( 'publish' !== $source['action'] ) { continue; }
				$parent = get_page_by_path( $source['parent_slug'], OBJECT, 'page' );
				if ( $parent && (int) $page->post_parent === (int) $parent->ID && $page->post_name === extrachill_docs_derive_slug_from_path( $source['path'] ) ) {
					$path = $source['path'];
					break;
				}
			}
		}
		if ( '' !== $path ) { $keys[ $path ][] = array( $page, $repo, $old ); }
	}
	foreach ( $keys as $path => $owners ) {
		if ( count( $owners ) > 1 ) {
			$rows[] = array( 'action' => 'conflict', 'path' => $path, 'page_ids' => array_map( static fn( $owner ) => $owner[0]->ID, $owners ) );
			continue;
		}
		list( $page, $repo, $old ) = $owners[0];
		if ( ! str_starts_with( $path, 'plugins/' ) || ( $old === $path && 'Extra-Chill/extrachill' === $repo ) ) { continue; }
		$rows[] = array( 'action' => '' === $old ? 'adopt' : 'rekey', 'page_id' => $page->ID, 'path' => $path );
		if ( $apply ) {
			update_post_meta( $page->ID, '_source_repo', 'Extra-Chill/extrachill' );
			update_post_meta( $page->ID, '_source_path', $path );
		}
	}
	$stored = get_option( EXTRACHILL_DOCS_PUBLISHED_FINGERPRINTS_OPTION, array() );
	$next = $stored;
	foreach ( $map as $entry ) {
		$legacy = 'Extra-Chill/' . basename( $entry['repo'] );
		if ( isset( $next[ $legacy ] ) ) {
			$next[ $entry['repo'] ] = $next[ $entry['repo'] ] ?? $next[ $legacy ];
			unset( $next[ $legacy ] );
			$rows[] = array( 'action' => 'rekey_fingerprint', 'path' => $entry['repo'] );
		}
	}
	if ( $apply && $next !== $stored ) { update_option( EXTRACHILL_DOCS_PUBLISHED_FINGERPRINTS_OPTION, $next, false ); }
	$redirects = get_option( 'extrachill_docs_legacy_redirects', array() );
	foreach ( extrachill_docs_legacy_source_posts() as $legacy ) {
		$target = null;
		foreach ( $map as $entry ) {
			if ( $entry['parent_slug'] !== $legacy->platform ) { continue; }
			$parent = get_page_by_path( $entry['parent_slug'], OBJECT, 'page' );
			foreach ( $pages as $page ) {
				if ( $parent && (int) $page->post_parent === (int) $parent->ID && $page->post_name === $legacy->post_name ) { $target = $page; break; }
			}
		}
		$path = '/' . $legacy->platform . '/' . $legacy->post_name . '/';
		$rows[] = array( 'action' => $target ? 'redirect_and_trash' : 'unmatched_legacy', 'post_id' => $legacy->ID, 'page_id' => $target ? $target->ID : 0, 'path' => $path );
		if ( $apply && $target ) {
			$redirects[ $path ] = (int) $target->ID;
			update_option( 'extrachill_docs_legacy_redirects', $redirects, false );
			// Persist routing before changing the source status.
			if ( (int) ( get_option( 'extrachill_docs_legacy_redirects', array() )[ $path ] ?? 0 ) === (int) $target->ID ) {
				if ( ! extrachill_docs_trash_legacy_post( (int) $legacy->ID ) ) { $rows[] = array( 'action' => 'trash_failed', 'post_id' => $legacy->ID ); }
			}
		}
	}
	if ( $apply ) { flush_rewrite_rules(); }
	$errors = array_intersect( array_column( $rows, 'action' ), array( 'conflict', 'trash_failed' ) );
	return array( 'success' => ! $errors, 'dry_run' => ! $apply, 'rows' => $rows );
}
