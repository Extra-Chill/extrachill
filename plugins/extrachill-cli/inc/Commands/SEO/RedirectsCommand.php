<?php
/**
 * SEO Redirect Rules CLI Commands
 *
 * Manage URL redirect rules stored in the extrachill-seo redirect table.
 * Delegates to functions in extrachill-seo/inc/core/redirects-db.php.
 *
 * @package ExtraChill\CLI\Commands\SEO
 */

namespace ExtraChill\CLI\Commands\SEO;

use WP_CLI;
use WP_CLI\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RedirectsCommand {

	/**
	 * List redirect rules.
	 *
	 * ## OPTIONS
	 *
	 * [--search=<search>]
	 * : Search from_url or to_url.
	 *
	 * [--active=<active>]
	 * : Filter by active status. -1 for all.
	 * ---
	 * default: -1
	 * ---
	 *
	 * [--orderby=<orderby>]
	 * : Column to sort by.
	 * ---
	 * default: created_at
	 * options:
	 *   - created_at
	 *   - hit_count
	 *   - from_url
	 *   - last_hit
	 * ---
	 *
	 * [--order=<order>]
	 * : Sort direction.
	 * ---
	 * default: DESC
	 * options:
	 *   - ASC
	 *   - DESC
	 * ---
	 *
	 * [--limit=<limit>]
	 * : Number of results.
	 * ---
	 * default: 50
	 * ---
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
	 *     wp extrachill seo redirects list
	 *     wp extrachill seo redirects list --orderby=hit_count --order=DESC
	 *     wp extrachill seo redirects list --search=grateful-dead
	 *
	 * @subcommand list
	 */
	public function list_rules( $args, $assoc_args ) {
		$this->ensure_seo();

		$rules = \ExtraChill\SEO\Core\extrachill_seo_get_redirects(
			array(
				'search'  => $assoc_args['search'] ?? '',
				'active'  => (int) ( $assoc_args['active'] ?? -1 ),
				'orderby' => $assoc_args['orderby'] ?? 'created_at',
				'order'   => $assoc_args['order'] ?? 'DESC',
				'limit'   => (int) ( $assoc_args['limit'] ?? 50 ),
			)
		);

		$total = \ExtraChill\SEO\Core\extrachill_seo_count_redirects(
			array(
				'search' => $assoc_args['search'] ?? '',
				'active' => (int) ( $assoc_args['active'] ?? -1 ),
			)
		);

		if ( empty( $rules ) ) {
			WP_CLI::log( 'No redirect rules found.' );
			return;
		}

		$format = $assoc_args['format'] ?? 'table';

		$rows = array();
		foreach ( $rules as $rule ) {
			$rows[] = array(
				'id'          => $rule->id,
				'from'        => $rule->from_url,
				'to'          => $rule->to_url,
				'code'        => $rule->status_code,
				'hits'        => number_format( $rule->hit_count ),
				'last_hit'    => $rule->last_hit ?: '—',
				'active'      => $rule->active ? 'yes' : 'no',
				'note'        => $rule->note,
				'created'     => $rule->created_at,
			);
		}

		Utils\format_items( $format, $rows, array( 'id', 'from', 'to', 'code', 'hits', 'last_hit', 'active', 'note', 'created' ) );

		WP_CLI::log( '' );
		WP_CLI::log( sprintf( 'Showing %d of %d total rules', count( $rows ), $total ) );
	}

	/**
	 * Add a redirect rule.
	 *
	 * ## OPTIONS
	 *
	 * <from>
	 * : Source URL path (e.g., /old-page or /ozzy-osbourne-bat).
	 *
	 * <to>
	 * : Destination URL or path (e.g., /new-page/ or https://example.com/page).
	 *
	 * [--code=<code>]
	 * : HTTP status code.
	 * ---
	 * default: 301
	 * options:
	 *   - 301
	 *   - 302
	 * ---
	 *
	 * [--note=<note>]
	 * : Optional note about why this redirect exists.
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill seo redirects add /old-page /new-page/
	 *     wp extrachill seo redirects add /ozzy-osbourne-bat /ozzy-osbourne-bit-bat-head/ --note="Slug was shortened"
	 *     wp extrachill seo redirects add /festival/ /events/ --code=302
	 *
	 * @subcommand add
	 */
	public function add( $args, $assoc_args ) {
		$this->ensure_seo();

		$from = $args[0];
		$to   = $args[1];
		$code = (int) ( $assoc_args['code'] ?? 301 );
		$note = $assoc_args['note'] ?? '';

		$id = \ExtraChill\SEO\Core\extrachill_seo_add_redirect( $from, $to, $code, $note, 'cli' );

		if ( false === $id ) {
			WP_CLI::error( sprintf( 'Failed — a redirect rule may already exist for %s', $from ) );
		}

		WP_CLI::success( sprintf( 'Redirect #%d created: %s → %s (%d)', $id, $from, $to, $code ) );
	}

	/**
	 * Remove a redirect rule.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Redirect rule ID to delete.
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill seo redirects remove 42
	 *
	 * @subcommand remove
	 */
	public function remove( $args, $assoc_args ) {
		$this->ensure_seo();

		$id      = (int) $args[0];
		$deleted = \ExtraChill\SEO\Core\extrachill_seo_delete_redirect( $id );

		if ( ! $deleted ) {
			WP_CLI::error( sprintf( 'Redirect #%d not found.', $id ) );
		}

		WP_CLI::success( sprintf( 'Redirect #%d deleted.', $id ) );
	}

	/**
	 * Test a URL against the redirect rules table.
	 *
	 * ## OPTIONS
	 *
	 * <url>
	 * : URL path to test (e.g., /old-page).
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill seo redirects test /ozzy-osbourne-bat
	 *
	 * @subcommand test
	 */
	public function test( $args, $assoc_args ) {
		$this->ensure_seo();

		$url  = $args[0];
		$rule = \ExtraChill\SEO\Core\extrachill_seo_get_redirect_by_url( $url );

		if ( ! $rule ) {
			WP_CLI::log( sprintf( 'No redirect rule matches: %s', $url ) );
			return;
		}

		WP_CLI::log( sprintf( 'Match found — Rule #%d', $rule->id ) );
		WP_CLI::log( sprintf( '  From:    %s', $rule->from_url ) );
		WP_CLI::log( sprintf( '  To:      %s', $rule->to_url ) );
		WP_CLI::log( sprintf( '  Code:    %d', $rule->status_code ) );
		WP_CLI::log( sprintf( '  Hits:    %s', number_format( $rule->hit_count ) ) );
		WP_CLI::log( sprintf( '  Active:  %s', $rule->active ? 'yes' : 'no' ) );
	}

	/**
	 * Import redirects from 404 analysis.
	 *
	 * Scans the top 404 URLs in the analytics table, matches them against
	 * published posts by slug, and creates redirect rules for any matches.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<days>]
	 * : Number of days of 404 data to analyze.
	 * ---
	 * default: 30
	 * ---
	 *
	 * [--min-hits=<min>]
	 * : Minimum hit count to consider.
	 * ---
	 * default: 3
	 * ---
	 *
	 * [--category=<category>]
	 * : Only import from a specific 404 category.
	 * ---
	 * default: all
	 * options:
	 *   - all
	 *   - legacy-html
	 *   - content
	 *   - date-prefix
	 * ---
	 *
	 * [--dry-run]
	 * : Show what would be imported without creating rules.
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill seo redirects import --dry-run
	 *     wp extrachill seo redirects import --category=legacy-html --min-hits=5
	 *     wp extrachill seo redirects import --days=7
	 *
	 * @subcommand import
	 */
	public function import( $args, $assoc_args ) {
		$this->ensure_seo();
		$this->ensure_analytics();

		global $wpdb;

		$days     = (int) ( $assoc_args['days'] ?? 30 );
		$min_hits = (int) ( $assoc_args['min-hits'] ?? 3 );
		$category = $assoc_args['category'] ?? 'all';
		$dry_run  = Utils\get_flag_value( $assoc_args, 'dry-run', false );

		$table     = extrachill_analytics_events_table();
		$date_from = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		// Get top 404 URLs.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
					JSON_UNQUOTE(JSON_EXTRACT(event_data, '$.requested_url')) AS url,
					COUNT(*) AS hits
				FROM {$table}
				WHERE event_type = '404_error'
				AND created_at >= %s
				GROUP BY url
				HAVING hits >= %d
				ORDER BY hits DESC",
				$date_from,
				$min_hits
			)
		);

		if ( empty( $results ) ) {
			WP_CLI::log( 'No 404 URLs with enough hits to import.' );
			return;
		}

		$importable_categories = array( 'legacy-html', 'content', 'date-prefix' );
		$created               = 0;
		$skipped_no_match      = 0;
		$skipped_exists        = 0;
		$skipped_category      = 0;
		$rows                  = array();

		foreach ( $results as $row ) {
			$url      = $row->url;
			$url_cat  = $this->categorize_url( $url );

			// Category filter.
			if ( 'all' !== $category && $url_cat !== $category ) {
				$skipped_category++;
				continue;
			}

			if ( ! in_array( $url_cat, $importable_categories, true ) ) {
				$skipped_category++;
				continue;
			}

			$slug = $this->extract_slug( $url );
			if ( empty( $slug ) ) {
				$skipped_no_match++;
				continue;
			}

			$post_id = $this->find_post_by_slug( $slug );
			if ( ! $post_id ) {
				$skipped_no_match++;
				continue;
			}

			$permalink = get_permalink( $post_id );
			$from_path = '/' . ltrim( $url, '/' );
			$from_path = untrailingslashit( $from_path );

			// Check if redirect already exists.
			$existing = \ExtraChill\SEO\Core\extrachill_seo_get_redirect_by_url( $from_path );
			if ( $existing ) {
				$skipped_exists++;
				continue;
			}

			$rows[] = array(
				'from'    => $from_path,
				'to'      => wp_make_link_relative( $permalink ),
				'hits'    => (int) $row->hits,
				'post_id' => $post_id,
				'cat'     => $url_cat,
			);

			if ( ! $dry_run ) {
				$note = sprintf( 'Auto-imported from 404 data (%d hits, post #%d)', $row->hits, $post_id );
				$id   = \ExtraChill\SEO\Core\extrachill_seo_add_redirect( $from_path, $permalink, 301, $note, 'cli-import' );
				if ( $id ) {
					$created++;
				}
			} else {
				$created++;
			}
		}

		// Show what was found.
		if ( ! empty( $rows ) ) {
			$label = $dry_run ? 'Would create' : 'Created';
			WP_CLI::log( sprintf( '%s %d redirect rules:', $label, count( $rows ) ) );
			WP_CLI::log( '' );

			Utils\format_items( 'table', $rows, array( 'from', 'to', 'hits', 'post_id', 'cat' ) );
		}

		WP_CLI::log( '' );
		WP_CLI::log( sprintf( '%s: %d', $dry_run ? 'Would create' : 'Created', $created ) );
		WP_CLI::log( sprintf( 'Skipped (no matching post): %d', $skipped_no_match ) );
		WP_CLI::log( sprintf( 'Skipped (rule exists): %d', $skipped_exists ) );
		WP_CLI::log( sprintf( 'Skipped (wrong category): %d', $skipped_category ) );

		if ( $dry_run && $created > 0 ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'Run without --dry-run to create these rules.' );
		}
	}

	// ─── Helpers ───────────────────────────────────────────────────────────

	/**
	 * Categorize a URL (mirrors FourOhFourCommand logic).
	 */
	private function categorize_url( $url ) {
		if ( preg_match( '#\.html#i', $url ) ) {
			return 'legacy-html';
		}
		if ( preg_match( '#^/wp-content/#', $url ) ) {
			return 'missing-upload';
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
		return 'content';
	}

	/**
	 * Extract a post slug from a URL.
	 */
	private function extract_slug( $url ) {
		$url = strtok( $url, '?' );
		$url = strtok( $url, '#' );
		$url = preg_replace( '#\.html/?$#', '', $url );
		$url = preg_replace( '#^/\d{4}/\d{2}/#', '/', $url );
		$slug = trim( $url, '/' );
		if ( strpos( $slug, '/' ) !== false ) {
			$parts = explode( '/', $slug );
			$slug  = end( $parts );
		}
		return sanitize_title( $slug );
	}

	/**
	 * Find a published post by slug.
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
	 * Ensure extrachill-seo redirect functions are available.
	 */
	private function ensure_seo() {
		if ( ! function_exists( 'ExtraChill\SEO\Core\extrachill_seo_get_redirects' ) ) {
			WP_CLI::error( 'extrachill-seo plugin is not active or redirect functions not loaded.' );
		}
	}

	/**
	 * Ensure extrachill-analytics is available.
	 */
	private function ensure_analytics() {
		if ( ! function_exists( 'extrachill_get_analytics_events' ) ) {
			WP_CLI::error( 'extrachill-analytics plugin is not active. Required for import command.' );
		}
	}
}
