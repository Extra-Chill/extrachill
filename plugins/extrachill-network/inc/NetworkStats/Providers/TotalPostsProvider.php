<?php
/**
 * Total-posts metric provider.
 *
 * Counts published `post` entries on the main editorial site (blog 1).
 *
 * Returns null only when the main site is unavailable.
 *
 * @package ExtraChillNetwork\NetworkStats\Providers
 * @since   1.19.0
 */

namespace ExtraChillNetwork\NetworkStats\Providers;

use ExtraChillNetwork\NetworkStats\AbstractMetricProvider;

defined( 'ABSPATH' ) || exit;

/**
 * Count of published editorial posts on the main site.
 */
class TotalPostsProvider extends AbstractMetricProvider {

	/**
	 * Construct the total-posts provider.
	 */
	public function __construct() {
		parent::__construct( 'total_posts', __( 'Articles', 'extrachill-network' ), HOUR_IN_SECONDS );
	}

	/**
	 * {@inheritDoc}
	 */
	public function value() {
		return $this->count_published_posts( 'main', 'post' );
	}
}
