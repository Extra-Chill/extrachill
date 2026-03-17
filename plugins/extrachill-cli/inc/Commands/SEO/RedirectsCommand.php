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
				'id'       => $rule->id,
				'from'     => $rule->from_url,
				'to'       => $rule->to_url,
				'code'     => $rule->status_code,
				'hits'     => number_format( $rule->hit_count ),
				'last_hit' => $rule->last_hit ? $rule->last_hit : '—',
				'active'   => $rule->active ? 'yes' : 'no',
				'note'     => $rule->note,
				'created'  => $rule->created_at,
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
	public function remove( $args) {
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
		$assoc_args;
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
	 * When --fuzzy is enabled, uses a 4-tier matching strategy:
	 *   1. Exact slug match (post_name = slug)
	 *   2. Slug substring match (post_name LIKE %slug%)
	 *   3. Title search with suffix filter (artist + song + "meaning" in title)
	 *   4. Best-of pattern match (best-X → the-N-best-X)
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
	 * default: 2
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
	 * [--fuzzy]
	 * : Enable fuzzy title matching when exact slug match fails.
	 *
	 * [--dry-run]
	 * : Show what would be imported without creating rules.
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill seo redirects import --dry-run
	 *     wp extrachill seo redirects import --fuzzy --dry-run --days=7 --min-hits=2
	 *     wp extrachill seo redirects import --fuzzy --dry-run --days=7 --category=content
	 *     wp extrachill seo redirects import --category=legacy-html --min-hits=5
	 *
	 * @subcommand import
	 */
	public function import( $args, $assoc_args ) {
		$this->ensure_seo();
		$this->ensure_analytics();

		global $wpdb;

		$days     = (int) ( $assoc_args['days'] ?? 30 );
		$min_hits = (int) ( $assoc_args['min-hits'] ?? 2 );
		$category = $assoc_args['category'] ?? 'all';
		$dry_run  = Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$fuzzy    = Utils\get_flag_value( $assoc_args, 'fuzzy', false );

		$table     = extrachill_analytics_events_table();
		$date_from = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		// Get top 404 URLs.
		// phpcs:disable WordPress.DB.PreparedSQL -- Table name from helper, not user input.
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
		// phpcs:enable WordPress.DB.PreparedSQL

		if ( empty( $results ) ) {
			WP_CLI::log( 'No 404 URLs with enough hits to import.' );
			return;
		}

		WP_CLI::log( sprintf( 'Found %d unique 404 URLs (min %d hits, last %d days).', count( $results ), $min_hits, $days ) );
		if ( $fuzzy ) {
			WP_CLI::log( 'Fuzzy matching enabled.' );
		}
		WP_CLI::log( '' );

		$importable_categories = array( 'legacy-html', 'content', 'date-prefix' );
		$created               = 0;
		$skipped_no_match      = 0;
		$skipped_exists        = 0;
		$skipped_category      = 0;
		$rows                  = array();

		foreach ( $results as $row ) {
			$url     = $row->url;
			$url_cat = $this->categorize_url( $url );

			// Category filter.
			if ( 'all' !== $category && $url_cat !== $category ) {
				++$skipped_category;
				continue;
			}

			if ( ! in_array( $url_cat, $importable_categories, true ) ) {
				++$skipped_category;
				continue;
			}

			$slug = $this->extract_slug( $url );
			if ( empty( $slug ) ) {
				++$skipped_no_match;
				continue;
			}

			// Tier 1: exact slug match.
			$post_id      = $this->find_post_by_slug( $slug );
			$match_method = 'exact';

			// Tiers 2-4: fuzzy matching fallback.
			if ( ! $post_id && $fuzzy ) {
				$fuzzy_result = $this->find_post_fuzzy( $slug );
				if ( $fuzzy_result ) {
					$post_id      = $fuzzy_result['post_id'];
					$match_method = $fuzzy_result['method'];
				}
			}

			if ( ! $post_id ) {
				++$skipped_no_match;
				continue;
			}

			$permalink = get_permalink( $post_id );
			$from_path = '/' . ltrim( $url, '/' );
			$from_path = untrailingslashit( $from_path );

			// Check if redirect already exists.
			$existing = \ExtraChill\SEO\Core\extrachill_seo_get_redirect_by_url( $from_path );
			if ( $existing ) {
				++$skipped_exists;
				continue;
			}

			// Skip self-redirects.
			$to_path = wp_make_link_relative( $permalink );
			if ( untrailingslashit( $from_path ) === untrailingslashit( $to_path ) ) {
				++$skipped_no_match;
				continue;
			}

			$rows[] = array(
				'from'   => $from_path,
				'to'     => $to_path,
				'method' => $match_method,
				'hits'   => (int) $row->hits,
				'post'   => $post_id,
				'cat'    => $url_cat,
			);

			if ( ! $dry_run ) {
				$note = sprintf( 'Auto-imported from 404 data (%d hits, post #%d, match: %s)', $row->hits, $post_id, $match_method );
				$id   = \ExtraChill\SEO\Core\extrachill_seo_add_redirect( $from_path, $permalink, 301, $note, 'cli-import' );
				if ( $id ) {
					++$created;
				}
			} else {
				++$created;
			}
		}

		// Show what was found.
		if ( ! empty( $rows ) ) {
			$label = $dry_run ? 'Would create' : 'Created';
			WP_CLI::log( sprintf( '%s %d redirect rules:', $label, count( $rows ) ) );
			WP_CLI::log( '' );

			Utils\format_items( 'table', $rows, array( 'from', 'to', 'method', 'hits', 'post', 'cat' ) );
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

	// ─── Fuzzy Matching ───────────────────────────────────────────────────

	/**
	 * Known content-type suffixes that can be stripped from slugs.
	 *
	 * @var string[]
	 */
	private static $known_suffixes = array(
		'-meaning',
		'-biography',
		'-review',
		'-lyrics',
		'-history',
		'-setlist',
		'-explained',
		'-ranked',
		'-analysis',
		'-guide',
		'-tour',
		'-discography',
		'-net-worth',
		'-songs',
	);

	/**
	 * Find a post using fuzzy matching (tiers 2-4).
	 *
	 * @param string $slug The 404 slug to match.
	 * @return array|false Array with 'post_id' and 'method' keys, or false.
	 */
	private function find_post_fuzzy( $slug ) {
		// Tier 2: slug substring in post_name.
		$result = $this->find_post_by_slug_like( $slug );
		if ( $result ) {
			return array(
				'post_id' => $result,
				'method'  => 'slug-like',
			);
		}

		// Tier 3: title search with suffix filter.
		$result = $this->find_post_by_title_search( $slug );
		if ( $result ) {
			return array(
				'post_id' => $result,
				'method'  => 'title',
			);
		}

		// Tier 4: best-of pattern match.
		$result = $this->find_post_by_best_of_pattern( $slug );
		if ( $result ) {
			return array(
				'post_id' => $result,
				'method'  => 'best-of',
			);
		}

		return false;
	}

	/**
	 * Tier 2: Find a post where the slug is a substring of an existing post_name.
	 *
	 * Handles cases where the published post has a longer/different slug but
	 * contains the core search slug (e.g. "sugaree-meaning" matches
	 * "jerry-garcia-sugaree-meaning").
	 *
	 * @param string $slug The slug to search for.
	 * @return int|false Post ID or false.
	 */
	private function find_post_by_slug_like( $slug ) {
		global $wpdb;

		// Skip very short slugs to avoid false positives.
		if ( strlen( $slug ) < 10 ) {
			return false;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$post_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				WHERE post_name LIKE %s
				AND post_type IN ('post', 'page')
				AND post_status = 'publish'
				ORDER BY post_date DESC
				LIMIT 1",
				'%' . $wpdb->esc_like( $slug ) . '%'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		return $post_id ? (int) $post_id : false;
	}

	/**
	 * Tier 3: Search post titles using the slug words and suffix filtering.
	 *
	 * Strips known suffixes (e.g. "-meaning") from the slug, converts the
	 * remainder to search terms, then searches titles that contain BOTH
	 * the main search terms AND the suffix word.
	 *
	 * Also tries artist+song splitting: splits the slug at different word
	 * boundaries (first 1-3 words as artist, rest as song) and searches
	 * with both as separate LIKE conditions plus the suffix.
	 *
	 * @param string $slug The slug to search for.
	 * @return int|false Post ID or false.
	 */
	private function find_post_by_title_search( $slug ) {
		global $wpdb;

		$suffix_info = $this->strip_known_suffix( $slug );
		if ( ! $suffix_info ) {
			return false;
		}

		$base_slug   = $suffix_info['base'];
		$suffix_word = $suffix_info['suffix'];
		$words       = explode( '-', $base_slug );

		// Need at least 2 words for a meaningful search.
		if ( count( $words ) < 2 ) {
			return false;
		}

		// First try: full base terms + suffix word in title.
		$search_terms = implode( ' ', $words );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$post_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				WHERE post_title LIKE %s
				AND post_title LIKE %s
				AND post_type IN ('post', 'page')
				AND post_status = 'publish'
				ORDER BY post_date DESC
				LIMIT 1",
				'%' . $wpdb->esc_like( $search_terms ) . '%',
				'%' . $wpdb->esc_like( $suffix_word ) . '%'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		if ( $post_id ) {
			return (int) $post_id;
		}

		// Second try: artist+song splitting.
		// Try splitting at word boundaries 1-3 as "artist", rest as "song/topic".
		$max_artist_words = min( 3, count( $words ) - 1 );
		for ( $split = 1; $split <= $max_artist_words; $split++ ) {
			$artist_part = implode( ' ', array_slice( $words, 0, $split ) );
			$song_part   = implode( ' ', array_slice( $words, $split ) );

			if ( strlen( $song_part ) < 3 ) {
				continue;
			}

			// phpcs:disable WordPress.DB.DirectDatabaseQuery
			$post_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts}
					WHERE post_title LIKE %s
					AND post_title LIKE %s
					AND post_title LIKE %s
					AND post_type IN ('post', 'page')
					AND post_status = 'publish'
					ORDER BY post_date DESC
					LIMIT 1",
					'%' . $wpdb->esc_like( $artist_part ) . '%',
					'%' . $wpdb->esc_like( $song_part ) . '%',
					'%' . $wpdb->esc_like( $suffix_word ) . '%'
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery

			if ( $post_id ) {
				return (int) $post_id;
			}
		}

		return false;
	}

	/**
	 * Tier 4: Match "best-X" patterns to "the-N-best-X" posts.
	 *
	 * Handles cases like "best-blink-182-songs" matching
	 * "the-10-best-blink-182-songs" or "the-15-best-blink-182-songs".
	 *
	 * @param string $slug The slug to search for.
	 * @return int|false Post ID or false.
	 */
	private function find_post_by_best_of_pattern( $slug ) {
		// Only handle slugs starting with "best-".
		if ( strpos( $slug, 'best-' ) !== 0 ) {
			return false;
		}

		$remainder = substr( $slug, 5 ); // Strip "best-".

		if ( strlen( $remainder ) < 3 ) {
			return false;
		}

		global $wpdb;

		// Search for "the-N-best-{remainder}" pattern in post_name.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$post_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				WHERE post_name LIKE %s
				AND post_type IN ('post', 'page')
				AND post_status = 'publish'
				ORDER BY post_date DESC
				LIMIT 1",
				$wpdb->esc_like( 'the-' ) . '%-' . $wpdb->esc_like( 'best-' . $remainder )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		return $post_id ? (int) $post_id : false;
	}

	/**
	 * Strip a known suffix from a slug and return the base and suffix word.
	 *
	 * @param string $slug The slug to strip.
	 * @return array|false Array with 'base' and 'suffix' keys, or false if no suffix found.
	 */
	private function strip_known_suffix( $slug ) {
		foreach ( self::$known_suffixes as $suffix ) {
			$suffix_len = strlen( $suffix );
			if ( substr( $slug, -$suffix_len ) === $suffix ) {
				$base = substr( $slug, 0, -$suffix_len );
				if ( strlen( $base ) >= 3 ) {
					return array(
						'base'   => $base,
						'suffix' => ltrim( $suffix, '-' ), // e.g. "meaning".
					);
				}
			}
		}
		return false;
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
		$url  = strtok( $url, '?' );
		$url  = strtok( $url, '#' );
		$url  = preg_replace( '#\.html/?$#', '', $url );
		$url  = preg_replace( '#^/\d{4}/\d{2}/#', '/', $url );
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
