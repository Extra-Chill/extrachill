<?php
/** MDI adapter. Installed source directories are import-only; never export here. */
defined( 'ABSPATH' ) || exit;

const EXTRACHILL_DOCS_MDI_LAYOUT = 'extrachill-plugin-docs';

/** Resolve a canonical identity against the platform map, not arbitrary paths. */
function extrachill_docs_mdi_source( string $identity ): ?array {
	foreach ( extrachill_docs_load_platform_map() as $entry ) {
		$prefix = $entry['repo'] . '/' . trim( $entry['docs_subpath'] ?: 'docs/user', '/' ) . '/';
		if ( str_starts_with( $identity, $prefix ) && preg_match( '/^[a-z0-9-]+\.md$/', substr( $identity, strlen( $prefix ) ) ) ) {
			return $entry;
		}
	}
	return null;
}

function extrachill_docs_register_mdi_layout(): void {
	if ( ! function_exists( 'markdown_db_register_content_layout_profile' ) ) {
		return;
	}
	markdown_db_register_content_layout_profile( EXTRACHILL_DOCS_MDI_LAYOUT, array(
		'enumerate' => static function ( string $root ): array {
			$paths = array();
			foreach ( extrachill_docs_plan_plugin_docs( extrachill_docs_load_platform_map(), $root, $GLOBALS['extrachill_docs_mdi_repo'] ?? '' ) as $row ) {
				if ( 'publish' === $row['action'] ) {
					$paths[] = substr( $row['path'], strlen( 'plugins/' ) );
				}
			}
			return $paths;
		},
		'map_source' => static function ( string $path ): array {
			$entry = extrachill_docs_mdi_source( 'plugins/' . $path );
			if ( ! $entry ) {
				return array();
			}
			$parent = get_page_by_path( $entry['parent_slug'], OBJECT, 'page' );
			return array(
				'post_type' => 'page',
				'post_name' => extrachill_docs_derive_slug_from_path( $path ),
				'post_parent' => $parent ? (int) $parent->ID : 0,
				'source_identity' => 'plugins/' . $path,
			);
		},
		'path_for_post' => static function ( object $post ): string {
			$identity = (string) get_post_meta( $post->ID, '_markdown_source_identity', true );
			return extrachill_docs_mdi_source( $identity ) ? substr( $identity, strlen( 'plugins/' ) ) : '';
		},
	) );
}
add_action( 'init', 'extrachill_docs_register_mdi_layout', 20 );

/** MDI v0.15 has no layout ID in context; scope to our layout import. */
function extrachill_docs_is_mdi_source( object $source ): bool {
	return ! empty( $GLOBALS['extrachill_docs_mdi_importing'] );
}

function extrachill_docs_mdi_post_data( array $data, array $context, object $source ): array {
	if ( ! extrachill_docs_is_mdi_source( $source ) ) {
		return $data;
	}
	$identity = (string) ( $context['source_path'] ?? '' );
	$entry = extrachill_docs_mdi_source( $identity );
	if ( $entry ) {
		$data['post_status'] = $entry['post_status'] ?? 'publish';
		// MDI's synthetic source IDs must not remap a real platform parent ID.
		$parent = get_page_by_path( $entry['parent_slug'], OBJECT, 'page' );
		$data['post_parent'] = $parent ? (int) $parent->ID : 0;
		$data['meta_input']['_source_path'] = $identity;
		$data['meta_input']['_source_repo'] = 'Extra-Chill/extrachill';
	}
	return $data;
}
add_filter( 'markdown_db_import_post_data', 'extrachill_docs_mdi_post_data', 10, 3 );

/** Rewrite Markdown before MDI converts it to blocks (including link attributes). */
function extrachill_docs_mdi_post_content( string $content, array $context, array $data, object $source ): string {
	$entry = extrachill_docs_is_mdi_source( $source ) ? extrachill_docs_mdi_source( (string) ( $context['source_path'] ?? '' ) ) : null;
	return $entry ? extrachill_docs_resolve_internal_markdown_links( $content, $entry['parent_slug'] ) : $content;
}
add_filter( 'markdown_db_import_post_content', 'extrachill_docs_mdi_post_content', 10, 4 );
