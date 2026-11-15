<?php
/**
 * Users Avatar Snapshot CLI Command
 *
 * Thin adapter over extrachill_users_backfill_avatar_snapshots(), owned by
 * extrachill-users.
 *
 * @package ExtraChill\CLI\Commands\Users
 */

namespace ExtraChill\CLI\Commands\Users;

use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AvatarSnapshotCommand {

	/**
	 * Build missing or stale `ec_avatar_urls` snapshots for users with a custom avatar.
	 *
	 * ## OPTIONS
	 *
	 * [--batch-size=<n>]
	 * : Users per batch (one community blog switch per batch).
	 * ---
	 * default: 100
	 * ---
	 *
	 * [--limit=<n>]
	 * : Maximum users to scan. 0 scans all.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--dry-run]
	 * : Count missing or stale snapshots without writing.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill users avatar-snapshot backfill --dry-run
	 *     wp extrachill users avatar-snapshot backfill --batch-size=50
	 *
	 * @when after_wp_load
	 */
	public function backfill( $args, $assoc_args ) {
		if ( ! function_exists( 'extrachill_users_backfill_avatar_snapshots' ) ) {
			WP_CLI::error( 'extrachill_users_backfill_avatar_snapshots() is unavailable. Is extrachill-users active?' );
		}

		$report = extrachill_users_backfill_avatar_snapshots(
			array(
				'batch_size' => isset( $assoc_args['batch-size'] ) ? (int) $assoc_args['batch-size'] : 100,
				'limit'      => isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 0,
				'dry_run'    => ! empty( $assoc_args['dry-run'] ),
			)
		);

		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';
		if ( 'json' === $format ) {
			WP_CLI::log( (string) wp_json_encode( $report ) );
			return;
		}

		$rows = array();
		foreach ( $report as $field => $value ) {
			if ( is_bool( $value ) ) {
				$value = $value ? 'yes' : 'no';
			}
			$rows[] = array(
				'Field' => $field,
				'Value' => (string) $value,
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'Field', 'Value' ) );

		if ( $report['dry_run'] ) {
			WP_CLI::success( sprintf( 'Dry run: %d snapshot(s) would be written.', (int) $report['would_write'] ) );
		} else {
			WP_CLI::success( sprintf( 'Wrote %d avatar snapshot(s).', (int) $report['written'] ) );
		}
	}
}
