<?php
/**
 * Artist performer kind: what sort of act an artist profile represents.
 *
 * Artist profiles cover more than bands. The performer kind is one profile
 * primitive (registered post meta on `artist_profile`), not a separate
 * system per kind. It drives the schema.org type every surface publishes for
 * the artist entity, through the single resolver
 * ec_artist_performer_kind_schema_type():
 *
 * - `music`  (default; bands and solo musicians) → `MusicGroup`
 * - `comedy` (comedians and comedy acts)         → `PerformingGroup`
 *
 * A profile cannot tell a solo act from a troupe, so comedy maps to
 * `PerformingGroup` rather than `Person`. Profiles without the meta resolve
 * to `music`, so existing output is unchanged. Genre is a music vocabulary
 * and is only published for music acts (ec_artist_performer_kind_has_genre()).
 *
 * The entity `@id` fragment stays `#musicgroup` for every kind so existing
 * references (Link Page ProfilePage → profile entity) keep resolving.
 *
 * @package ExtraChillArtistPlatform
 */

defined( 'ABSPATH' ) || exit;

/**
 * Post meta key holding the artist's performer kind.
 */
const EC_ARTIST_PERFORMER_KIND_META = '_artist_performer_kind';

/**
 * Performer kind every profile has unless set otherwise.
 */
const EC_ARTIST_PERFORMER_KIND_DEFAULT = 'music';

/**
 * Supported performer kinds mapped to their schema.org entity type.
 *
 * @return array<string, string> Kind slug => schema.org type.
 */
function ec_artist_performer_kind_schema_types() {
	return array(
		'music'  => 'MusicGroup',
		'comedy' => 'PerformingGroup',
	);
}

/**
 * Supported performer kinds with their display labels.
 *
 * @return array<string, string> Kind slug => label.
 */
function ec_artist_performer_kinds() {
	return array(
		'music'  => __( 'Music', 'extrachill-artist-platform' ),
		'comedy' => __( 'Comedy', 'extrachill-artist-platform' ),
	);
}

/**
 * Normalize any value to a supported performer kind, falling back to the default.
 *
 * @param mixed $kind Raw kind value.
 * @return string Supported kind slug.
 */
function ec_artist_normalize_performer_kind( $kind ) {
	$kind = is_scalar( $kind ) ? strtolower( trim( (string) $kind ) ) : '';
	return array_key_exists( $kind, ec_artist_performer_kind_schema_types() ) ? $kind : EC_ARTIST_PERFORMER_KIND_DEFAULT;
}

/**
 * Whether a value is a supported performer kind (strict, no fallback).
 *
 * @param mixed $kind Raw kind value.
 */
function ec_artist_is_performer_kind( $kind ) {
	return is_string( $kind ) && array_key_exists( $kind, ec_artist_performer_kind_schema_types() );
}

/**
 * Canonical resolver: performer kind → schema.org entity type.
 *
 * @param mixed $kind Performer kind.
 * @return string schema.org type.
 */
function ec_artist_performer_kind_schema_type( $kind ) {
	$types = ec_artist_performer_kind_schema_types();
	return $types[ ec_artist_normalize_performer_kind( $kind ) ];
}

/**
 * Whether the performer kind publishes genre (music only).
 *
 * @param mixed $kind Performer kind.
 */
function ec_artist_performer_kind_has_genre( $kind ) {
	return 'music' === ec_artist_normalize_performer_kind( $kind );
}

/**
 * Read an artist profile's performer kind. Runs on the artist site.
 *
 * @param int $artist_id Artist profile ID.
 * @return string Supported kind slug.
 */
function ec_artist_get_performer_kind( $artist_id ) {
	return ec_artist_normalize_performer_kind( get_post_meta( (int) $artist_id, EC_ARTIST_PERFORMER_KIND_META, true ) );
}

/**
 * Write an artist profile's performer kind. Runs on the artist site.
 *
 * The default kind is stored as absent meta so unset and `music` profiles
 * are indistinguishable.
 *
 * @param int   $artist_id Artist profile ID.
 * @param mixed $kind      Performer kind.
 * @return true|WP_Error
 */
function ec_artist_set_performer_kind( $artist_id, $kind ) {
	if ( ! ec_artist_is_performer_kind( $kind ) ) {
		return new WP_Error(
			'invalid_performer_kind',
			sprintf(
				/* translators: %s: comma-separated list of supported kinds. */
				__( 'Performer kind must be one of: %s.', 'extrachill-artist-platform' ),
				implode( ', ', array_keys( ec_artist_performer_kind_schema_types() ) )
			),
			array( 'status' => 400 )
		);
	}
	if ( EC_ARTIST_PERFORMER_KIND_DEFAULT === $kind ) {
		delete_post_meta( (int) $artist_id, EC_ARTIST_PERFORMER_KIND_META );
	} else {
		update_post_meta( (int) $artist_id, EC_ARTIST_PERFORMER_KIND_META, $kind );
	}
	return true;
}

/**
 * Register the performer kind as post meta on `artist_profile`.
 *
 * Runs after artist_profile registers (init:5).
 *
 * @return void
 */
function ec_artist_register_performer_kind_meta() {
	register_post_meta(
		'artist_profile',
		EC_ARTIST_PERFORMER_KIND_META,
		array(
			'type'              => 'string',
			'single'            => true,
			'default'           => EC_ARTIST_PERFORMER_KIND_DEFAULT,
			'description'       => __( 'Performer kind (music or comedy). Drives the schema.org entity type.', 'extrachill-artist-platform' ),
			'sanitize_callback' => 'ec_artist_normalize_performer_kind',
			'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
				unset( $allowed, $meta_key );
				return function_exists( 'ec_can_manage_artist' ) && ec_can_manage_artist( get_current_user_id(), (int) $post_id );
			},
			'show_in_rest'      => false,
		)
	);
}
add_action( 'init', 'ec_artist_register_performer_kind_meta', 10 );
