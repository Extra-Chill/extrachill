<?php
/**
 * Publish installed plugin user docs.
 *
 * Feature plugins keep their end-user help articles as Markdown in their own
 * repository (docs/user/*.md by default, maintained by docs-agent) and ship
 * them with every deploy. This publishes those installed files as pages on
 * the docs site through MDI import. No remote fetch is involved: the deploy already put the files on
 * disk.
 *
 * Publishing is additive. A page whose source file disappears is reported as
 * an orphan and left in place.
 *
 * @package ExtraChillDocs
 */

defined( 'ABSPATH' ) || exit;

/** Cron hook that publishes when any mapped plugin's docs changed. */
const EXTRACHILL_DOCS_PUBLISH_CRON_HOOK = 'extrachill_docs_publish_plugin_docs';

/** Option storing the last published fingerprint per source repo. */
const EXTRACHILL_DOCS_PUBLISHED_FINGERPRINTS_OPTION = 'extrachill_docs_published_fingerprints';

add_action( 'wp_abilities_api_init', 'extrachill_docs_register_publish_plugin_docs_ability' );

/**
 * Register the publish ability.
 */
function extrachill_docs_register_publish_plugin_docs_ability(): void {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability(
		'extrachill-docs/publish-plugin-docs',
		array(
			'label'               => __( 'Publish Plugin Documentation', 'extrachill-docs' ),
			'description'         => __( 'Publish the Markdown user docs shipped inside installed plugins as pages on the docs site. Additive: never deletes pages; reports orphans whose source file is gone.', 'extrachill-docs' ),
			'category'            => 'extrachill-docs',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'repo'    => array(
						'type'        => 'string',
						'description' => __( 'Only publish this component path (plugins/plugin-name). Default: every mapped repo.', 'extrachill-docs' ),
					),
					'dry_run' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'rows'    => array( 'type' => 'array' ),
				),
			),
			'execute_callback'    => 'extrachill_docs_execute_publish_plugin_docs',
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
 * Plan the files to publish for each mapped platform. Pure filesystem logic.
 *
 * @param array  $platform_map Output of extrachill_docs_load_platform_map().
 * @param string $plugins_root Absolute plugins directory.
 * @param string $repo_filter  Optional owner/repo to restrict to.
 * @return array<int,array<string,string>> One row per file or skipped repo.
 */
function extrachill_docs_plan_plugin_docs( array $platform_map, string $plugins_root, string $repo_filter = '' ): array {
	$plan = array();

	foreach ( $platform_map as $platform ) {
		$repo = (string) ( $platform['repo'] ?? '' );
		if ( '' === $repo || ( '' !== $repo_filter && $repo !== $repo_filter ) ) {
			continue;
		}

		$subpath    = trim( (string) ( $platform['docs_subpath'] ?? '' ), '/' );
		$subpath    = '' !== $subpath ? $subpath : 'docs/user';
		$plugin_dir = rtrim( $plugins_root, '/' ) . '/' . basename( $repo );

		if ( ! is_dir( $plugin_dir ) ) {
			$plan[] = array(
				'repo'   => $repo,
				'action' => 'skipped_missing_plugin',
			);
			continue;
		}

		$files = glob( $plugin_dir . '/' . $subpath . '/*.md' );
		$files = is_array( $files ) ? $files : array();
		sort( $files );

		foreach ( $files as $file ) {
			$plan[] = array(
				'repo'         => $repo,
				'path'         => $repo . '/' . $subpath . '/' . basename( $file ),
				'file'         => $file,
				'parent_slug'  => (string) ( $platform['parent_slug'] ?? '' ),
				'parent_title' => (string) ( $platform['parent_title'] ?? '' ),
				'post_status'  => (string) ( $platform['post_status'] ?? 'publish' ),
				'action'       => 'publish',
			);
		}
	}

	return $plan;
}

/**
 * Git-blob-style SHA of Markdown content for fingerprint change detection.
 *
 * @param string $markdown File content.
 */
function extrachill_docs_markdown_sha( string $markdown ): string {
	return sha1( 'blob ' . strlen( $markdown ) . "\0" . $markdown );
}

/**
 * Fingerprint every planned file per repo, so a deploy that changes docs is
 * detectable without relying on plugin version headers.
 *
 * @param array $plan Output of extrachill_docs_plan_plugin_docs().
 * @return array<string,string> repo => fingerprint.
 */
function extrachill_docs_plugin_docs_fingerprints( array $plan ): array {
	$parts = array();
	foreach ( $plan as $row ) {
		if ( 'publish' !== $row['action'] ) {
			continue;
		}
		$content                 = (string) file_get_contents( $row['file'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local installed plugin file.
		$parts[ $row['repo'] ][] = $row['path'] . ':' . extrachill_docs_markdown_sha( $content );
	}

	$fingerprints = array();
	foreach ( $parts as $repo => $entries ) {
		$fingerprints[ $repo ] = sha1( implode( "\n", $entries ) );
	}
	return $fingerprints;
}

/**
 * Execute the publish ability.
 *
 * @param array $input Ability input.
 * @return array<string,mixed>
 */
function extrachill_docs_execute_publish_plugin_docs( array $input ): array {
	$repo_filter = isset( $input['repo'] ) ? (string) $input['repo'] : '';
	$dry_run     = ! empty( $input['dry_run'] );
	if ( ! class_exists( 'WP_Markdown_CLI' ) || ! function_exists( 'markdown_db_register_content_layout_profile' ) ) {
		return array(
			'success' => false,
			'error'   => 'mdi_unavailable',
			'rows'    => array(),
		);
	}
	extrachill_docs_register_mdi_layout();
	$plan = extrachill_docs_plan_plugin_docs( extrachill_docs_load_platform_map(), WP_PLUGIN_DIR, $repo_filter );
	foreach ( $plan as $item ) {
		if ( 'publish' !== $item['action'] ) {
			continue;
		}
		$parent = extrachill_docs_resolve_or_create_parent_page( $item['parent_slug'], $item['parent_title'], $item['post_status'], $dry_run );
		if ( is_wp_error( $parent ) ) {
			return array(
				'success' => false,
				'error'   => $parent->get_error_message(),
				'rows'    => array(),
			);
		}
	}
	$GLOBALS['extrachill_docs_mdi_repo']      = $repo_filter;
	$GLOBALS['extrachill_docs_mdi_importing'] = true;
	try {
		$result = WP_Markdown_CLI::import( array(
			'path'           => WP_PLUGIN_DIR,
			'layout_profile' => EXTRACHILL_DOCS_MDI_LAYOUT,
			'profile'        => 'markdown-h1-title',
			'dry_run'        => $dry_run,
		) );
	} finally {
		unset( $GLOBALS['extrachill_docs_mdi_repo'], $GLOBALS['extrachill_docs_mdi_importing'] );
	}
	$rows = array( $result );
	$ok   = ! empty( $result['success'] ) && empty( $result['skipped_count'] );

	foreach ( extrachill_docs_find_orphaned_doc_pages( $plan, $repo_filter ) as $orphan ) {
		$rows[] = $orphan;
	}

	return array(
		'success' => $ok,
		'rows'    => $rows,
	);
}

/**
 * Pages keyed to a mapped repo whose source file is no longer installed.
 *
 * @param array  $plan        Current publish plan.
 * @param string $repo_filter Optional repo restriction.
 * @return array<int,array<string,mixed>>
 */
function extrachill_docs_find_orphaned_doc_pages( array $plan, string $repo_filter = '' ): array {
	$present = array();
	$repos   = array();
	foreach ( extrachill_docs_load_platform_map() as $entry ) {
		$repos[ $entry['repo'] ] = true;
	}
	foreach ( $plan as $row ) {
		if ( 'publish' === $row['action'] ) {
			$present[ $row['path'] ] = true;
			$repos[ $row['repo'] ]   = true;
		}
	}

	$orphans = array();
	foreach ( array_keys( $repos ) as $repo ) {
		if ( '' !== $repo_filter && $repo !== $repo_filter ) {
			continue;
		}
		$query = new \WP_Query(
			array(
				'post_type'      => 'page',
				'post_status'    => array( 'publish', 'private', 'draft', 'pending' ),
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'meta_key'       => '_source_repo', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Small docs site.
				'meta_value'     => 'Extra-Chill/extrachill', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Small docs site.
			)
		);
		foreach ( $query->posts as $page ) {
			if ( ! $page instanceof \WP_Post ) {
				continue;
			}
			$path = (string) get_post_meta( $page->ID, '_source_path', true );
			if ( str_starts_with( $path, $repo . '/' ) && empty( $present[ $path ] ) ) {
				$orphans[] = array(
					'repo'    => $repo,
					'path'    => $path,
					'action'  => 'orphaned',
					'page_id' => (int) $page->ID,
				);
			}
		}
	}
	return $orphans;
}

/**
 * Publish any repo whose installed docs changed since the last publish.
 *
 * Runs hourly, so a deploy that ships new docs is live within the hour
 * without anyone visiting wp-admin.
 */
function extrachill_docs_publish_changed_plugin_docs(): void {
	if ( ! class_exists( 'WP_Markdown_CLI' ) || ! function_exists( 'markdown_db_register_content_layout_profile' ) ) {
		static $logged = false;
		if ( ! $logged ) {
			error_log( 'Extra Chill Docs: MDI inactive; skipping docs import.' );
			$logged = true;
		}
		return;
	}
	$stored = get_option( EXTRACHILL_DOCS_PUBLISHED_FINGERPRINTS_OPTION, array() );
	$stored = is_array( $stored ) ? $stored : array();
	$next   = $stored;

	$plan = extrachill_docs_plan_plugin_docs( extrachill_docs_load_platform_map(), WP_PLUGIN_DIR );
	foreach ( extrachill_docs_plugin_docs_fingerprints( $plan ) as $repo => $fingerprint ) {
		if ( ( $stored[ $repo ] ?? '' ) === $fingerprint ) {
			continue;
		}
		$result = extrachill_docs_execute_publish_plugin_docs( array( 'repo' => $repo ) );
		if ( ! empty( $result['success'] ) ) {
			$next[ $repo ] = $fingerprint;
		}
	}

	if ( $next !== $stored ) {
		update_option( EXTRACHILL_DOCS_PUBLISHED_FINGERPRINTS_OPTION, $next, false );
	}
}
add_action( EXTRACHILL_DOCS_PUBLISH_CRON_HOOK, 'extrachill_docs_publish_changed_plugin_docs' );

/**
 * Keep the hourly publish check scheduled on the docs site.
 */
function extrachill_docs_schedule_publish_check(): void {
	if ( ! wp_next_scheduled( EXTRACHILL_DOCS_PUBLISH_CRON_HOOK ) ) {
		wp_schedule_event( time() + MINUTE_IN_SECONDS, 'hourly', EXTRACHILL_DOCS_PUBLISH_CRON_HOOK );
	}
}
add_action( 'init', 'extrachill_docs_schedule_publish_check' );
