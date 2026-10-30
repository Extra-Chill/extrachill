<?php
/**
 * Artist-profiles metric provider.
 *
 * Counts published `artist_profile` posts on the artist site (blog 4). Read
 * directly from the shared DB via switch_to_blog(), so it works from any origin
 * site even though extrachill-artist-platform is a per-site plugin.
 *
 * Returns null only when the artist site is unavailable.
 *
 * @package ExtraChillNetwork\NetworkStats\Providers
 * @since   1.19.0
 */

namespace ExtraChillNetwork\NetworkStats\Providers;

use ExtraChillNetwork\NetworkStats\AbstractMetricProvider;

defined( 'ABSPATH' ) || exit;

/**
 * Count of published artist profiles.
 */
class ArtistProfilesProvider extends AbstractMetricProvider {

	/**
	 * Construct the artist-profiles provider.
	 */
	public function __construct() {
		parent::__construct( 'artist_profiles', __( 'Artist Profiles', 'extrachill-network' ), HOUR_IN_SECONDS );
	}

	/**
	 * {@inheritDoc}
	 */
	public function value() {
		return $this->count_published_posts( 'artist', 'artist_profile' );
	}
}
