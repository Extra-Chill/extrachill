<?php
/**
 * Sync Orchestrator
 *
 * Drives `extrachill-docs/upsert-doc-page` across configured plugin repos.
 * Two entry points share the same orchestration code:
 *
 *   - Scheduled cron event `extrachill_docs_sync_cron` (default: hourly)
 *   - WP-CLI command `wp extrachill docs sync` for ad-hoc and dry-runs
 *
 * Both walk the list of repos returned by the `extrachill_docs_sync_repos`
 * filter (seeded from runner-configs/platform-map.yml so the docs-agent
 * CI side and the production sync side share one source of truth).
 *
 * For each repo:
 *   1. List docs/user/**\/*.md via datamachine-code/list-github-tree
 *   2. Fetch each file's content + sha via datamachine-code/get-github-file
 *   3. Call extrachill-docs/upsert-doc-page per file
 *
 * Errors are per-file. One bad file logs and continues; one bad repo
 * logs and proceeds to the next repo. The orchestrator returns a
 * structured summary suitable for CLI rendering or job logs.
 *
 * @package ExtraChillDocs
 * @since   0.5.0
 */

defined( 'ABSPATH' ) || exit;

const EXTRACHILL_DOCS_SYNC_CRON_HOOK = 'extrachill_docs_sync_cron';

/**
 * Register the scheduled sync event on plugin activation.
 *
 * Called from extrachill_docs_activate(). Schedules an hourly run by
 * default; the interval is configurable via the `extrachill_docs_sync_interval`
 * filter (must return a valid WP-Cron schedule name).
 *
 * @since 0.5.0
 * @return void
 */
function extrachill_docs_schedule_sync_cron(): void {
	$interval = (string) apply_filters( 'extrachill_docs_sync_interval', 'hourly' );

	if ( ! wp_next_scheduled( EXTRACHILL_DOCS_SYNC_CRON_HOOK ) ) {
		wp_schedule_event( time() + 60, $interval, EXTRACHILL_DOCS_SYNC_CRON_HOOK );
	}
}

/**
 * Tear down the scheduled event on plugin deactivation.
 *
 * @since 0.5.0
 * @return void
 */
function extrachill_docs_unschedule_sync_cron(): void {
	$timestamp = wp_next_scheduled( EXTRACHILL_DOCS_SYNC_CRON_HOOK );
	if ( false !== $timestamp ) {
		wp_unschedule_event( $timestamp, EXTRACHILL_DOCS_SYNC_CRON_HOOK );
	}
}

/**
 * Cron callback — run the sync across all configured repos.
 *
 * @since 0.5.0
 * @return void
 */
function extrachill_docs_run_sync_cron(): void {
	if ( class_exists( '\\DataMachine\\Abilities\\PermissionHelper' ) ) {
		$summary = \DataMachine\Abilities\PermissionHelper::run_as_authenticated(
			static fn() => extrachill_docs_sync_all_repos( false, null )
		);
	} else {
		$summary = extrachill_docs_sync_all_repos( false, null );
	}

	do_action( 'extrachill_docs_sync_completed', $summary );
}
add_action( EXTRACHILL_DOCS_SYNC_CRON_HOOK, 'extrachill_docs_run_sync_cron' );

// Activation hooks do not run during a normal plugin upgrade. Repair the
// schedule idempotently so installations that received sync after activation
// begin syncing without an operator deactivate/reactivate cycle.
add_action( 'init', 'extrachill_docs_schedule_sync_cron' );

/**
 * Return the list of repos to sync, with their parent-page identities.
 *
 * Default seed reads from runner-configs/platform-map.yml so the
 * docs-agent CI side (which writes markdown into plugin repos) and the
 * production sync side (which reads it back into pages) stay in lockstep.
 *
 * The `extrachill_docs_sync_repos` filter lets other plugins or
 * site-specific code add or remove repos.
 *
 * Each entry shape:
 *   [
 *     'repo'         => 'Extra-Chill/extrachill-artist-platform',
 *     'parent_slug'  => 'artist-platform',
 *     'parent_title' => 'Artist Platform',
 *     'docs_subpath' => 'docs/user',
 *   ]
 *
 * @since 0.5.0
 * @return array<int,array<string,string>>
 */
function extrachill_docs_get_sync_repos(): array {
	$default = extrachill_docs_load_platform_map();

	$filtered = apply_filters( 'extrachill_docs_sync_repos', $default );

	if ( ! is_array( $filtered ) ) {
		return array();
	}

	$out = array();
	foreach ( $filtered as $entry ) {
		if ( ! is_array( $entry ) ) {
			continue;
		}
		$repo         = isset( $entry['repo'] ) ? (string) $entry['repo'] : '';
		$parent_slug  = isset( $entry['parent_slug'] ) ? sanitize_title( (string) $entry['parent_slug'] ) : '';
		$parent_title = isset( $entry['parent_title'] ) ? (string) $entry['parent_title'] : '';
		$docs_subpath = isset( $entry['docs_subpath'] ) ? trim( (string) $entry['docs_subpath'], '/' ) : 'docs/user';

		if ( '' === $repo || '' === $parent_slug || '' === $parent_title ) {
			continue;
		}

		$out[] = array(
			'repo'         => $repo,
			'parent_slug'  => $parent_slug,
			'parent_title' => $parent_title,
			'docs_subpath' => '' === $docs_subpath ? 'docs/user' : $docs_subpath,
		);
	}

	return $out;
}

/**
 * Parse runner-configs/platform-map.yml into the sync-repos array shape.
 *
 * Hand-written YAML parser scoped to the shape platform-map.yml uses.
 * Avoids adding a Symfony YAML dependency for one file we control.
 *
 * @since 0.5.0
 * @return array<int,array<string,string>>
 */
function extrachill_docs_load_platform_map(): array {
	$path = EXTRACHILL_DOCS_PLUGIN_DIR . 'runner-configs/platform-map.yml';
	if ( ! is_readable( $path ) ) {
		return array();
	}

	// Local plugin-bundled YAML read (not a remote URL); WP_Filesystem/wp_remote_get do not apply.
	$yaml = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	if ( false === $yaml ) {
		return array();
	}

	$lines = preg_split( '/\r\n|\r|\n/', $yaml );
	if ( false === $lines ) {
		return array();
	}

	$out          = array();
	$current_repo = null;
	$current      = array();
	$in_repos     = false;

	foreach ( $lines as $raw ) {
		// Drop comments and empty lines.
		$trimmed = trim( $raw );
		if ( '' === $trimmed || str_starts_with( $trimmed, '#' ) ) {
			continue;
		}

		// Top-level 'repos:' marker.
		if ( ! $in_repos ) {
			if ( preg_match( '/^repos:\s*$/', $trimmed ) ) {
				$in_repos = true;
			}
			continue;
		}

		// Two-space-indented repo key: 'OWNER/REPO:'.
		if ( preg_match( '/^  ([^\s:][^:]*):\s*$/', $raw, $matches ) ) {
			extrachill_docs_flush_platform_map_entry( $out, $current_repo, $current );
			$current_repo = trim( $matches[1] );
			$current      = array();
			continue;
		}

		// Four-space-indented field: '    key: "value"' or '    key: value'.
		if ( null !== $current_repo && preg_match( '/^    ([a-z_]+):\s*"?([^"\n]*)"?\s*$/', $raw, $matches ) ) {
			$key   = $matches[1];
			$value = trim( $matches[2], " \t\"'" );
			if ( '' !== $key && '' !== $value ) {
				$current[ $key ] = $value;
			}
		}
	}

	extrachill_docs_flush_platform_map_entry( $out, $current_repo, $current );

	return $out;
}

/**
 * Helper for the YAML parser: push a completed entry onto the output.
 *
 * @since 0.5.0
 *
 * @param array<int,array<string,string>> $out          Accumulator (by-ref).
 * @param string|null                     $current_repo Repo identifier collected so far.
 * @param array<string,string>            $current      Fields collected for the current repo.
 * @return void
 */
function extrachill_docs_flush_platform_map_entry( array &$out, ?string $current_repo, array $current ): void {
	if ( null === $current_repo || '' === $current_repo ) {
		return;
	}

	$parent_slug  = $current['parent_slug'] ?? '';
	$platform     = $current['platform_name'] ?? '';
	$docs_subpath = $current['docs_subpath'] ?? 'docs/user';

	if ( '' === $parent_slug || '' === $platform ) {
		return;
	}

	$out[] = array(
		'repo'         => $current_repo,
		'parent_slug'  => $parent_slug,
		'parent_title' => $platform,
		'docs_subpath' => $docs_subpath,
	);
}

/**
 * Sync every configured repo.
 *
 * @since 0.5.0
 *
 * @param bool        $dry_run    When true, report planned actions without writing.
 * @param string|null $only_repo  Optional filter — sync only this OWNER/REPO.
 * @return array<string,mixed>    Summary by repo.
 */
function extrachill_docs_sync_all_repos( bool $dry_run = false, ?string $only_repo = null ): array {
	$repos   = extrachill_docs_get_sync_repos();
	$summary = array(
		'started_at' => gmdate( 'c' ),
		'dry_run'    => $dry_run,
		'repos'      => array(),
	);

	foreach ( $repos as $entry ) {
		if ( null !== $only_repo && $entry['repo'] !== $only_repo ) {
			continue;
		}

		$summary['repos'][ $entry['repo'] ] = extrachill_docs_sync_one_repo( $entry, $dry_run );
	}

	if ( ! $dry_run && extrachill_docs_sync_created_pages( $summary['repos'] ) && function_exists( 'flush_rewrite_rules' ) ) {
		flush_rewrite_rules( false );
		$summary['rewrite_rules_flushed'] = true;
	}

	$summary['finished_at'] = gmdate( 'c' );

	return $summary;
}

/**
 * Determine whether a sync created Pages that need new rewrite rules.
 *
 * @since 0.5.4
 *
 * @param array<string,mixed> $repos Repo sync results.
 * @return bool
 */
function extrachill_docs_sync_created_pages( array $repos ): bool {
	foreach ( $repos as $repo ) {
		$files = is_array( $repo ) && isset( $repo['files'] ) && is_array( $repo['files'] ) ? $repo['files'] : array();
		foreach ( $files as $file ) {
			if ( is_array( $file ) && isset( $file['action'] ) && 'created' === $file['action'] ) {
				return true;
			}
		}
	}

	return false;
}

/**
 * Normalize an ability failure without assuming an array result.
 *
 * @param mixed  $result   Ability result.
 * @param string $fallback Fallback message when no error detail is available.
 * @return string
 */
function extrachill_docs_sync_error_message( $result, string $fallback ): string {
	if ( is_wp_error( $result ) ) {
		$code    = (string) $result->get_error_code();
		$message = $result->get_error_message();
		return '' !== $code ? sprintf( '%s: %s', $code, $message ) : $message;
	}

	if ( ! is_array( $result ) ) {
		return $fallback;
	}

	$messages = array();
	foreach ( array( 'error', 'error_detail' ) as $key ) {
		if ( ! isset( $result[ $key ] ) ) {
			continue;
		}
		if ( is_wp_error( $result[ $key ] ) ) {
			$messages[] = extrachill_docs_sync_error_message( $result[ $key ], $fallback );
		} elseif ( is_scalar( $result[ $key ] ) && '' !== (string) $result[ $key ] ) {
			$messages[] = (string) $result[ $key ];
		}
	}

	foreach ( is_array( $result['errors'] ?? null ) ? $result['errors'] : array() as $error ) {
		if ( is_wp_error( $error ) ) {
			$messages[] = extrachill_docs_sync_error_message( $error, $fallback );
			continue;
		}
		if ( is_array( $error ) ) {
			$code    = is_scalar( $error['code'] ?? null ) ? (string) $error['code'] : '';
			$message = is_scalar( $error['message'] ?? null ) ? (string) $error['message'] : '';
			if ( '' !== $message ) {
				$messages[] = '' !== $code ? sprintf( '%s: %s', $code, $message ) : $message;
			}
		} elseif ( is_scalar( $error ) && '' !== (string) $error ) {
			$messages[] = (string) $error;
		}
	}

	$messages = array_values( array_unique( $messages ) );
	return empty( $messages ) ? $fallback : implode( '; ', $messages );
}

/**
 * Sync a single repo: list → fetch → upsert per file.
 *
 * @since 0.5.0
 *
 * @param array<string,string> $entry   Platform-map entry.
 * @param bool                 $dry_run See sync_all_repos.
 * @return array<string,mixed> Per-repo summary.
 */
function extrachill_docs_sync_one_repo( array $entry, bool $dry_run ): array {
	$repo         = $entry['repo'];
	$parent_slug  = $entry['parent_slug'];
	$parent_title = $entry['parent_title'];
	$docs_subpath = $entry['docs_subpath'] ?? 'docs/user';

	$result = array(
		'repo'         => $repo,
		'parent_slug'  => $parent_slug,
		'docs_subpath' => $docs_subpath,
		'files'        => array(),
		'errors'       => array(),
	);

	if ( ! function_exists( 'wp_get_ability' ) ) {
		$result['errors'][] = 'WordPress Abilities API is not available.';
		return $result;
	}

	$list_ability = wp_get_ability( 'datamachine-code/list-github-tree' );
	if ( ! is_object( $list_ability ) || ! method_exists( $list_ability, 'execute' ) ) {
		$result['errors'][] = 'datamachine-code/list-github-tree ability not registered. Is data-machine-code active?';
		return $result;
	}

	$listing = $list_ability->execute(
		array(
			'repo' => $repo,
			'path' => $docs_subpath,
		)
	);

	if ( ! is_array( $listing ) || empty( $listing['success'] ) ) {
		$result['errors'][] = sprintf(
			'list-github-tree failed for %s: %s',
			$repo,
			extrachill_docs_sync_error_message( $listing, 'unknown error' )
		);
		return $result;
	}

	$files = isset( $listing['files'] ) && is_array( $listing['files'] ) ? $listing['files'] : array();

	// Filter to .md files within the docs subpath.
	$md_files = array();
	foreach ( $files as $file ) {
		if ( ! is_array( $file ) ) {
			continue;
		}
		$path = isset( $file['path'] ) ? (string) $file['path'] : '';
		$type = isset( $file['type'] ) ? (string) $file['type'] : '';

		if ( 'blob' !== $type && '' !== $type ) {
			continue;
		}
		if ( '' === $path || ! str_ends_with( strtolower( $path ), '.md' ) ) {
			continue;
		}
		if ( ! str_starts_with( $path, $docs_subpath . '/' ) && $path !== $docs_subpath ) {
			continue;
		}

		$md_files[] = array(
			'path' => $path,
			'sha'  => isset( $file['sha'] ) ? (string) $file['sha'] : '',
		);
	}

	if ( empty( $md_files ) ) {
		// Not an error — repo just doesn't have docs yet.
		return $result;
	}

	$fetch_ability  = wp_get_ability( 'datamachine-code/get-github-file' );
	$upsert_ability = wp_get_ability( 'extrachill-docs/upsert-doc-page' );

	if ( ! is_object( $fetch_ability ) || ! method_exists( $fetch_ability, 'execute' ) ) {
		$result['errors'][] = 'datamachine-code/get-github-file ability not registered.';
		return $result;
	}
	if ( ! is_object( $upsert_ability ) || ! method_exists( $upsert_ability, 'execute' ) ) {
		$result['errors'][] = 'extrachill-docs/upsert-doc-page ability not registered.';
		return $result;
	}

	foreach ( $md_files as $file ) {
		$fetch = $fetch_ability->execute(
			array(
				'repo' => $repo,
				'path' => $file['path'],
			)
		);

		if ( ! is_array( $fetch ) || empty( $fetch['success'] ) ) {
			$result['files'][] = array(
				'path'   => $file['path'],
				'action' => 'fetch_failed',
				'error'  => extrachill_docs_sync_error_message( $fetch, 'unknown' ),
			);
			continue;
		}

		// get-github-file returns a `files` array even for single-file requests.
		$fetched_files = isset( $fetch['files'] ) && is_array( $fetch['files'] ) ? $fetch['files'] : array();
		$content       = '';
		foreach ( $fetched_files as $f ) {
			if ( is_array( $f ) && isset( $f['path'] ) && $f['path'] === $file['path'] ) {
				$content = isset( $f['content'] ) ? (string) $f['content'] : '';
				break;
			}
		}

		if ( '' === $content && ! empty( $fetched_files ) ) {
			// Fallback: first file.
			$first   = reset( $fetched_files );
			$content = is_array( $first ) && isset( $first['content'] ) ? (string) $first['content'] : '';
		}

		$upsert = $upsert_ability->execute(
			array(
				'repo'         => $repo,
				'path'         => $file['path'],
				'sha'          => $file['sha'],
				'markdown'     => $content,
				'parent_slug'  => $parent_slug,
				'parent_title' => $parent_title,
				'dry_run'      => $dry_run,
			)
		);
		if ( ! is_array( $upsert ) || empty( $upsert['success'] ) ) {
			$result['files'][] = array(
				'path'    => $file['path'],
				'action'  => 'upsert_failed',
				'page_id' => 0,
				'error'   => extrachill_docs_sync_error_message( $upsert, 'unknown' ),
			);
			continue;
		}

		$result['files'][] = array(
			'path'    => $file['path'],
			'action'  => isset( $upsert['action'] ) ? (string) $upsert['action'] : 'unknown',
			'page_id' => isset( $upsert['page_id'] ) ? (int) $upsert['page_id'] : 0,
			'error'   => isset( $upsert['error'] ) ? (string) $upsert['error'] : '',
		);
	}

	return $result;
}

// ---------------------------------------------------------------------------
// WP-CLI command surface.
// ---------------------------------------------------------------------------

if ( defined( 'WP_CLI' ) && WP_CLI ) {

	/**
	 * Sync user docs from configured plugin repos into hierarchical WP pages.
	 *
	 * ## OPTIONS
	 *
	 * [--repo=<repository>]
	 * : Sync only this repo.
	 *
	 * [--dry-run]
	 * : Report planned actions without writing.
	 *
	 * [--format=<format>]
	 * : Output format (table|json|yaml). Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *   wp extrachill docs sync --dry-run
	 *   wp extrachill docs sync --repo=Extra-Chill/extrachill-artist-platform
	 *
	 * @when after_wp_load
	 *
	 * @param array<int,string>    $args       Positional args (unused).
	 * @param array<string,string> $assoc_args Associative options.
	 */
	$cli_callback = function ( array $args, array $assoc_args ): void {
		$dry_run   = isset( $assoc_args['dry-run'] );
		$only_repo = isset( $assoc_args['repo'] ) ? (string) $assoc_args['repo'] : null;
		$format    = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';

		$summary = extrachill_docs_sync_all_repos( $dry_run, $only_repo );

		if ( 'json' === $format ) {
			\WP_CLI::print_value( $summary, array( 'format' => 'json' ) );
			return;
		}
		if ( 'yaml' === $format ) {
			\WP_CLI::print_value( $summary, array( 'format' => 'yaml' ) );
			return;
		}

		// Table format: flatten to per-file rows.
		$rows = array();
		foreach ( $summary['repos'] as $repo_summary ) {
			$repo = (string) ( $repo_summary['repo'] ?? '' );
			foreach ( $repo_summary['errors'] ?? array() as $err ) {
				$rows[] = array(
					'repo'    => $repo,
					'path'    => '(repo-level)',
					'action'  => 'error',
					'page_id' => 0,
					'error'   => (string) $err,
				);
			}
			foreach ( $repo_summary['files'] ?? array() as $file_row ) {
				$rows[] = array(
					'repo'    => $repo,
					'path'    => (string) ( $file_row['path'] ?? '' ),
					'action'  => (string) ( $file_row['action'] ?? '' ),
					'page_id' => (int) ( $file_row['page_id'] ?? 0 ),
					'error'   => (string) ( $file_row['error'] ?? '' ),
				);
			}
		}

		if ( empty( $rows ) ) {
			\WP_CLI::log( 'No files to sync (no configured repos have docs/user/*.md yet).' );
			return;
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'repo', 'path', 'action', 'page_id', 'error' ) );
		\WP_CLI::success(
			sprintf(
				'Sync %s — %d row(s).',
				$dry_run ? 'dry-run complete' : 'complete',
				count( $rows )
			)
		);
	};

	\WP_CLI::add_command( 'extrachill docs sync', $cli_callback );
}
