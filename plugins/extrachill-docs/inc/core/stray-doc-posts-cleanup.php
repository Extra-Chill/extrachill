<?php
/**
 * One-shot cleanup of stray MDI posts.
 *
 * Before the publish plan and the MDI layout shared one file-name rule,
 * docs/user/README.md was enumerated but not mapped, so MDI imported it with
 * its defaults: a public `post` (e.g. docs.extrachill.com/readme) with open
 * comments that attracted spam. This deletes those posts, and with them their
 * comments, once.
 *
 * Only `post` rows whose MDI source path sits in a mapped docs directory and
 * fails the publishable file-name rule are removed. Real docs pages are
 * `page` rows and are never touched.
 *
 * @package ExtraChillDocs
 */

defined( 'ABSPATH' ) || exit;

/** Option flag recording that the stray-post cleanup ran. */
const EXTRACHILL_DOCS_STRAY_POSTS_CLEANUP_OPTION = 'extrachill_docs_stray_doc_posts_cleanup_done';

/**
 * Whether an MDI source path is an unpublishable file in a mapped docs dir.
 *
 * Accepts both the canonical `plugins/...` identity and the unprefixed path
 * MDI stored when it fell back to its defaults.
 *
 * @param string $source_path MDI source path or identity.
 */
function extrachill_docs_is_stray_doc_source_path( string $source_path ): bool {
	$source_path = ltrim( $source_path, '/' );
	if ( '' === $source_path ) {
		return false;
	}
	$identity = str_starts_with( $source_path, 'plugins/' ) ? $source_path : 'plugins/' . $source_path;

	foreach ( extrachill_docs_load_platform_map() as $entry ) {
		$subpath = trim( ! empty( $entry['docs_subpath'] ) ? $entry['docs_subpath'] : 'docs/user', '/' );
		$prefix  = $entry['repo'] . '/' . $subpath . '/';
		if ( ! str_starts_with( $identity, $prefix ) ) {
			continue;
		}
		$filename = substr( $identity, strlen( $prefix ) );
		return ! str_contains( $filename, '/' )
			&& str_ends_with( strtolower( $filename ), '.md' )
			&& ! extrachill_docs_is_publishable_doc_filename( $filename );
	}
	return false;
}

/**
 * Delete stray MDI `post` rows imported from unpublishable docs files.
 *
 * @return int[] Deleted post IDs.
 */
function extrachill_docs_delete_stray_doc_posts(): array {
	$candidates = get_posts(
		array(
			'post_type'        => 'post',
			'post_status'      => 'any',
			'posts_per_page'   => -1,
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => true,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- One-shot cleanup on a small docs site.
			'meta_query'       => array(
				'relation' => 'OR',
				array(
					'key'     => '_markdown_source_path',
					'compare' => 'EXISTS',
				),
				array(
					'key'     => '_markdown_source_identity',
					'compare' => 'EXISTS',
				),
			),
		)
	);

	$deleted = array();
	foreach ( $candidates as $post_id ) {
		$post_id = (int) $post_id;
		$paths   = array(
			(string) get_post_meta( $post_id, '_markdown_source_path', true ),
			(string) get_post_meta( $post_id, '_markdown_source_identity', true ),
		);
		foreach ( $paths as $path ) {
			if ( extrachill_docs_is_stray_doc_source_path( $path ) ) {
				// Force delete also removes the post's comments (spam included).
				if ( wp_delete_post( $post_id, true ) ) {
					$deleted[] = $post_id;
				}
				break;
			}
		}
	}
	return $deleted;
}

/**
 * Run the stray-post cleanup once.
 */
function extrachill_docs_maybe_cleanup_stray_doc_posts(): void {
	if ( '1' === (string) get_option( EXTRACHILL_DOCS_STRAY_POSTS_CLEANUP_OPTION, '' ) ) {
		return;
	}
	extrachill_docs_delete_stray_doc_posts();
	update_option( EXTRACHILL_DOCS_STRAY_POSTS_CLEANUP_OPTION, '1', false );
}
add_action( 'init', 'extrachill_docs_maybe_cleanup_stray_doc_posts', 30 );
