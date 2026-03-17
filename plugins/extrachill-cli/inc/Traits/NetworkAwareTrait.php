<?php
/**
 * Network-Aware CLI Trait
 *
 * Provides multisite filtering for CLI commands that query network-wide tables.
 * Commands using this trait gain a --site flag to filter by blog ID.
 *
 * Usage:
 *   1. Add `use \ExtraChill\CLI\Traits\NetworkAwareTrait;` in your command class.
 *   2. Add the --site option to your command's docblock (copy from SITE_OPTION_DOC).
 *   3. Call `$this->get_site_filter( $assoc_args )` to get the resolved blog ID.
 *   4. Call `$this->get_site_where_clause()` to get SQL fragments for filtering.
 *   5. Call `$this->format_site_label()` for human-readable output headers.
 *
 * Behavior:
 *   --site=<id>    Filter to a specific blog ID.
 *   --site=all     Show all sites (no filter).
 *   (omitted)      Defaults to current blog context via get_current_blog_id().
 *                  On the main site (blog 1), defaults to all sites for convenience.
 *
 * Docblock snippet to include in command OPTIONS:
 *
 *   [--site=<site>]
 *   : Filter by site. Use a blog ID, 'all' for network-wide, or omit for current site.
 *
 * @package ExtraChill\CLI\Traits
 */

namespace ExtraChill\CLI\Traits;

use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait NetworkAwareTrait {

	/**
	 * Resolved blog ID for the current command invocation.
	 *
	 * Set by get_site_filter(). 0 means all sites (no filter).
	 *
	 * @var int
	 */
	private $resolved_blog_id = 0;

	/**
	 * Resolve the --site flag to a blog ID.
	 *
	 * @param array $assoc_args Command associative arguments.
	 * @return int Blog ID (0 = all sites).
	 */
	protected function get_site_filter( $assoc_args ) {
		$site = $assoc_args['site'] ?? null;

		if ( null === $site ) {
			// No --site flag: default to current blog context.
			// On the main site (blog 1), default to all for backward compat.
			$current = get_current_blog_id();
			$this->resolved_blog_id = ( 1 === $current ) ? 0 : $current;
			return $this->resolved_blog_id;
		}

		if ( 'all' === strtolower( $site ) ) {
			$this->resolved_blog_id = 0;
			return 0;
		}

		$blog_id = absint( $site );

		if ( $blog_id > 0 && ! get_blog_details( $blog_id ) ) {
			WP_CLI::error( sprintf( 'Site with blog ID %d does not exist.', $blog_id ) );
		}

		$this->resolved_blog_id = $blog_id;
		return $blog_id;
	}

	/**
	 * Get a SQL WHERE clause fragment for blog_id filtering.
	 *
	 * Returns an array with 'sql' (the clause) and 'values' (for prepare()).
	 * If blog_id is 0 (all sites), returns empty clause.
	 *
	 * @param string $column Column name for blog_id. Default 'blog_id'.
	 * @return array{ sql: string, values: array }
	 */
	protected function get_site_where_clause( $column = 'blog_id' ) {
		if ( 0 === $this->resolved_blog_id ) {
			return array(
				'sql'    => '',
				'values' => array(),
			);
		}

		return array(
			'sql'    => " AND {$column} = %d",
			'values' => array( $this->resolved_blog_id ),
		);
	}

	/**
	 * Get a human-readable label for the current site context.
	 *
	 * @return string Label like "events.extrachill.com" or "all sites".
	 */
	protected function format_site_label() {
		if ( 0 === $this->resolved_blog_id ) {
			return 'all sites';
		}

		$details = get_blog_details( $this->resolved_blog_id );
		if ( $details ) {
			return rtrim( $details->domain . $details->path, '/' );
		}

		return sprintf( 'site %d', $this->resolved_blog_id );
	}

	/**
	 * Get all network sites as an array of [ blog_id => domain ].
	 *
	 * Useful for commands that want to show per-site breakdowns.
	 *
	 * @return array<int, string>
	 */
	protected function get_network_sites() {
		$sites = get_sites(
			array(
				'number' => 100,
				'fields' => 'ids',
			)
		);

		$map = array();
		foreach ( $sites as $blog_id ) {
			$details = get_blog_details( $blog_id );
			if ( $details ) {
				$map[ $blog_id ] = rtrim( $details->domain . $details->path, '/' );
			}
		}

		return $map;
	}
}
