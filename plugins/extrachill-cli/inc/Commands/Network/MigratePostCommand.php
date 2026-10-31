<?php
/**
 * Network CLI Commands
 *
 * Thin WP-CLI wrappers over network-level primitives owned by
 * extrachill-multisite. No logic lives here — each subcommand resolves its
 * arguments and delegates to the multisite implementation.
 *
 * @package ExtraChill\CLI\Commands\Network
 */

namespace ExtraChill\CLI\Commands\Network;

use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MigratePostCommand {

	/**
	 * Migrate one post and all its media from one network site to another.
	 *
	 * Delegates to the `ec_migrate_post()` primitive in extrachill-multisite.
	 * Non-destructive by default — the source is only deleted when
	 * `--delete-source` is passed AND the migration verifies successfully.
	 * `--dry-run` reports what would happen without writing anything.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : Post ID on the source site.
	 *
	 * --from=<site>
	 * : Source site — a blog ID (e.g. 12) or a site key (e.g. studio, main, wire).
	 *
	 * --to=<site>
	 * : Destination site — a blog ID or site key.
	 *
	 * [--status=<status>]
	 * : Destination post status. Default: preserve source status (a pending
	 * source stays pending).
	 *
	 * [--delete-source]
	 * : Delete the source post + its attachments after a verified successful
	 * migration. Never fires on dry-run or partial failure.
	 *
	 * [--dry-run]
	 * : Report what would happen without writing anything on either site.
	 *
	 * [--porcelain]
	 * : Output only the new destination post ID (empty on dry-run).
	 *
	 * [--format=<format>]
	 * : Output format for the full result.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Dry-run: un-strand Studio post 88 (blog 12) onto the main site (blog 1) as pending.
	 *     wp extrachill network migrate-post 88 --from=12 --to=1 --status=pending --dry-run
	 *
	 *     # Same, using site keys.
	 *     wp extrachill network migrate-post 88 --from=studio --to=main --status=pending --dry-run
	 *
	 *     # Real migration, leaving the source in place.
	 *     wp extrachill network migrate-post 88 --from=studio --to=main --status=pending
	 *
	 *     # Migration that removes the source afterwards (only if it verifies).
	 *     wp extrachill network migrate-post 88 --from=studio --to=main --delete-source
	 *
	 * @when after_wp_load
	 */
	public function migrate_post( $args, $assoc_args ) {
		if ( ! function_exists( 'ec_migrate_post' ) ) {
			WP_CLI::error( 'ec_migrate_post() not available. Ensure extrachill-multisite is network-activated.' );
		}

		$post_id = absint( $args[0] ?? 0 );
		if ( ! $post_id ) {
			WP_CLI::error( 'A positive post ID is required.' );
		}

		$from = isset( $assoc_args['from'] ) ? (string) $assoc_args['from'] : '';
		$to   = isset( $assoc_args['to'] ) ? (string) $assoc_args['to'] : '';

		if ( '' === $from || '' === $to ) {
			WP_CLI::error( 'Both --from and --to are required.' );
		}

		$source_blog_id = $this->resolve_blog_id( $from );
		if ( null === $source_blog_id ) {
			WP_CLI::error( sprintf( 'Could not resolve --from=%s to a blog ID.', $from ) );
		}

		$dest_blog_id = $this->resolve_blog_id( $to );
		if ( null === $dest_blog_id ) {
			WP_CLI::error( sprintf( 'Could not resolve --to=%s to a blog ID.', $to ) );
		}

		$dry_run       = ! empty( $assoc_args['dry-run'] );
		$delete_source = ! empty( $assoc_args['delete-source'] );

		$result = $this->call_dependency(
			'ec_migrate_post',
			$source_blog_id,
			$post_id,
			$dest_blog_id,
			array(
				'status'        => $assoc_args['status'] ?? '',
				'delete_source' => $delete_source,
				'dry_run'       => $dry_run,
			)
		);

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		// Porcelain: only the new post ID (empty string on dry-run).
		if ( ! empty( $assoc_args['porcelain'] ) ) {
			WP_CLI::line( (string) ( $result['dest_post_id'] ? $result['dest_post_id'] : '' ) );
			return;
		}

		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::log( (string) wp_json_encode( $result, JSON_PRETTY_PRINT ) );
			return;
		}

		$this->render_human( $result );
	}

	/**
	 * Resolve a blog ID or site key to a numeric blog ID.
	 *
	 * @param string $value Blog ID string or logical site key.
	 * @return int|null Blog ID, or null if unresolvable.
	 */
	private function resolve_blog_id( string $value ): ?int {
		$value = trim( $value );

		if ( ctype_digit( $value ) ) {
			return (int) $value;
		}

		if ( function_exists( 'ec_get_blog_id' ) ) {
			$resolved = ec_get_blog_id( $value );
			if ( $resolved ) {
				return (int) $resolved;
			}
		}

		return null;
	}

	/**
	 * Render a human-readable summary of the migration result.
	 *
	 * @param array $result Result from ec_migrate_post().
	 */
	private function render_human( array $result ): void {
		if ( ! empty( $result['dry_run'] ) ) {
			WP_CLI::line( 'DRY RUN — nothing was written on either site.' );
			WP_CLI::line( '' );
			WP_CLI::line( sprintf( 'Would migrate: "%s" (post %d)', $result['source_title'] ?? '', $result['source_post_id'] ) );
			WP_CLI::line( sprintf( '  From blog %d  ->  blog %d', $result['source_blog_id'], $result['dest_blog_id'] ) );
			WP_CLI::line( sprintf( '  Destination status: %s', $result['dest_status'] ) );
			WP_CLI::line( sprintf( '  Attachments to migrate: %d', $result['attachments_total'] ) );
			if ( ! empty( $result['would_delete_source'] ) ) {
				WP_CLI::line( '  Source would be DELETED after a verified success.' );
			} else {
				WP_CLI::line( '  Source would be preserved.' );
			}
			$this->render_missing( $result['missing_files'] );
			return;
		}

		WP_CLI::success(
			sprintf(
				'Migrated "%s" -> blog %d as post %d (status: %s).',
				$result['source_title'] ?? '',
				$result['dest_blog_id'],
				$result['dest_post_id'],
				$result['dest_status']
			)
		);
		WP_CLI::line( sprintf( '  Images migrated: %d of %d', $result['attachments_migrated'], $result['attachments_total'] ) );
		if ( ! empty( $result['featured_image_id'] ) ) {
			WP_CLI::line( sprintf( '  Featured image (dest): %d', $result['featured_image_id'] ) );
		}
		if ( ! empty( $result['source_deleted'] ) ) {
			WP_CLI::line( '  Source post + attachments DELETED.' );
		} else {
			WP_CLI::line( '  Source preserved.' );
		}
		$this->render_missing( $result['missing_files'] );
	}

	/**
	 * Report any attachments whose underlying file was missing.
	 *
	 * @param array $missing Missing-file descriptors.
	 */
	private function render_missing( array $missing ): void {
		if ( empty( $missing ) ) {
			return;
		}
		WP_CLI::warning( sprintf( '%d attachment(s) had missing/unmigratable files:', count( $missing ) ) );
		foreach ( $missing as $m ) {
			WP_CLI::line(
				sprintf(
					'  - #%d %s (%s)',
					$m['id'] ?? 0,
					$m['filename'] ?? '',
					$m['reason'] ?? 'unknown'
				)
			);
		}
	}

	/** Invoke a migration function supplied by the network runtime. */
	private function call_dependency( $callback, ...$args ) {
		if ( ! is_callable( $callback ) ) {
			WP_CLI::error( 'Post migration API is not available.' );
			return null;
		}

		return $callback( ...$args );
	}
}
