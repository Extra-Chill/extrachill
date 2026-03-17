<?php
/**
 * 404 Error Analysis CLI Commands
 *
 * Provides tools for analyzing, categorizing, and managing 404 errors
 * tracked by the extrachill-analytics plugin.
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
		$this->ensure_analytics();

		$blog_id = $this->get_site_filter( $assoc_args );
		$days    = (int) ( $assoc_args['days'] ?? 7 );
		$limit   = (int) ( $assoc_args['limit'] ?? 50 );
		$format  = $assoc_args['format'] ?? 'table';

		$query_args = array(
			'event_type' => '404_error',
			'date_from'  => gmdate( 'Y-m-d', strtotime( "-{$days} days" ) ),
			'limit'      => $limit,
		);

		if ( $blog_id > 0 ) {
			$query_args['blog_id'] = $blog_id;
		}

		$events = extrachill_get_analytics_events( $query_args );

		if ( empty( $events ) ) {
			WP_CLI::success( 'No 404 errors in the last ' . $days . ' days.' );
			return;
		}

		$rows = array();
		foreach ( $events as $event ) {
			$data   = $event->event_data;
			$rows[] = array(
				'url'        => $data['requested_url'] ?? '',
				'referer'    => $this->truncate( $data['referer'] ?? '', 40 ),
				'user_agent' => $this->truncate( $this->simplify_ua( $data['user_agent'] ?? '' ), 30 ),
				'date'       => $event->created_at,
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
		$this->ensure_analytics();

		global $wpdb;

		$this->get_site_filter( $assoc_args );
		$days     = (int) ( $assoc_args['days'] ?? 7 );
		$limit    = (int) ( $assoc_args['limit'] ?? 30 );
		$min_hits = (int) ( $assoc_args['min-hits'] ?? 2 );
		$format   = $assoc_args['format'] ?? 'table';

		$table      = extrachill_analytics_events_table();
		$date_from  = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
		$site_where = $this->get_site_where_clause();

		$sql    = "SELECT 
					JSON_UNQUOTE(JSON_EXTRACT(event_data, '$.requested_url')) AS url,
					COUNT(*) AS hits,
					MAX(created_at) AS last_seen
				FROM {$table}
				WHERE event_type = '404_error'
				AND created_at >= %s
				{$site_where['sql']}
				GROUP BY url
				HAVING hits >= %d
				ORDER BY hits DESC
				LIMIT %d";
		$values = array_merge( array( $date_from ), $site_where['values'], array( $min_hits, $limit ) );

		$results = $wpdb->get_results( $wpdb->prepare( $sql, $values ) );

		if ( empty( $results ) ) {
			WP_CLI::success( 'No 404 URLs with ' . $min_hits . '+ hits in the last ' . $days . ' days.' );
			return;
		}

		$rows = array();
		foreach ( $results as $row ) {
			$rows[] = array(
				'url'       => $row->url,
				'hits'      => (int) $row->hits,
				'last_seen' => $row->last_seen,
				'category'  => $this->categorize_url( $row->url ),
			);
		}

		Utils\format_items( $format, $rows, array( 'url', 'hits', 'last_seen', 'category' ) );

		$total = array_sum( array_column( $rows, 'hits' ) );
		WP_CLI::log( '' );
		WP_CLI::log( sprintf( 'Total: %d hits across %d unique URLs', $total, count( $rows ) ) );
	}

	/**
	 * Show 404 errors grouped by pattern category.
	 *
	 * Categories: legacy-html, missing-upload, bot-probe, ad-txt, content,
	 * community-thread, events, author-enum, old-sitemap, and more.
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
		$this->ensure_analytics();

		global $wpdb;

		$this->get_site_filter( $assoc_args );
		$days   = (int) ( $assoc_args['days'] ?? 7 );
		$format = $assoc_args['format'] ?? 'table';

		$table      = extrachill_analytics_events_table();
		$date_from  = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
		$site_where = $this->get_site_where_clause();

		$sql    = "SELECT 
					JSON_UNQUOTE(JSON_EXTRACT(event_data, '$.requested_url')) AS url,
					COUNT(*) AS hits
				FROM {$table}
				WHERE event_type = '404_error'
				AND created_at >= %s
				{$site_where['sql']}
				GROUP BY url";
		$values = array_merge( array( $date_from ), $site_where['values'] );

		$results = $wpdb->get_results( $wpdb->prepare( $sql, $values ) );

		if ( empty( $results ) ) {
			WP_CLI::success( 'No 404 errors in the last ' . $days . ' days.' );
			return;
		}

		// Aggregate by category.
		$categories = array();
		$total      = 0;

		foreach ( $results as $row ) {
			$category = $this->categorize_url( $row->url );
			if ( ! isset( $categories[ $category ] ) ) {
				$categories[ $category ] = array(
					'hits'        => 0,
					'unique_urls' => 0,
				);
			}
			$categories[ $category ]['hits']        += (int) $row->hits;
			$categories[ $category ]['unique_urls'] += 1;
			$total                                  += (int) $row->hits;
		}

		// Sort by hits descending.
		arsort( $categories );

		$rows = array();
		foreach ( $categories as $name => $data ) {
			$rows[] = array(
				'category'    => $name,
				'hits'        => $data['hits'],
				'unique_urls' => $data['unique_urls'],
				'pct'         => $total > 0 ? round( $data['hits'] / $total * 100, 1 ) . '%' : '0%',
				'actionable'  => $this->is_actionable( $name ) ? 'yes' : 'no',
			);
		}

		Utils\format_items( $format, $rows, array( 'category', 'hits', 'unique_urls', 'pct', 'actionable' ) );

		WP_CLI::log( '' );
		WP_CLI::log( sprintf( 'Total: %d hits across %d unique URLs in %d categories', $total, count( $results ), count( $categories ) ) );
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
		$this->ensure_analytics();

		global $wpdb;

		$this->get_site_filter( $assoc_args );
		$category = $args[0];
		$days     = (int) ( $assoc_args['days'] ?? 7 );
		$limit    = (int) ( $assoc_args['limit'] ?? 20 );
		$format   = $assoc_args['format'] ?? 'table';

		$table      = extrachill_analytics_events_table();
		$date_from  = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
		$site_where = $this->get_site_where_clause();

		$sql    = "SELECT 
					JSON_UNQUOTE(JSON_EXTRACT(event_data, '$.requested_url')) AS url,
					COUNT(*) AS hits,
					MAX(created_at) AS last_seen
				FROM {$table}
				WHERE event_type = '404_error'
				AND created_at >= %s
				{$site_where['sql']}
				GROUP BY url
				ORDER BY hits DESC";
		$values = array_merge( array( $date_from ), $site_where['values'] );

		$results = $wpdb->get_results( $wpdb->prepare( $sql, $values ) );

		// Filter to matching category.
		$filtered = array();
		foreach ( $results as $row ) {
			if ( $this->categorize_url( $row->url ) === $category ) {
				$filtered[] = $row;
			}
		}

		if ( empty( $filtered ) ) {
			WP_CLI::success( "No '{$category}' 404s in the last {$days} days." );
			return;
		}

		$filtered = array_slice( $filtered, 0, $limit );

		$rows = array();
		foreach ( $filtered as $row ) {
			$extra = array(
				'url'       => $row->url,
				'hits'      => (int) $row->hits,
				'last_seen' => $row->last_seen,
			);

			// For legacy-html and content, check if a matching post exists.
			if ( in_array( $category, array( 'legacy-html', 'content', 'date-prefix' ), true ) ) {
				$slug              = $this->extract_slug( $row->url );
				$post_id           = $this->find_post_by_slug( $slug );
				$extra['slug']     = $slug;
				$extra['post_id']  = $post_id ?: '—';
				$extra['fixable']  = $post_id ? 'redirect' : 'no match';
			}

			$rows[] = $extra;
		}

		$fields = array( 'url', 'hits', 'last_seen' );
		if ( in_array( $category, array( 'legacy-html', 'content', 'date-prefix' ), true ) ) {
			$fields = array( 'url', 'hits', 'slug', 'post_id', 'fixable', 'last_seen' );
		}

		Utils\format_items( $format, $rows, $fields );

		$total_hits = array_sum( array_column( $rows, 'hits' ) );
		WP_CLI::log( '' );
		WP_CLI::log( sprintf( '%d URLs, %d total hits', count( $rows ), $total_hits ) );
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
		$this->ensure_analytics();

		global $wpdb;

		$this->get_site_filter( $assoc_args );
		$days = (int) ( $assoc_args['days'] ?? 7 );

		$table      = extrachill_analytics_events_table();
		$date_from  = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
		$site_where = $this->get_site_where_clause();

		// Total count.
		$sql    = "SELECT COUNT(*) FROM {$table} WHERE event_type = '404_error' AND created_at >= %s{$site_where['sql']}";
		$values = array_merge( array( $date_from ), $site_where['values'] );
		$total  = $wpdb->get_var( $wpdb->prepare( $sql, $values ) );

		// Unique URLs.
		$sql    = "SELECT COUNT(DISTINCT JSON_UNQUOTE(JSON_EXTRACT(event_data, '$.requested_url'))) 
				FROM {$table} WHERE event_type = '404_error' AND created_at >= %s{$site_where['sql']}";
		$values = array_merge( array( $date_from ), $site_where['values'] );
		$unique = $wpdb->get_var( $wpdb->prepare( $sql, $values ) );

		// Per day average.
		$per_day = $days > 0 ? round( $total / $days, 1 ) : $total;

		// Unique IPs.
		$sql        = "SELECT COUNT(DISTINCT JSON_UNQUOTE(JSON_EXTRACT(event_data, '$.ip_hash'))) 
				FROM {$table} WHERE event_type = '404_error' AND created_at >= %s{$site_where['sql']}";
		$values     = array_merge( array( $date_from ), $site_where['values'] );
		$unique_ips = $wpdb->get_var( $wpdb->prepare( $sql, $values ) );

		// By day breakdown.
		$sql    = "SELECT DATE(created_at) as date, COUNT(*) as hits
				FROM {$table} 
				WHERE event_type = '404_error' AND created_at >= %s{$site_where['sql']}
				GROUP BY DATE(created_at)
				ORDER BY date DESC";
		$values = array_merge( array( $date_from ), $site_where['values'] );
		$by_day = $wpdb->get_results( $wpdb->prepare( $sql, $values ) );

		$site_label = $this->format_site_label();
		WP_CLI::log( sprintf( '404 Error Summary — Last %d days (%s)', $days, $site_label ) );
		WP_CLI::log( str_repeat( '─', 40 ) );
		WP_CLI::log( sprintf( 'Total hits:     %s', number_format( $total ) ) );
		WP_CLI::log( sprintf( 'Unique URLs:    %s', number_format( $unique ) ) );
		WP_CLI::log( sprintf( 'Unique visitors: %s', number_format( $unique_ips ) ) );
		WP_CLI::log( sprintf( 'Per day avg:    %s', $per_day ) );
		WP_CLI::log( '' );

		if ( ! empty( $by_day ) ) {
			WP_CLI::log( 'Daily breakdown:' );
			foreach ( $by_day as $day ) {
				$bar = str_repeat( '█', min( (int) ( $day->hits / max( $total / $days / 2, 1 ) ), 40 ) );
				WP_CLI::log( sprintf( '  %s  %5d  %s', $day->date, $day->hits, $bar ) );
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
		$this->ensure_analytics();

		global $wpdb;

		$this->get_site_filter( $assoc_args );
		$days    = (int) ( $assoc_args['days'] ?? 30 );
		$dry_run = Utils\get_flag_value( $assoc_args, 'dry-run', false );

		$table      = extrachill_analytics_events_table();
		$date_from  = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
		$site_where = $this->get_site_where_clause();
		$site_label = $this->format_site_label();

		$sql    = "SELECT COUNT(*) FROM {$table} WHERE event_type = '404_error' AND created_at < %s{$site_where['sql']}";
		$values = array_merge( array( $date_from ), $site_where['values'] );
		$count  = $wpdb->get_var( $wpdb->prepare( $sql, $values ) );

		if ( $dry_run ) {
			WP_CLI::log( sprintf( 'Would delete %s 404 events older than %d days on %s.', number_format( $count ), $days, $site_label ) );
			return;
		}

		if ( (int) $count === 0 ) {
			WP_CLI::success( sprintf( 'No 404 events older than %d days on %s.', $days, $site_label ) );
			return;
		}

		WP_CLI::confirm( sprintf( 'Delete %s 404 events older than %d days on %s?', number_format( $count ), $days, $site_label ) );

		$sql     = "DELETE FROM {$table} WHERE event_type = '404_error' AND created_at < %s{$site_where['sql']}";
		$values  = array_merge( array( $date_from ), $site_where['values'] );
		$deleted = $wpdb->query( $wpdb->prepare( $sql, $values ) );

		WP_CLI::success( sprintf( 'Purged %s 404 events on %s.', number_format( $deleted ), $site_label ) );
	}

	// ─── Helpers ───────────────────────────────────────────────────────────

	/**
	 * Categorize a 404 URL into a pattern bucket.
	 *
	 * @param string $url The requested URL.
	 * @return string Category name.
	 */
	private function categorize_url( $url ) {
		// Order matters — more specific patterns first.
		if ( preg_match( '#\.html#i', $url ) ) {
			return 'legacy-html';
		}
		if ( preg_match( '#^/wp-content/uploads/#', $url ) ) {
			return 'missing-upload';
		}
		if ( preg_match( '#^/wp-content/plugins/#', $url ) ) {
			return 'plugin-probe';
		}
		if ( preg_match( '#^/wp-includes/#', $url ) ) {
			return 'wp-includes-probe';
		}
		if ( preg_match( '#\.(php|PhP)\d?#i', $url ) ) {
			return 'php-probe';
		}
		if ( preg_match( '#^/(ads\.txt|app-ads\.txt|sellers\.json|security\.txt)$#', $url ) ) {
			return 'ad-txt';
		}
		if ( preg_match( '#^/(login|admin|cgi-bin|getcmd|ip|xmlrpc)/?$#i', $url ) ) {
			return 'bot-probe';
		}
		if ( preg_match( '#^\?author=#', $url ) || preg_match( '#^/\?author=#', $url ) ) {
			return 'author-enum';
		}
		if ( preg_match( '#^/sitemap#', $url ) ) {
			return 'old-sitemap';
		}
		if ( preg_match( '#^/t/#', $url ) ) {
			return 'community-thread';
		}
		if ( preg_match( '#^/events/#', $url ) ) {
			return 'events';
		}
		if ( preg_match( '#^/festival#', $url ) ) {
			return 'festival';
		}
		if ( preg_match( '#^/\d{4}/\d{2}/#', $url ) ) {
			return 'date-prefix';
		}
		if ( preg_match( '#^/join/?$#', $url ) ) {
			return 'join-page';
		}

		return 'content';
	}

	/**
	 * Check if a category is actionable (fixable).
	 *
	 * @param string $category Category name.
	 * @return bool Whether the category has actionable fixes.
	 */
	private function is_actionable( $category ) {
		$actionable = array(
			'legacy-html',
			'content',
			'date-prefix',
			'missing-upload',
			'ad-txt',
			'community-thread',
			'events',
			'festival',
			'old-sitemap',
			'join-page',
		);
		return in_array( $category, $actionable, true );
	}

	/**
	 * Extract a post slug from a URL.
	 *
	 * Handles:
	 *   /YYYY/MM/slug.html      → slug
	 *   /YYYY/MM/slug.html/     → slug
	 *   /YYYY/MM/slug           → slug
	 *   /slug                   → slug
	 *   /slug/                  → slug
	 *
	 * @param string $url The requested URL.
	 * @return string Extracted slug.
	 */
	private function extract_slug( $url ) {
		// Remove query string and fragment.
		$url = strtok( $url, '?' );
		$url = strtok( $url, '#' );

		// Remove .html extension.
		$url = preg_replace( '#\.html/?$#', '', $url );

		// Remove date prefix.
		$url = preg_replace( '#^/\d{4}/\d{2}/#', '/', $url );

		// Remove leading/trailing slashes.
		$slug = trim( $url, '/' );

		// Take only the last segment if there are slashes.
		if ( strpos( $slug, '/' ) !== false ) {
			$parts = explode( '/', $slug );
			$slug  = end( $parts );
		}

		return sanitize_title( $slug );
	}

	/**
	 * Find a published post by slug.
	 *
	 * @param string $slug Post slug.
	 * @return int|false Post ID or false.
	 */
	private function find_post_by_slug( $slug ) {
		if ( empty( $slug ) ) {
			return false;
		}

		$posts = get_posts(
			array(
				'name'           => $slug,
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		return ! empty( $posts ) ? $posts[0] : false;
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
		if ( stripos( $ua, 'facebookexternalhit' ) !== false ) {
			return 'Facebook';
		}
		if ( stripos( $ua, 'Googlebot' ) !== false ) {
			return 'Googlebot';
		}
		if ( stripos( $ua, 'bingbot' ) !== false ) {
			return 'Bingbot';
		}
		if ( stripos( $ua, 'Verity' ) !== false || stripos( $ua, 'gumgum' ) !== false ) {
			return 'GumGum/Verity';
		}
		if ( stripos( $ua, 'Grammarly' ) !== false ) {
			return 'Grammarly';
		}
		if ( stripos( $ua, 'axios' ) !== false ) {
			return 'Axios bot';
		}
		if ( stripos( $ua, 'Mediavine' ) !== false ) {
			return 'Mediavine';
		}
		if ( stripos( $ua, 'Chrome' ) !== false ) {
			return 'Chrome';
		}
		if ( stripos( $ua, 'Firefox' ) !== false ) {
			return 'Firefox';
		}
		if ( stripos( $ua, 'Safari' ) !== false ) {
			return 'Safari';
		}
		if ( stripos( $ua, 'curl' ) !== false ) {
			return 'curl';
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

	/**
	 * Ensure the analytics plugin is available.
	 */
	private function ensure_analytics() {
		if ( ! function_exists( 'extrachill_get_analytics_events' ) ) {
			WP_CLI::error( 'extrachill-analytics plugin is not active. Activate it first.' );
		}
		if ( ! function_exists( 'extrachill_analytics_events_table' ) ) {
			WP_CLI::error( 'extrachill-analytics database functions not available.' );
		}
	}
}
