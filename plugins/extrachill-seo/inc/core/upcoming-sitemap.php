<?php
/**
 * Fast discovery lane for upcoming events and page-specific index timestamps.
 *
 * @package ExtraChill\SEO
 */

namespace ExtraChill\SEO\Core;

use DataMachineEvents\Core\EventDatesTable;
use DataMachineEvents\Blocks\Calendar\Query\UpcomingFilter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reuse core's query defaults and all event sitemap exclusions.
 */
class EC_Sitemaps_Upcoming_Provider extends \WP_Sitemaps_Posts {
	/** Constructor. */
	public function __construct() {
		$this->name        = 'upcoming-events';
		$this->object_type = 'upcoming-events';
	}

	/** @return bool Whether the owning event date API is available. */
	public function is_available() {
		return class_exists( EventDatesTable::class ) && class_exists( UpcomingFilter::class );
	}

	/** @return array Query arguments, also shared with the index aggregate. */
	public function event_query_args( $page, $upcoming = true ) {
		$args          = parent::get_posts_query_args( 'data_machine_events' );
		$args['paged'] = max( 1, (int) $page );
		if ( $upcoming ) {
			$args['ec_seo_upcoming_sitemap'] = true;
			$args['suppress_filters']       = false;
			$args['orderby']                = array( 'modified' => 'DESC', 'ID' => 'DESC' );
		}
		return $args;
	}

	/** @return array[] URLs in newest-modified-first order, with a stable ID tie-break. */
	public function get_url_list( $page_num, $object_subtype = '' ) {
		if ( ! $this->is_available() ) {
			return array();
		}
		$query = new \WP_Query( $this->event_query_args( $page_num ) );
		$urls  = array();
		foreach ( $query->posts as $post ) {
			$urls[] = array(
				'loc'     => get_permalink( $post ),
				'lastmod' => ec_seo_sitemap_gmt_lastmod( $post->post_modified_gmt ),
			);
		}
		return $urls;
	}

	/** @return int Page count using exactly the URL query's membership. */
	public function get_max_num_pages( $object_subtype = '' ) {
		if ( ! $this->is_available() ) {
			return 0;
		}
		$args                  = $this->event_query_args( 1 );
		$args['fields']        = 'ids';
		$args['no_found_rows'] = false;
		$query                 = new \WP_Query( $args );
		return (int) $query->max_num_pages;
	}

	/** @return array No subtypes: this provider contains only upcoming events. */
	public function get_object_subtypes() {
		return array();
	}
}

/** Register while core adds its first provider, before that provider is stored. */
add_filter(
	'wp_sitemaps_add_provider',
	function ( $provider, $name ) {
		if ( 'posts' === $name ) {
			wp_register_sitemap_provider( 'upcoming-events', new EC_Sitemaps_Upcoming_Provider() );
		}
		return $provider;
	},
	10,
	2
);

/** @param string $where SQL. @param \WP_Query $query Query. @return string */
function ec_seo_upcoming_sitemap_where( $where, $query ) {
	if ( ! $query->get( 'ec_seo_upcoming_sitemap' ) || 'data_machine_events' !== $query->get( 'post_type' )
		|| ! class_exists( EventDatesTable::class ) || ! class_exists( UpcomingFilter::class ) ) {
		return $where;
	}
	global $wpdb;
	$table    = EventDatesTable::table_name();
	$upcoming = UpcomingFilter::upcoming_where( current_time( 'mysql' ) );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Owner API table and prepared predicate; core posts identifier.
	return $where . " AND EXISTS (SELECT 1 FROM {$table} ed WHERE ed.post_id = {$wpdb->posts}.ID AND {$upcoming})";
}
add_filter( 'posts_where', __NAMESPACE__ . '\\ec_seo_upcoming_sitemap_where', 10, 2 );

/** @param string $datetime MySQL GMT datetime. @return string W3C UTC timestamp. */
function ec_seo_sitemap_gmt_lastmod( $datetime ) {
	return gmdate( DATE_W3C, strtotime( $datetime . ' UTC' ) );
}

/**
 * Compute MAX over precisely the ordered, limited page, not the entire subtype.
 * WP_Query builds the SQL (including retention and recurring exclusions), but
 * posts_pre_query prevents its normal SELECT and any object/cache hydration.
 * One aggregate SELECT per page; no event IDs are materialized in PHP.
 *
 * @param array  $entry Index entry.
 * @param string $type Provider object type.
 * @param string $subtype Subtype.
 * @param int    $page Page number.
 * @return array
 */
function ec_seo_event_sitemap_index_entry( $entry, $type, $subtype, $page ) {
	$upcoming = 'upcoming-events' === $type;
	if ( ! $upcoming && ( 'posts' !== $type || 'data_machine_events' !== $subtype ) ) {
		return $entry;
	}
	$provider = new EC_Sitemaps_Upcoming_Provider();
	$args     = $provider->event_query_args( $page, $upcoming );
	// Core posts pages use the posts limit, which may differ from this provider.
	if ( ! $upcoming ) {
		$args['posts_per_page'] = wp_sitemaps_get_max_urls( 'post' );
	}
	$args['fields']        = '';
	$args['no_found_rows'] = true;
	$args['cache_results'] = false;
	$query                 = new \WP_Query();
	$lastmod               = null;
	$aggregate             = static function ( $posts, $candidate ) use ( $query, &$lastmod ) {
		if ( $candidate !== $query ) {
			return $posts;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- SQL generated by WP_Query, one aggregate per index page.
		$lastmod = $wpdb->get_var( 'SELECT MAX(ec_sitemap_page.post_modified_gmt) FROM (' . $query->request . ') ec_sitemap_page' );
		return array();
	};
	add_filter( 'posts_pre_query', $aggregate, 10, 2 );
	try {
		$query->query( $args );
	} finally {
		remove_filter( 'posts_pre_query', $aggregate, 10 );
	}
	if ( $lastmod && '0000-00-00 00:00:00' !== $lastmod ) {
		$entry['lastmod'] = ec_seo_sitemap_gmt_lastmod( $lastmod );
	}
	return $entry;
}
add_filter( 'wp_sitemaps_index_entry', __NAMESPACE__ . '\\ec_seo_event_sitemap_index_entry', 10, 4 );
