<?php
/**
 * WP-CLI commands for Extra Chill Cache.
 *
 * @package ExtraChillCache
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Run garbage collection for the page cache.
 *
 * ## OPTIONS
 *
 * [--dry-run]
 * : Count expired files and bytes without deleting anything.
 *
 * [--file-limit=<limit>]
 * : Maximum files to examine in this run. Default 2000.
 *
 * [--time-budget=<seconds>]
 * : Maximum seconds to spend walking. Default 30.
 *
 * ## EXAMPLES
 *
 *     wp extrachill-cache gc
 *     wp extrachill-cache gc --dry-run
 *     wp extrachill-cache gc --file-limit=5000 --time-budget=60
 */
class Extrachill_Cache_GC_Command extends WP_CLI_Command {

	/**
	 * Run a single batched garbage-collection pass.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ) {
		$dry_run     = WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$file_limit  = (int) WP_CLI\Utils\get_flag_value( $assoc_args, 'file-limit', 2000 );
		$time_budget = (int) WP_CLI\Utils\get_flag_value( $assoc_args, 'time-budget', 30 );

		$gc_args = array(
			'dry_run'     => $dry_run,
			'file_limit'  => $file_limit,
			'time_budget' => $time_budget,
		);

		WP_CLI::log( sprintf( 'Running Extra Chill Cache GC (dry-run: %s)...', $dry_run ? 'yes' : 'no' ) );

		$stats = extrachill_cache_gc( $gc_args );

		$bytes_human = size_format( $stats['bytes'] );

		WP_CLI::success(
			sprintf(
				'GC complete. Examined: %d | Deleted: %d | Reclaimed: %s (%d bytes) | Completed full scan: %s',
				$stats['examined'],
				$stats['deleted'],
				$bytes_human ? $bytes_human : '0 B',
				$stats['bytes'],
				$stats['completed'] ? 'yes' : 'no'
			)
		);
	}
}

WP_CLI::add_command( 'extrachill-cache gc', 'Extrachill_Cache_GC_Command' );
