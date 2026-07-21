<?php
/**
 * ec_doc → Hierarchical Page Migration
 *
 * One-shot WP-CLI command that converts the existing ec_doc posts on
 * docs.extrachill.com into hierarchical WordPress pages using the same
 * upsert-doc-page ability the sync uses. This guarantees migrated pages
 * are shape-identical to sync-produced pages — same meta keys, same
 * parent-child relationships, same URL structure — so #39 can later
 * remove the ec_doc CPT without orphaning any data.
 *
 * Algorithm:
 *
 *   For each ec_doc post:
 *     1. Resolve its ec_doc_platform term, look up the matching
 *        platform-map.yml entry to get the destination plugin repo +
 *        parent slug + parent title.
 *     2. Synthesize _source_path = "docs/user/<post_name>.md" so the
 *        migrated page sits in the coordinate space that future sync
 *        runs will eventually write to. When the real markdown file
 *        appears in the source repo, sync sees the existing page and
 *        updates content instead of duplicating.
 *     3. Preserve the existing Gutenberg block content directly while
 *        creating the same source-coordinate metadata shape as the sync
 *        ability.
 *     4. Set _migrated_from_ec_doc = <ec_doc_post_id> on the new page
 *        as an audit breadcrumb and the idempotency key for re-runs.
 *     5. Move the source ec_doc post to "private" status (not trashed)
 *        as a safety net until #39 removes them entirely.
 *
 * Idempotency: re-running checks for an existing page with matching
 * _migrated_from_ec_doc meta and skips if present.
 *
 * @package ExtraChillDocs
 * @since   0.5.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hand-curated mapping: ec_doc_platform term slug → destination plugin repo.
 *
 * The taxonomy slug naming aligns with platform-map.yml parent_slugs by
 * design (artist-platform, community, events-calendar, chat). This map
 * just resolves each term to its owning plugin repo.
 *
 * Kept inline rather than read from platform-map.yml because the
 * ec_doc_platform terms are an OLD model; only these four matter, and
 * they will be deleted in #39. The platform-map is the FUTURE model for
 * which repos sync into which parents.
 *
 * @since 0.5.0
 * @return array<string,string> Term slug => OWNER/REPO.
 */
function extrachill_docs_migration_term_to_repo_map(): array {
	return array(
		'artist-platform' => 'Extra-Chill/extrachill-artist-platform',
		'community'       => 'Extra-Chill/extrachill-community',
		'events-calendar' => 'Extra-Chill/extrachill-events',
		'chat'            => 'Extra-Chill/extrachill-roadie',
	);
}

/**
 * Find a page that was previously migrated from a given ec_doc post.
 *
 * Looks up by _migrated_from_ec_doc meta. Returns the WP_Post if
 * present, null otherwise. Used for idempotency on re-runs.
 *
 * @since 0.5.0
 *
 * @param int $ec_doc_id Source ec_doc post ID.
 * @return \WP_Post|null
 */
function extrachill_docs_migration_find_migrated_page( int $ec_doc_id ): ?\WP_Post {
	$query = new \WP_Query(
		array(
			'post_type'      => 'page',
			'post_status'    => array( 'publish', 'draft', 'private', 'pending' ),
			'posts_per_page' => 1,
			'no_found_rows'  => true,
			'meta_query'     => array(
				array(
					'key'     => '_migrated_from_ec_doc',
					'value'   => (string) $ec_doc_id,
					'compare' => '=',
				),
			),
		)
	);

	if ( empty( $query->posts ) ) {
		return null;
	}

	$post = $query->posts[0];
	return $post instanceof \WP_Post ? $post : null;
}

/**
 * Look up the platform-map entry for a given parent slug.
 *
 * @since 0.5.0
 *
 * @param string $parent_slug Slug from ec_doc_platform term (matches platform-map parent_slug).
 * @return array<string,string>|null
 */
function extrachill_docs_migration_lookup_platform( string $parent_slug ): ?array {
	if ( ! function_exists( 'extrachill_docs_load_platform_map' ) ) {
		return null;
	}

	foreach ( extrachill_docs_load_platform_map() as $entry ) {
		if ( ( $entry['parent_slug'] ?? '' ) === $parent_slug ) {
			return $entry;
		}
	}

	return null;
}

/**
 * Migrate a single ec_doc post into a hierarchical page.
 *
 * @since 0.5.0
 *
 * @param \WP_Post $ec_doc  Source ec_doc post.
 * @param bool     $dry_run When true, report planned action without writing.
 * @return array<string,mixed> Per-post outcome.
 */
function extrachill_docs_migration_migrate_one( \WP_Post $ec_doc, bool $dry_run ): array {
	$row = array(
		'ec_doc_id'    => (int) $ec_doc->ID,
		'ec_doc_title' => (string) $ec_doc->post_title,
		'ec_doc_slug'  => (string) $ec_doc->post_name,
		'action'       => '',
		'page_id'      => 0,
		'permalink'    => '',
		'error'        => '',
	);

	// Idempotency: skip if already migrated.
	$existing = extrachill_docs_migration_find_migrated_page( (int) $ec_doc->ID );
	if ( $existing ) {
		$row['action']    = 'already_migrated';
		$row['page_id']   = (int) $existing->ID;
		$row['permalink'] = $dry_run ? '' : (string) get_permalink( $existing->ID );
		return $row;
	}

	// Resolve platform term → owning repo.
	$terms = wp_get_post_terms( (int) $ec_doc->ID, 'ec_doc_platform' );
	if ( is_wp_error( $terms ) || empty( $terms ) ) {
		$row['action'] = 'error';
		$row['error']  = 'no ec_doc_platform term assigned';
		return $row;
	}

	$term         = $terms[0];
	$parent_slug  = (string) $term->slug;
	$repo_map     = extrachill_docs_migration_term_to_repo_map();
	$source_repo  = $repo_map[ $parent_slug ] ?? '';

	if ( '' === $source_repo ) {
		$row['action'] = 'error';
		$row['error']  = sprintf( 'no repo mapping for ec_doc_platform term "%s"', $parent_slug );
		return $row;
	}

	$platform = extrachill_docs_migration_lookup_platform( $parent_slug );
	if ( null === $platform ) {
		$row['action'] = 'error';
		$row['error']  = sprintf( 'no platform-map entry for parent_slug "%s"', $parent_slug );
		return $row;
	}

	$parent_title = (string) $platform['parent_title'];

	// Synthesize the source path so the page lives in the future sync coordinate space.
	// Strip the legacy collision suffix (e.g. getting-started-2 → getting-started) since
	// each parent has its own namespace and collisions are no longer possible.
	$synthetic_slug = preg_replace( '/-\d+$/', '', (string) $ec_doc->post_name );
	$synthetic_path = 'docs/user/' . $synthetic_slug . '.md';

	if ( $dry_run ) {
		$row['action']  = 'would_migrate';
		$row['page_id'] = 0;
		$row['error']   = sprintf( 'would create page under "%s" with _source_repo=%s, _source_path=%s', $parent_title, $source_repo, $synthetic_path );
		return $row;
	}

	// The existing post_content is already Gutenberg blocks. To produce a
	// page with that exact content, we bypass the upsert-doc-page ability's
	// markdown-to-blocks conversion and write directly — but still preserve
	// the same shape (meta keys, parent resolution) the sync ability would
	// produce. This is the one case where the migration cannot reuse the
	// ability verbatim because the source data is already in the target
	// format.

	// Resolve / create parent page (reuses ability's helper).
	if ( ! function_exists( 'extrachill_docs_resolve_or_create_parent_page' ) ) {
		$row['action'] = 'error';
		$row['error']  = 'extrachill_docs_resolve_or_create_parent_page() not available (upsert-doc-page ability not loaded?)';
		return $row;
	}

	$parent_id = extrachill_docs_resolve_or_create_parent_page( $parent_slug, $parent_title, false );
	if ( is_wp_error( $parent_id ) ) {
		$row['action'] = 'error';
		$row['error']  = sprintf( 'parent page resolution failed: %s', $parent_id->get_error_message() );
		return $row;
	}

	$page_id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => (string) $ec_doc->post_status,
			'post_title'   => (string) $ec_doc->post_title,
			'post_name'    => $synthetic_slug,
			'post_content' => (string) $ec_doc->post_content,
			'post_parent'  => (int) $parent_id,
			'post_excerpt' => (string) $ec_doc->post_excerpt,
		),
		true
	);

	if ( is_wp_error( $page_id ) ) {
		$row['action'] = 'error';
		$row['error']  = sprintf( 'wp_insert_post failed: %s', $page_id->get_error_message() );
		return $row;
	}

	$page_id = (int) $page_id;

	update_post_meta( $page_id, '_source_repo', $source_repo );
	update_post_meta( $page_id, '_source_path', $synthetic_path );
	update_post_meta( $page_id, '_migrated_from_ec_doc', (string) $ec_doc->ID );

	// Preserve featured image if present.
	$thumb_id = (int) get_post_thumbnail_id( (int) $ec_doc->ID );
	if ( $thumb_id > 0 ) {
		set_post_thumbnail( $page_id, $thumb_id );
	}

	// Safety net: move source ec_doc to private (not trashed, not deleted).
	wp_update_post(
		array(
			'ID'          => (int) $ec_doc->ID,
			'post_status' => 'private',
		),
		true
	);

	$row['action']    = 'migrated';
	$row['page_id']   = $page_id;
	$row['permalink'] = (string) get_permalink( $page_id );

	return $row;
}

/**
 * Migrate all (or one platform's worth of) ec_doc posts.
 *
 * @since 0.5.0
 *
 * @param bool        $dry_run        Report planned actions without writing.
 * @param string|null $only_platform  Optional: only migrate posts in this ec_doc_platform term slug.
 * @return array<string,mixed> Summary.
 */
function extrachill_docs_migration_run( bool $dry_run = false, ?string $only_platform = null ): array {
	$query_args = array(
		'post_type'      => 'ec_doc',
		'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
		'posts_per_page' => -1,
		'no_found_rows'  => true,
		'orderby'        => 'ID',
		'order'          => 'ASC',
	);

	if ( null !== $only_platform ) {
		$query_args['tax_query'] = array(
			array(
				'taxonomy' => 'ec_doc_platform',
				'field'    => 'slug',
				'terms'    => $only_platform,
			),
		);
	}

	$posts = get_posts( $query_args );

	$summary = array(
		'started_at'    => gmdate( 'c' ),
		'dry_run'       => $dry_run,
		'only_platform' => $only_platform,
		'total'         => count( $posts ),
		'rows'          => array(),
	);

	foreach ( $posts as $post ) {
		if ( $post instanceof \WP_Post ) {
			$summary['rows'][] = extrachill_docs_migration_migrate_one( $post, $dry_run );
		}
	}

	$summary['finished_at'] = gmdate( 'c' );

	return $summary;
}

// ---------------------------------------------------------------------------
// WP-CLI command.
// ---------------------------------------------------------------------------

if ( defined( 'WP_CLI' ) && WP_CLI ) {

	/**
	 * Migrate ec_doc posts to hierarchical pages.
	 *
	 * One-shot operation: each ec_doc post becomes a child page under the
	 * parent corresponding to its ec_doc_platform term. The source ec_doc
	 * post is moved to private (not deleted) as a safety net.
	 *
	 * Idempotent: re-running skips posts that have already been migrated
	 * (detected via _migrated_from_ec_doc meta on the destination page).
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report planned migrations without writing.
	 *
	 * [--platform=<slug>]
	 * : Only migrate ec_docs in this ec_doc_platform term (e.g. chat, community).
	 *
	 * [--format=<format>]
	 * : Output format (table|json|yaml). Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *   wp extrachill docs migrate-ec-docs --dry-run
	 *   wp extrachill docs migrate-ec-docs --platform=chat
	 *   wp extrachill docs migrate-ec-docs
	 *
	 * @when after_wp_load
	 *
	 * @param array<int,string>    $args       Positional args (unused).
	 * @param array<string,string> $assoc_args Options.
	 */
	$migration_cli = function ( array $args, array $assoc_args ): void {
		$dry_run       = isset( $assoc_args['dry-run'] );
		$only_platform = isset( $assoc_args['platform'] ) ? (string) $assoc_args['platform'] : null;
		$format        = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';

		$summary = extrachill_docs_migration_run( $dry_run, $only_platform );

		if ( 'json' === $format ) {
			\WP_CLI::print_value( $summary, array( 'format' => 'json' ) );
			return;
		}
		if ( 'yaml' === $format ) {
			\WP_CLI::print_value( $summary, array( 'format' => 'yaml' ) );
			return;
		}

		if ( empty( $summary['rows'] ) ) {
			\WP_CLI::log( 'No ec_doc posts to migrate.' );
			return;
		}

		\WP_CLI\Utils\format_items(
			'table',
			$summary['rows'],
			array( 'ec_doc_id', 'ec_doc_slug', 'action', 'page_id', 'error' )
		);

		// Summary counts.
		$counts = array_count_values( array_column( $summary['rows'], 'action' ) );
		$parts  = array();
		foreach ( $counts as $action => $count ) {
			$parts[] = sprintf( '%s: %d', $action, $count );
		}

		\WP_CLI::success(
			sprintf(
				'Migration %s — %d total (%s)',
				$dry_run ? 'dry-run complete' : 'complete',
				(int) $summary['total'],
				implode( ', ', $parts )
			)
		);

		// Persist log file in non-dry-run real runs.
		if ( ! $dry_run ) {
			$uploads = wp_upload_dir();
			if ( empty( $uploads['error'] ) ) {
				$log_path = trailingslashit( $uploads['basedir'] ) . sprintf( 'extrachill-docs-migration-%s.log', gmdate( 'Ymd-His' ) );
				file_put_contents( $log_path, wp_json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
				\WP_CLI::log( sprintf( 'Migration log written to: %s', $log_path ) );
			}
		}
	};

	\WP_CLI::add_command( 'extrachill docs migrate-ec-docs', $migration_cli );
}
