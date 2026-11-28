<?php
/**
 * Sitemap provider for the upcoming events discovery lane.
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
			$args['suppress_filters']        = false;
			$args['orderby']                 = array(
				'modified' => 'DESC',
				'ID'       => 'DESC',
			);
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
			// Default fields return WP_Post objects; skip anything else.
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
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
