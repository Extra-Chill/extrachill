<?php
/**
 * 404 Error Analysis CLI Commands
 *
 * Thin CLI wrappers around the extrachill-analytics 404 abilities.
 * All query logic lives in the ability callbacks; this class handles
 * argument parsing, formatting, and presentation only.
 *
 * @package ExtraChill\CLI\Commands\Analytics
 */

namespace ExtraChill\CLI\Commands\Analytics;

use WP_CLI;
use WP_CLI\Utils;
use ExtraChill\CLI\Traits\NetworkAwareTrait;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FourOhFourCommand {

	use NetworkAwareTrait;

	/**
	 * List recent 404 errors.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<days>]
	 * : Number of days to look back.
	 * ---
	 * default: 7
	 * ---
	 *
	 * [--limit=<limit>]
	 * : Maximum number of results.
	 * ---
	 * default: 50
	 * ---
	 *
	 * [--site=<site>]
	 * : Filter by site. Use a blog ID, 'all' for network-wide, or omit for current site.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill analytics 404 list
	 *     wp extrachill analytics 404 list --days=1 --limit=20
	 *     wp extrachill analytics 404 list --site=7
	 *     wp extrachill analytics 404 list --format=json
	 *
	 * @subcommand list
	 */
	public function list_errors( $args, $assoc_args ) {
		$ability = $this->get_ability( 'extrachill/list-404-events' );

		$blog_id = $this->get_site_filter( $assoc_args );
		$days    = (int) ( $assoc_args['days'] ?? 7 );
		$limit   = (int) ( $assoc_args['limit'] ?? 50 );
		$format  = $assoc_args['format'] ?? 'table';

		$result = $ability->execute( array(
			'days'    => $days,
			'blog_id' => $blog_id,
			'limit'   => $limit,
		) );

		$this->handle_error( $result );

		if ( empty( $result ) ) {
			WP_CLI::success( 'No 404 errors in the last ' . $days . ' days.' );
			return;
		}

		$rows = array();
		foreach ( $result as $event ) {
			$rows[] = array(
				'url'        => $event['url'],
				'referer'    => $this->truncate( $event['referer'], 40 ),
				'user_agent' => $this->truncate( $this->simplify_ua( $event['user_agent'] ), 30 ),
				'date'       => $event['date'],
			);
		}

		Utils\format_items( $format, $rows, array( 'url', 'referer', 'user_agent', 'date' ) );
	}

	/**
	 * Show top 404 URLs by hit count.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<days>]
	 * : Number of days to look back.
	 * ---
	 * default: 7
	 * ---
	 *
	 * [--limit=<limit>]
	 * : Number of top URLs to show.
	 * ---
	 * default: 30
	 * ---
	 *
	 * [--min-hits=<min>]
	 * : Minimum hit count to include.
	 * ---
	 * default: 2
	 * ---
	 *
	 * [--site=<site>]
	 * : Filter by site. Use a blog ID, 'all' for network-wide, or omit for current site.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill analytics 404 top
	 *     wp extrachill analytics 404 top --days=30 --min-hits=5
	 *     wp extrachill analytics 404 top --site=7
	 *
	 * @subcommand top
	 */
	public function top( $args, $assoc_args ) {
		$ability = $this->get_ability( 'extrachill/get-404-top-urls' );

		$blog_id  = $this->get_site_filter( $assoc_args );
		$days     = (int) ( $assoc_args['days'] ?? 7 );
		$limit    = (int) ( $assoc_args['limit'] ?? 30 );
		$min_hits = (int) ( $assoc_args['min-hits'] ?? 2 );
		$format   = $assoc_args['format'] ?? 'table';

		$result = $ability->execute( array(
			'days'     => $days,
			'blog_id'  => $blog_id,
			'limit'    => $limit,
			'min_hits' => $min_hits,
		) );

		$this->handle_error( $result );

		if ( empty( $result ) ) {
			WP_CLI::success( 'No 404 URLs with ' . $min_hits . '+ hits in the last ' . $days . ' days.' );
			return;
		}

		Utils\format_items( $format, $result, array( 'url', 'hits', 'last_seen', 'category' ) );

		if ( 'table' === $format ) {
			$total = array_sum( array_column( $result, 'hits' ) );
			WP_CLI::log( '' );
			WP_CLI::log( sprintf( 'Total: %d hits across %d unique URLs', $total, count( $result ) ) );
		}
	}

	/**
	 * Show 404 errors grouped by pattern category.
	 *
	 * Categories include: legacy-html, missing-upload, bot-probe, ad-txt,
	 * sql-injection, ad-sponsor-probe, content, community-thread, events,
	 * author-enum, old-sitemap, and more.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<days>]
	 * : Number of days to look back.
	 * ---
	 * default: 7
	 * ---
	 *
	 * [--site=<site>]
	 * : Filter by site. Use a blog ID, 'all' for network-wide, or omit for current site.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill analytics 404 patterns
	 *     wp extrachill analytics 404 patterns --days=30
	 *     wp extrachill analytics 404 patterns --site=7
	 *
	 * @subcommand patterns
	 */
	public function patterns( $args, $assoc_args ) {
		$ability = $this->get_ability( 'extrachill/get-404-patterns' );

		$blog_id = $this->get_site_filter( $assoc_args );
		$days    = (int) ( $assoc_args['days'] ?? 7 );
		$format  = $assoc_args['format'] ?? 'table';

		$result = $ability->execute( array(
			'days'    => $days,
			'blog_id' => $blog_id,
		) );

		$this->handle_error( $result );

		if ( empty( $result ) ) {
			WP_CLI::success( 'No 404 errors in the last ' . $days . ' days.' );
			return;
		}

		// Convert actionable boolean to yes/no string for table display.
		$rows = array();
		foreach ( $result as $row ) {
			$rows[] = array(
				'category'    => $row['category'],
				'hits'        => $row['hits'],
				'unique_urls' => $row['unique_urls'],
				'pct'         => $row['pct'],
				'actionable'  => $row['actionable'] ? 'yes' : 'no',
			);
		}

		Utils\format_items( $format, $rows, array( 'category', 'hits', 'unique_urls', 'pct', 'actionable' ) );

		if ( 'table' === $format ) {
			$total_hits = array_sum( array_column( $rows, 'hits' ) );
			$total_urls = array_sum( array_column( $rows, 'unique_urls' ) );
			WP_CLI::log( '' );
			WP_CLI::log( sprintf( 'Total: %d hits across %d unique URLs in %d categories', $total_hits, $total_urls, count( $rows ) ) );
		}
	}

	/**
	 * Show top 404 URLs for a specific pattern category.
	 *
	 * ## OPTIONS
	 *
	 * <category>
	 * : Pattern category to drill into.
	 * ---
	 * options:
	 *   - legacy-html
	 *   - missing-upload
	 *   - content
	 *   - bot-probe
	 *   - ad-txt
	 *   - ad-sponsor-probe
	 *   - sql-injection
	 *   - author-enum
	 *   - community-thread
	 *   - events
	 *   - festival
	 *   - old-sitemap
	 *   - php-probe
	 *   - plugin-probe
	 *   - wp-includes-probe
	 *   - date-prefix
	 *   - join-page
	 * ---
	 *
	 * [--days=<days>]
	 * : Number of days to look back.
	 * ---
	 * default: 7
	 * ---
	 *
	 * [--limit=<limit>]
	 * : Number of URLs to show.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--site=<site>]
	 * : Filter by site. Use a blog ID, 'all' for network-wide, or omit for current site.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill analytics 404 drill legacy-html
	 *     wp extrachill analytics 404 drill content --days=30
	 *     wp extrachill analytics 404 drill missing-upload --limit=50
	 *     wp extrachill analytics 404 drill content --site=7
	 *
	 * @subcommand drill
	 */
	public function drill( $args, $assoc_args ) {
		$ability = $this->get_ability( 'extrachill/drill-404-category' );

		$blog_id  = $this->get_site_filter( $assoc_args );
		$category = $args[0];
		$days     = (int) ( $assoc_args['days'] ?? 7 );
		$limit    = (int) ( $assoc_args['limit'] ?? 20 );
		$format   = $assoc_args['format'] ?? 'table';

		$result = $ability->execute( array(
			'category' => $category,
			'days'     => $days,
			'blog_id'  => $blog_id,
			'limit'    => $limit,
		) );

		$this->handle_error( $result );

		if ( empty( $result ) ) {
			WP_CLI::success( "No '{$category}' 404s in the last {$days} days." );
			return;
		}

		// Determine fields based on whether the result includes redirect info.
		$has_slug = ! empty( $result[0]['slug'] );
		$fields   = $has_slug
			? array( 'url', 'hits', 'slug', 'post_id', 'fixable', 'last_seen' )
			: array( 'url', 'hits', 'last_seen' );

		Utils\format_items( $format, $result, $fields );

		if ( 'table' === $format ) {
			$total_hits = array_sum( array_column( $result, 'hits' ) );
			WP_CLI::log( '' );
			WP_CLI::log( sprintf( '%d URLs, %d total hits', count( $result ), $total_hits ) );
		}
	}

	/**
	 * Show summary statistics for 404 errors.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<days>]
	 * : Number of days to look back.
	 * ---
	 * default: 7
	 * ---
	 *
	 * [--site=<site>]
	 * : Filter by site. Use a blog ID, 'all' for network-wide, or omit for current site.
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill analytics 404 summary
	 *     wp extrachill analytics 404 summary --days=30
	 *     wp extrachill analytics 404 summary --site=7
	 *     wp extrachill analytics 404 summary --site=all
	 *
	 * @subcommand summary
	 */
	public function summary( $args, $assoc_args ) {
		$ability = $this->get_ability( 'extrachill/get-404-summary' );

		$blog_id = $this->get_site_filter( $assoc_args );
		$days    = (int) ( $assoc_args['days'] ?? 7 );

		$result = $ability->execute( array(
			'days'    => $days,
			'blog_id' => $blog_id,
		) );

		$this->handle_error( $result );

		$site_label = $this->format_site_label();
		WP_CLI::log( sprintf( '404 Error Summary — Last %d days (%s)', $days, $site_label ) );
		WP_CLI::log( str_repeat( '─', 40 ) );
		WP_CLI::log( sprintf( 'Total hits:     %s', number_format( $result['total'] ) ) );
		WP_CLI::log( sprintf( 'Unique URLs:    %s', number_format( $result['unique_urls'] ) ) );
		WP_CLI::log( sprintf( 'Unique visitors: %s', number_format( $result['unique_ips'] ) ) );
		WP_CLI::log( sprintf( 'Per day avg:    %s', $result['per_day_avg'] ) );
		WP_CLI::log( '' );

		if ( ! empty( $result['daily'] ) ) {
			WP_CLI::log( 'Daily breakdown:' );
			$max_hits = max( array_merge( array( 0 ), array_column( $result['daily'], 'hits' ) ) );
			foreach ( $result['daily'] as $day ) {
				$bar_width = $max_hits > 0 ? (int) ( $day['hits'] / $max_hits * 40 ) : 0;
				$bar       = str_repeat( '█', max( $bar_width, 0 ) );
				WP_CLI::log( sprintf( '  %s  %5d  %s', $day['date'], $day['hits'], $bar ) );
			}
		}
	}

	/**
	 * Purge 404 error events older than a given number of days.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<days>]
	 * : Delete events older than this many days.
	 * ---
	 * default: 30
	 * ---
	 *
	 * [--site=<site>]
	 * : Filter by site. Use a blog ID, 'all' for network-wide, or omit for current site.
	 *
	 * [--dry-run]
	 * : Show what would be deleted without deleting.
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill analytics 404 purge --days=30
	 *     wp extrachill analytics 404 purge --days=7 --dry-run
	 *     wp extrachill analytics 404 purge --days=14 --site=7
	 *
	 * @subcommand purge
	 */
	public function purge( $args, $assoc_args ) {
		$ability = $this->get_ability( 'extrachill/purge-404-events' );

		$blog_id = $this->get_site_filter( $assoc_args );
		$days    = (int) ( $assoc_args['days'] ?? 30 );
		$dry_run = Utils\get_flag_value( $assoc_args, 'dry-run', false );

		$site_label = $this->format_site_label();

		if ( $dry_run ) {
			$result = $ability->execute( array(
				'days'    => $days,
				'blog_id' => $blog_id,
				'dry_run' => true,
			) );

			$this->handle_error( $result );

			WP_CLI::log( sprintf(
				'Would delete %s 404 events older than %d days on %s.',
				number_format( $result['count'] ),
				$days,
				$site_label
			) );
			return;
		}

		// Get count first to confirm.
		$preview = $ability->execute( array(
			'days'    => $days,
			'blog_id' => $blog_id,
			'dry_run' => true,
		) );

		$this->handle_error( $preview );

		if ( 0 === (int) $preview['count'] ) {
			WP_CLI::success( sprintf( 'No 404 events older than %d days on %s.', $days, $site_label ) );
			return;
		}

		WP_CLI::confirm( sprintf(
			'Delete %s 404 events older than %d days on %s?',
			number_format( $preview['count'] ),
			$days,
			$site_label
		) );

		$result = $ability->execute( array(
			'days'    => $days,
			'blog_id' => $blog_id,
			'dry_run' => false,
		) );

		$this->handle_error( $result );

		WP_CLI::success( sprintf( 'Purged %s 404 events on %s.', number_format( $result['count'] ), $site_label ) );
	}

	/**
	 * Show top IP addresses generating 404 errors.
	 *
	 * Identifies concentrated attack sources by ranking hashed IPs
	 * by hit count with metadata about their behavior.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<days>]
	 * : Number of days to look back.
	 * ---
	 * default: 7
	 * ---
	 *
	 * [--limit=<limit>]
	 * : Number of top IPs to show.
	 * ---
	 * default: 15
	 * ---
	 *
	 * [--site=<site>]
	 * : Filter by site. Use a blog ID, 'all' for network-wide, or omit for current site.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill analytics 404 top-ips
	 *     wp extrachill analytics 404 top-ips --days=30
	 *     wp extrachill analytics 404 top-ips --limit=5
	 *     wp extrachill analytics 404 top-ips --site=7
	 *
	 * @subcommand top-ips
	 */
	public function top_ips( $args, $assoc_args ) {
		$ability = $this->get_ability( 'extrachill/get-404-top-ips' );

		$blog_id = $this->get_site_filter( $assoc_args );
		$days    = (int) ( $assoc_args['days'] ?? 7 );
		$limit   = (int) ( $assoc_args['limit'] ?? 15 );
		$format  = $assoc_args['format'] ?? 'table';

		$result = $ability->execute( array(
			'days'    => $days,
			'blog_id' => $blog_id,
			'limit'   => $limit,
		) );

		$this->handle_error( $result );

		if ( empty( $result ) ) {
			WP_CLI::success( 'No 404 errors in the last ' . $days . ' days.' );
			return;
		}

		// Simplify for table display.
		$rows = array();
		foreach ( $result as $row ) {
			$rows[] = array(
				'ip_hash'     => substr( $row['ip_hash'], 0, 12 ) . '…',
				'hits'        => $row['hits'],
				'unique_urls' => $row['unique_urls'],
				'first_seen'  => $row['first_seen'],
				'last_seen'   => $row['last_seen'],
				'top_ua'      => $this->truncate( $this->simplify_ua( $row['top_ua'] ), 20 ),
			);
		}

		$site_label = $this->format_site_label();
		if ( 'table' === $format ) {
			WP_CLI::log( sprintf( 'Top 404 IPs — Last %d days (%s)', $days, $site_label ) );
			WP_CLI::log( str_repeat( '─', 80 ) );
		}

		Utils\format_items( $format, $rows, array( 'ip_hash', 'hits', 'unique_urls', 'first_seen', 'last_seen', 'top_ua' ) );

		if ( 'table' === $format ) {
			$total_hits = array_sum( array_column( $rows, 'hits' ) );
			WP_CLI::log( '' );
			WP_CLI::log( sprintf( 'Top %d IPs account for %s hits', count( $rows ), number_format( $total_hits ) ) );
		}
	}

	// --- Helpers (presentation only — no query logic) ---

	/**
	 * Get an ability or die with a helpful error.
	 *
	 * @param string $ability_name The ability identifier.
	 * @return \WP_Ability The ability instance.
	 */
	private function get_ability( $ability_name ) {
		$ability = wp_get_ability( $ability_name );

		if ( ! $ability ) {
			WP_CLI::error( sprintf(
				'%s ability not found. Is extrachill-analytics active?',
				$ability_name
			) );
		}

		return $ability;
	}

	/**
	 * Handle WP_Error results from ability execution.
	 *
	 * @param mixed $result Ability result.
	 */
	private function handle_error( $result ) {
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
	}

	/**
	 * Simplify a user agent string to a readable label.
	 *
	 * @param string $ua Full user agent string.
	 * @return string Simplified label.
	 */
	private function simplify_ua( $ua ) {
		if ( empty( $ua ) ) {
			return '(empty)';
		}

		$patterns = array(
			'facebookexternalhit' => 'Facebook',
			'Googlebot'           => 'Googlebot',
			'bingbot'             => 'Bingbot',
			'Verity'              => 'GumGum/Verity',
			'gumgum'              => 'GumGum/Verity',
			'Grammarly'           => 'Grammarly',
			'axios'               => 'Axios bot',
			'Mediavine'           => 'Mediavine',
			'Chrome'              => 'Chrome',
			'Firefox'             => 'Firefox',
			'Safari'              => 'Safari',
			'curl'                => 'curl',
		);

		foreach ( $patterns as $needle => $label ) {
			if ( false !== stripos( $ua, $needle ) ) {
				return $label;
			}
		}

		return $this->truncate( $ua, 30 );
	}

	/**
	 * Truncate a string with ellipsis.
	 *
	 * @param string $str    Input string.
	 * @param int    $length Max length.
	 * @return string Truncated string.
	 */
	private function truncate( $str, $length = 50 ) {
		if ( strlen( $str ) <= $length ) {
			return $str;
		}
		return substr( $str, 0, $length - 1 ) . '…';
	}
}
