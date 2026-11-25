<?php
/**
 * Global test double for extrachill-artist-platform's ec_get_artist_profile_data().
 *
 * @package ExtraChill\SEO\Tests
 */

declare( strict_types=1 );

/**
 * Return the staged artist profile data.
 *
 * @param int $artist_id Artist profile ID.
 */
function ec_get_artist_profile_data( $artist_id ) {
	return \ExtraChill\SEO\Tests\Support\ArtistProfileDataRegistry::get( (int) $artist_id );
}
