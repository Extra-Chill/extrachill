<?php
/**
 * Wire-posts metric provider.
 *
 * Counts published `festival_wire` posts on the news-wire site (blog 11). Read
 * directly from the shared DB via switch_to_blog(), so it works from any origin
 * site even though extrachill-news-wire is a per-site plugin.
 *
 * Returns null only when the wire site is unavailable.
 *
 * @package ExtraChillNetwork\NetworkStats\Providers
 * @since   1.19.0
 */

namespace ExtraChillNetwork\NetworkStats\Providers;

use ExtraChillNetwork\NetworkStats\AbstractMetricProvider;

defined( 'ABSPATH' ) || exit;

/**
 * Count of published Festival Wire posts.
 */
class WirePostsProvider extends AbstractMetricProvider {

	/**
	 * Construct the wire-posts provider.
	 */
	public function __construct() {
		parent::__construct( 'wire_posts', __( 'Wire Posts', 'extrachill-network' ), HOUR_IN_SECONDS );
	}

	/**
	 * {@inheritDoc}
	 */
	public function value() {
		return $this->count_published_posts( 'wire', 'festival_wire' );
	}
}
