<?php
/**
 * Upsert Doc Page Ability
 *
 * Registers `extrachill-docs/upsert-doc-page` — the atomic operation that
 * converts a single markdown file into a hierarchical WordPress page on
 * docs.extrachill.com. Idempotent, keyed by post meta { _source_repo,
 * _source_path }.
 *
 * Composability: this ability is the single write path for every doc page
 * on docs.extrachill.com. The sync orchestrator (cron, WP-CLI) calls it
 * once per file. The migration script (#38) calls it once per ec_doc post.
 * Future agents in chat could call it to fix a single page without running
 * a full repo walk.
 *
 * Dependencies:
 *   - DataMachine\Core\Content\ContentFormat (provided by data-machine)
 *   - Native WordPress post functions (wp_insert_post, wp_update_post, etc.)
 *
 * @package ExtraChillDocs
 * @since   0.5.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_docs_register_upsert_doc_page_ability' );

/**
 * Register the upsert-doc-page ability with the WordPress Abilities API.
 *
 * Skipped cleanly when the Abilities API is not loaded (e.g. unit tests or
 * plugin deactivation transitions).
 *
 * @since 0.5.0
 * @return void
 */
function extrachill_docs_register_upsert_doc_page_ability(): void {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability(
		'extrachill-docs/upsert-doc-page',
		array(
			'label'               => __( 'Upsert Documentation Page', 'extrachill-docs' ),
			'description'         => __( 'Convert a markdown file into a hierarchical WordPress page on docs.extrachill.com. Idempotent: pages are keyed by { _source_repo, _source_path } meta. Creates the parent page if it does not exist.', 'extrachill-docs' ),
			'category'            => 'extrachill-docs',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'repo', 'path', 'markdown', 'parent_slug', 'parent_title' ),
				'properties' => array(
					'repo'         => array(
						'type'        => 'string',
						'description' => __( 'Source repository in owner/repo format (e.g. Extra-Chill/extrachill-artist-platform).', 'extrachill-docs' ),
					),
					'path'         => array(
						'type'        => 'string',
						'description' => __( 'Path to the markdown file inside the source repo (e.g. docs/user/getting-started.md). Used as part of the idempotency key.', 'extrachill-docs' ),
					),
					'sha'          => array(
						'type'        => 'string',
						'description' => __( 'Optional content SHA from the source repo. Stored as _source_sha post meta to short-circuit unchanged-content updates on future runs.', 'extrachill-docs' ),
					),
					'markdown'     => array(
						'type'        => 'string',
						'description' => __( 'Full markdown content of the source file. Will be converted to Gutenberg blocks through Data Machine content-format conversion.', 'extrachill-docs' ),
					),
					'parent_slug'  => array(
						'type'        => 'string',
						'description' => __( 'Slug of the parent page (e.g. artist-platform). Parent page is created if it does not exist.', 'extrachill-docs' ),
					),
					'parent_title' => array(
						'type'        => 'string',
						'description' => __( 'Title of the parent page, used when creating the parent (e.g. Artist Platform). Ignored when parent already exists.', 'extrachill-docs' ),
					),
					'dry_run'      => array(
						'type'        => 'boolean',
						'description' => __( 'If true, returns the action that would be taken without writing to the database. Default: false.', 'extrachill-docs' ),
						'default'     => false,
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'      => array( 'type' => 'boolean' ),
					'action'       => array(
						'type' => 'string',
						'enum' => array( 'created', 'updated', 'unchanged', 'would_create', 'would_update', 'would_skip' ),
					),
					'page_id'      => array( 'type' => 'integer' ),
					'parent_id'    => array( 'type' => 'integer' ),
					'permalink'    => array( 'type' => 'string' ),
					'error'        => array( 'type' => 'string' ),
					'error_detail' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => 'extrachill_docs_execute_upsert_doc_page',
			'permission_callback' => 'extrachill_docs_upsert_doc_page_permission_callback',
			'meta'                => array(
				'show_in_rest' => false,
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		)
	);
}

/**
 * Permission callback for upsert-doc-page.
 *
 * Restricts to administrators by default. The sync orchestrator runs in a
 * cron context where current_user_can() returns true for all caps (no
 * user), so cron-driven calls always succeed. Manual REST/CLI calls
 * require admin.
 *
 * @since 0.5.0
 * @return bool
 */
function extrachill_docs_upsert_doc_page_permission_callback(): bool {
	// Cron context: no current user, all checks pass.
	if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
		return true;
	}

	// CLI context: trust the operator.
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		return true;
	}

	return function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
}

/**
 * Execute the upsert.
 *
 * Algorithm:
 *   1. Resolve or create the parent page from parent_slug + parent_title.
 *   2. Convert markdown → Gutenberg blocks through Data Machine ContentFormat.
 *   3. Look up existing page by { _source_repo, _source_path } meta query.
 *   4. If unchanged (same _source_sha), return 'unchanged' without writing.
 *   5. If new, wp_insert_post under the parent with required meta.
 *   6. If changed, wp_update_post preserving slug + parent.
 *
 * Title is derived from the first H1 in the markdown, falling back to the
 * filename-derived slug humanized.
 *
 * @since 0.5.0
 *
 * @param array<string,mixed> $input Ability input.
 * @return array<string,mixed>
 */
function extrachill_docs_execute_upsert_doc_page( array $input ): array {
	$repo         = isset( $input['repo'] ) ? (string) $input['repo'] : '';
	$path         = isset( $input['path'] ) ? (string) $input['path'] : '';
	$sha          = isset( $input['sha'] ) ? (string) $input['sha'] : '';
	$markdown     = isset( $input['markdown'] ) ? (string) $input['markdown'] : '';
	$parent_slug  = isset( $input['parent_slug'] ) ? sanitize_title( (string) $input['parent_slug'] ) : '';
	$parent_title = isset( $input['parent_title'] ) ? (string) $input['parent_title'] : '';
	$dry_run      = ! empty( $input['dry_run'] );

	if ( '' === $repo || '' === $path || '' === $parent_slug || '' === $parent_title ) {
		return array(
			'success' => false,
			'error'   => 'extrachill_docs_missing_input',
			'error_detail' => 'repo, path, parent_slug, and parent_title are required.',
		);
	}

	if ( ! function_exists( 'wp_get_ability' ) ) {
		return array(
			'success' => false,
			'error'   => 'extrachill_docs_abilities_api_missing',
			'error_detail' => 'WordPress Abilities API is not available.',
		);
	}

	// Resolve or create parent page.
	$parent_id = extrachill_docs_resolve_or_create_parent_page( $parent_slug, $parent_title, $dry_run );
	if ( is_wp_error( $parent_id ) ) {
		return array(
			'success'      => false,
			'error'        => (string) $parent_id->get_error_code(),
			'error_detail' => (string) $parent_id->get_error_message(),
		);
	}

	// Look up existing synced page.
	$existing = extrachill_docs_find_synced_page( $repo, $path );

	// Short-circuit on unchanged content (sha match).
	if ( $existing && '' !== $sha ) {
		$existing_sha = (string) get_post_meta( $existing->ID, '_source_sha', true );
		if ( $existing_sha === $sha ) {
			return array(
				'success'   => true,
				'action'    => 'unchanged',
				'page_id'   => (int) $existing->ID,
				'parent_id' => (int) $parent_id,
				'permalink' => $dry_run ? '' : (string) get_permalink( $existing->ID ),
			);
		}
	}

	$markdown       = extrachill_docs_resolve_internal_markdown_links( $markdown, $parent_slug );
	$blocks_content = extrachill_docs_convert_markdown_to_blocks( $markdown );
	if ( is_wp_error( $blocks_content ) ) {
		return array(
			'success'      => false,
			'error'        => (string) $blocks_content->get_error_code(),
			'error_detail' => (string) $blocks_content->get_error_message(),
		);
	}

	$title = extrachill_docs_extract_title_from_markdown( $markdown, $path );
	$slug  = extrachill_docs_derive_slug_from_path( $path );

	if ( $dry_run ) {
		return array(
			'success'   => true,
			'action'    => $existing ? 'would_update' : 'would_create',
			'page_id'   => $existing ? (int) $existing->ID : 0,
			'parent_id' => (int) $parent_id,
			'permalink' => '',
		);
	}

	// Apply.
	$postarr = array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => $title,
		'post_name'    => $slug,
		'post_content' => $blocks_content,
		'post_parent'  => (int) $parent_id,
	);

	if ( $existing ) {
		$postarr['ID'] = (int) $existing->ID;
		$result_id     = wp_update_post( $postarr, true );
		$action        = 'updated';
	} else {
		$result_id = wp_insert_post( $postarr, true );
		$action    = 'created';
	}

	if ( is_wp_error( $result_id ) ) {
		return array(
			'success'      => false,
			'error'        => (string) $result_id->get_error_code(),
			'error_detail' => (string) $result_id->get_error_message(),
		);
	}

	$page_id = (int) $result_id;

	update_post_meta( $page_id, '_source_repo', $repo );
	update_post_meta( $page_id, '_source_path', $path );
	if ( '' !== $sha ) {
		update_post_meta( $page_id, '_source_sha', $sha );
	}

	return array(
		'success'   => true,
		'action'    => $action,
		'page_id'   => $page_id,
		'parent_id' => (int) $parent_id,
		'permalink' => (string) get_permalink( $page_id ),
	);
}

/**
 * Convert Markdown through Data Machine's canonical content boundary.
 *
 * @since 0.5.3
 *
 * @param string $markdown Markdown source.
 * @return string|\WP_Error Serialized block markup or an error.
 */
function extrachill_docs_convert_markdown_to_blocks( string $markdown ) {
	if ( ! class_exists( '\\DataMachine\\Core\\Content\\ContentFormat' ) ) {
		return new WP_Error(
			'extrachill_docs_content_converter_missing',
			'Data Machine content-format conversion is unavailable.'
		);
	}

	$result = \DataMachine\Core\Content\ContentFormat::convert(
		$markdown,
		'markdown',
		'blocks',
		array( 'post_type' => 'page' )
	);

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	if ( ! is_string( $result ) || '' === trim( $result ) ) {
		return new WP_Error(
			'extrachill_docs_conversion_empty',
			'Markdown-to-blocks conversion returned no content.'
		);
	}

	return $result;
}

/**
 * Resolve repository-local Markdown links to sibling Docs pages.
 *
 * Synced documents are flattened beneath their configured parent page, so the
 * destination slug is always derived from the linked Markdown filename.
 * Images, absolute URLs, root-relative URLs, and non-Markdown targets remain
 * unchanged.
 *
 * @since 0.5.3
 *
 * @param string $markdown    Markdown source.
 * @param string $parent_slug Destination parent page slug.
 * @return string Markdown with internal document links resolved.
 */
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

/**
 * Find an existing synced page by source repo + path meta.
 *
 * @since 0.5.0
 *
 * @param string $repo Source repo (owner/repo).
 * @param string $path Source path inside the repo.
 * @return \WP_Post|null
 */
function extrachill_docs_find_synced_page( string $repo, string $path ): ?\WP_Post {
	$query = new \WP_Query(
		array(
			'post_type'      => 'page',
			'post_status'    => array( 'publish', 'draft', 'private', 'pending' ),
			'posts_per_page' => 1,
			'no_found_rows'  => true,
			'meta_query'     => array(
				'relation' => 'AND',
				array(
					'key'     => '_source_repo',
					'value'   => $repo,
					'compare' => '=',
				),
				array(
					'key'     => '_source_path',
					'value'   => $path,
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
 * Resolve the parent page ID for a given slug, creating it if absent.
 *
 * Parent pages are non-synced (no _source_repo meta) so they are not
 * locked down by SyncedPageGuard — admins can edit titles/content/order
 * directly. The parent's content is intentionally left blank for the
 * theme to render an archive-style listing of its children.
 *
 * @since 0.5.0
 *
 * @param string $slug    Parent slug.
 * @param string $title   Parent title (used on create).
 * @param bool   $dry_run When true, returns 0 for unknown parents instead of creating.
 * @return int|\WP_Error
 */
function extrachill_docs_resolve_or_create_parent_page( string $slug, string $title, bool $dry_run ) {
	$existing = get_page_by_path( $slug, OBJECT, 'page' );
	if ( $existing instanceof \WP_Post ) {
		return (int) $existing->ID;
	}

	if ( $dry_run ) {
		return 0;
	}

	$parent_id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
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

/**
 * Extract a human-readable title from the markdown body.
 *
 * Looks for the first ATX-style H1 (`# Title`) in the content. Falls back
 * to the filename-derived slug humanized when no H1 is present.
 *
 * @since 0.5.0
 *
 * @param string $markdown Full markdown body.
 * @param string $path     Source file path, used for the filename fallback.
 * @return string
 */
function extrachill_docs_extract_title_from_markdown( string $markdown, string $path ): string {
	if ( preg_match( '/^\s*#\s+(.+?)\s*$/m', $markdown, $matches ) ) {
		$title = trim( $matches[1] );
		if ( '' !== $title ) {
			return $title;
		}
	}

	$slug = extrachill_docs_derive_slug_from_path( $path );
	return ucwords( str_replace( '-', ' ', $slug ) );
}

/**
 * Derive a page slug from a source file path.
 *
 * Takes the basename minus the .md extension. Sanitizes through
 * sanitize_title() so the slug is URL-safe.
 *
 * @since 0.5.0
 *
 * @param string $path Source file path inside the repo (e.g. docs/user/getting-started.md).
 * @return string
 */
function extrachill_docs_derive_slug_from_path( string $path ): string {
	$basename = basename( $path );
	$basename = preg_replace( '/\.md$/i', '', $basename );
	return sanitize_title( (string) $basename );
}
