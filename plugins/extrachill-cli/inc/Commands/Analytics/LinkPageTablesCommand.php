<?php
/**
 * Link Page Analytics Tables CLI Command
 *
 * Thin wrapper over the extrachill/cleanup-link-page-tables ability (#1775).
 *
 * @package ExtraChill\CLI\Commands\Analytics
 */

namespace ExtraChill\CLI\Commands\Analytics;

use WP_CLI;
use WP_CLI\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LinkPageTablesCommand {

	/**
	 * Drop empty Link Page analytics table copies on non-storage blogs.
	 *
	 * One-time cleanup: the daily views/clicks tables only belong on the Link
	 * Page storage blog. Dry-run by default. A non-storage copy that has rows
	 * is reported as `refused` and never dropped; the command exits non-zero
	 * when anything is refused so the operator reviews it.
	 *
	 * ## OPTIONS
	 *
	 * [--execute]
	 * : Actually drop the empty tables. Without this flag nothing is changed.
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
	 *     # Report what would be dropped (default dry run).
	 *     wp extrachill analytics link-page-tables cleanup
	 *
	 *     # Drop the empty non-storage copies.
	 *     wp extrachill analytics link-page-tables cleanup --execute
	 *
	 * @subcommand cleanup
	 */
	public function cleanup( $args, $assoc_args ) {
		$ability = wp_get_ability( 'extrachill/cleanup-link-page-tables' );
		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/cleanup-link-page-tables ability not found. Is extrachill-analytics active?' );
		}

		$dry_run = ! Utils\get_flag_value( $assoc_args, 'execute', false );
		$result  = $ability->execute( array( 'dry_run' => $dry_run ) );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$format = $assoc_args['format'] ?? 'table';
		if ( 'json' === $format ) {
			WP_CLI::line( (string) wp_json_encode( $result, JSON_PRETTY_PRINT ) );
		} else {
			WP_CLI::log( sprintf( 'Link Page storage blog: %d', (int) $result['storage_blog_id'] ) );
			if ( empty( $result['tables'] ) ) {
				WP_CLI::log( 'No Link Page analytics tables found.' );
			} else {
				Utils\format_items( 'table', $result['tables'], array( 'blog_id', 'table', 'rows', 'action' ) );
			}
		}

		$summary = $dry_run
			? sprintf( 'Dry run: %d empty table(s) would be dropped. Re-run with --execute to drop them.', (int) $result['would_drop'] )
			: sprintf( 'Dropped %d empty table(s).', (int) $result['dropped'] );

		if ( (int) $result['refused'] > 0 ) {
			WP_CLI::log( $summary );
			WP_CLI::error( sprintf( '%d non-storage table(s) have rows and were NOT dropped. Review/migrate them before re-running.', (int) $result['refused'] ) );
		}

		WP_CLI::success( $summary );
	}
}
