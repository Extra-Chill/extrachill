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
 * WP-CLI command. Usage and options are documented on __invoke(), where
 * WP-CLI reads them for invokable commands.
 */
class Extrachill_Cache_GC_Command extends WP_CLI_Command {

	/**
	 * Run garbage collection for the page cache.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report projected expiry and budget evictions without deleting anything.
	 *
	 * [--file-limit=<limit>]
	 * : Maximum files to examine for expiry. Default 2000.
	 *
	 * [--time-budget=<seconds>]
	 * : Maximum seconds to spend walking for expiry. Default 30.
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill-cache gc
	 *     wp extrachill-cache gc --dry-run
	 *     wp extrachill-cache gc --file-limit=5000 --time-budget=60
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ) {
		$dry_run = WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$gc_args = array( 'dry_run' => $dry_run );
		if ( isset( $assoc_args['file-limit'] ) ) {
			$gc_args['file_limit'] = (int) $assoc_args['file-limit'];
		}
		if ( isset( $assoc_args['time-budget'] ) ) {
			$gc_args['time_budget'] = (int) $assoc_args['time-budget'];
		}

		WP_CLI::log( sprintf( 'Running Extra Chill Cache GC (dry-run: %s)...', $dry_run ? 'yes' : 'no' ) );

		$stats = extrachill_cache_gc( $gc_args );

		$bytes_human = size_format( $stats['bytes'] );

		foreach ( $stats['partitions'] as $blog_id => $usage ) {
			WP_CLI::log(
				sprintf(
					'Blog %d%s: %d files, %d / %d bytes%s',
					$blog_id,
					$dry_run ? ' (projected)' : '',
					$usage['files'],
					$usage['bytes'],
					$usage['max_bytes'],
					$usage['bytes'] > $usage['max_bytes'] ? ' (over budget)' : ''
				)
			);
		}

		WP_CLI::success(
			sprintf(
				'GC finished. Examined for expiry: %d | %s: %d | %s: %s (%d bytes) | Completed expiry scan: %s',
				$stats['examined'],
				$dry_run ? 'Would delete' : 'Deleted',
				$stats['deleted'],
				$dry_run ? 'Would reclaim' : 'Reclaimed',
				$bytes_human ? $bytes_human : '0 B',
				$stats['bytes'],
				$stats['completed'] ? 'yes' : 'no'
			)
		);
	}
}

WP_CLI::add_command( 'extrachill-cache gc', 'Extrachill_Cache_GC_Command' );

/**
 * WP-CLI command. Usage and options are documented on __invoke(), where
 * WP-CLI reads them for invokable commands.
 */
// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Keep the two invokable commands registered together in this CLI-only module.
class Extrachill_Cache_Purge_Command extends WP_CLI_Command {

	/**
	 * Purge the page cache.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Purge the entire cache tree for every site in the network.
	 *
	 * [--site=<id>]
	 * : Purge only this site's (blog ID) cache partition. Named --site because
	 *   --blog is a reserved WP-CLI global flag.
	 *
	 * With neither flag, purges the site selected by the global --url (the
	 * current blog).
	 *
	 * ## EXAMPLES
	 *
	 *     # After a deploy that changed enqueued assets or templates.
	 *     wp extrachill-cache purge --all
	 *
	 *     # One site only.
	 *     wp extrachill-cache purge --site=4
	 *
	 *     # The site selected by --url.
	 *     wp --url=https://events.extrachill.com extrachill-cache purge
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ) {
		$all  = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'all', false );
		$blog = WP_CLI\Utils\get_flag_value( $assoc_args, 'site', null );

		if ( $all && null !== $blog ) {
			WP_CLI::error( 'Use either --all or --site, not both.' );
		}

		if ( $all ) {
			extrachill_cache_purge_all();
			WP_CLI::success( 'Purged the page cache for every site.' );
			return;
		}

		if ( null === $blog ) {
			extrachill_cache_purge_current_blog();
			WP_CLI::success( sprintf( 'Purged the page cache for blog %d.', get_current_blog_id() ) );
			return;
		}

		$blog_id = absint( $blog );
		if ( ! $blog_id || ( function_exists( 'get_site' ) && ! get_site( $blog_id ) ) ) {
			WP_CLI::error( sprintf( 'Unknown site: %s', $blog ) );
		}

		$switched = function_exists( 'switch_to_blog' ) && get_current_blog_id() !== $blog_id;
		if ( $switched ) {
			switch_to_blog( $blog_id );
		}
		try {
			extrachill_cache_purge_current_blog();
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
		WP_CLI::success( sprintf( 'Purged the page cache for blog %d.', $blog_id ) );
	}
}

WP_CLI::add_command( 'extrachill-cache purge', 'Extrachill_Cache_Purge_Command' );
