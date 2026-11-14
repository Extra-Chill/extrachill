<?php
/**
 * Venue owner definition for the standalone Link Pages runtime.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/TeamTermLinkPages.php';

/** Supplies venue membership, canonical profile, and MusicVenue projection. */
final class VenueLinkPages extends TeamTermLinkPages {

	public const KIND              = 'venue';
	public const SNAPSHOT_META_KEY = '_extrachill_events_venue_link_page_snapshot';
	public const ORPHAN_META_KEY   = '_extrachill_events_venue_link_page_orphaned';
	protected const SCHEMA_TYPE    = 'MusicVenue';
	protected const OG_TYPE        = 'place';
	protected const ORPHAN_POLICY  = 'draft_on_owner_deletion';

	/** Location fields carried by the snapshot and schema address. */
	private const LOCATION_FIELDS = array( 'address', 'city', 'state', 'zip', 'country' );

	/**
	 * Require the canonical feature gate plus exact active direct membership.
	 *
	 * @param int $term_id Venue term ID.
	 */
	protected static function authorize_member( int $term_id ) {
		return ( new VenueAuthorization() )->authorize( get_current_user_id(), $term_id, VenueAuthorization::ACTION_ACCESS_VENUE );
	}

	/**
	 * Read the canonical venue profile.
	 *
	 * @param \WP_Term $term Venue term.
	 */
	protected static function read_source( $term ) {
		$term_id = (int) $term->term_id;
		$profile = function_exists( 'data_machine_events_get_venue_profile' ) ? data_machine_events_get_venue_profile( $term_id ) : array();
		if ( is_wp_error( $profile ) ) {
			return $profile;
		}
		$logo                   = is_array( $profile['logo'] ?? null ) ? $profile['logo'] : array();
		$profile['term_id']     = $term_id;
		$profile['name']        = (string) ( $profile['name'] ?? $term->name );
		$profile['slug']        = (string) $term->slug;
		$profile['description'] = (string) ( $profile['description'] ?? $term->description );
		$profile['image_url']   = (string) ( $logo['url'] ?? '' );
		$profile['image_alt']   = (string) ( $logo['alt'] ?? '' );
		$profile['source_url']  = get_term_link( $term );
		foreach ( array_merge( self::LOCATION_FIELDS, array( 'website' ) ) as $field ) {
			$profile[ $field ] = (string) ( $profile[ $field ] ?? get_term_meta( $term_id, '_venue_' . $field, true ) );
		}
		$profile['social_links'] = array();
		foreach ( array( 'instagram', 'facebook', 'youtube', 'tiktok', 'twitter' ) as $network ) {
			$url = esc_url_raw( (string) get_term_meta( $term_id, '_venue_' . $network, true ), array( 'http', 'https' ) );
			if ( $url ) {
				$profile['social_links'][] = array(
					'type' => $network,
					'url'  => $url,
				);
			}
		}
		return $profile;
	}

	/**
	 * Carry the sanitized venue location.
	 *
	 * @param array $source Canonical source.
	 */
	protected static function snapshot_extras( array $source ): array {
		$location = array();
		foreach ( self::LOCATION_FIELDS as $field ) {
			$location[ $field ] = sanitize_text_field( (string) ( $source[ $field ] ?? '' ) );
		}
		return array( 'location' => $location );
	}

	/**
	 * Require the stored location record.
	 *
	 * @param array $snapshot Stored snapshot.
	 */
	protected static function snapshot_extras_valid( array $snapshot ): bool {
		return isset( $snapshot['location'] ) && is_array( $snapshot['location'] );
	}

	/**
	 * Add the postal address when the venue has one.
	 *
	 * @param array $snapshot Validated snapshot.
	 */
	protected static function schema_entity_extras( array $snapshot ): array {
		$location = $snapshot['location'];
		if ( ! $location['address'] && ! $location['city'] ) {
			return array();
		}
		return array(
			'address' => array_filter(
				array(
					'@type'           => 'PostalAddress',
					'streetAddress'   => $location['address'],
					'addressLocality' => $location['city'],
					'addressRegion'   => $location['state'],
					'postalCode'      => $location['zip'],
					'addressCountry'  => $location['country'],
				)
			),
		);
	}

	/**
	 * Qualify colliding slugs by city and state.
	 *
	 * @param array $source Canonical source.
	 */
	protected static function slug_qualifier( array $source ): string {
		return implode( '-', array_filter( array( $source['city'] ?? '', $source['state'] ?? '' ) ) );
	}

	/**
	 * Build the venue workspace Link Page tab URL.
	 *
	 * @param int $term_id Venue term ID.
	 */
	public static function management_url( int $term_id ): string {
		if ( function_exists( 'ec_events_get_venue_settings_url' ) ) {
			return str_replace( '#tab-calendar', '#tab-link-page', ec_events_get_venue_settings_url( $term_id, 'calendar' ) );
		}
		return add_query_arg( 'venue_id', $term_id, get_home_url( self::events_blog_id(), '/venue-settings/' ) ) . '#tab-link-page';
	}
}
