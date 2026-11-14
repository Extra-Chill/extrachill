<?php
/**
 * Creation Filter Functions for ExtraChill Artist Platform
 *
 * Centralized creation logic using WordPress filters for extensibility.
 * Handles creation vs editing mode distinction and prevents duplicate creation.
 */

defined( 'ABSPATH' ) || exit;

/** Capture one Artist-owned metadata value for compensation. */
function ec_artist_snapshot_owned_meta( $post_id, $meta_key ) {
	return array(
		'exists' => metadata_exists( 'post', $post_id, $meta_key ),
		'value'  => get_post_meta( $post_id, $meta_key, true ),
	);
}

/** Restore and verify Artist-owned metadata snapshots. */
function ec_artist_restore_owned_meta_snapshots( $post_id, $snapshots ) {
	$restored = true;
	foreach ( $snapshots as $meta_key => $snapshot ) {
		if ( $snapshot['exists'] ) {
			update_post_meta( $post_id, $meta_key, $snapshot['value'] );
			$success = metadata_exists( 'post', $post_id, $meta_key ) && get_post_meta( $post_id, $meta_key, true ) === $snapshot['value'];
		} else {
			delete_post_meta( $post_id, $meta_key );
			$success = ! metadata_exists( 'post', $post_id, $meta_key );
		}
		$restored = $success && $restored;
	}
	return $restored;
}

/**
 * Create a link page for an artist profile (centralized creation logic)
 *
 * The runtime owner reference is the only artist/page binding: the runtime
 * provisions the page, assigns the reference, and replaces a previous page
 * when `$force` is set.
 *
 * @param int  $artist_id The artist profile ID to create a link page for
 * @param bool $force     Force creation even if link page already exists
 * @return int|WP_Error   Link page ID on success, WP_Error on failure
 */
function ec_create_link_page( $artist_id, $force = false ) {
	if ( ! $artist_id || 'artist_profile' !== get_post_type( $artist_id ) ) {
		return new WP_Error( 'invalid_artist_profile', 'Invalid artist profile ID for link page creation' );
	}

	$owner_reference = ec_normalize_link_page_owner_reference(
		array(
			'kind'      => 'post',
			'blog_id'   => get_current_blog_id(),
			'subtype'   => 'artist_profile',
			'object_id' => $artist_id,
		)
	);
	if ( is_wp_error( $owner_reference ) ) {
		return $owner_reference;
	}

	$existing_link_page_id = ec_get_link_page_id_for_owner( $owner_reference );
	if ( is_wp_error( $existing_link_page_id ) ) {
		return $existing_link_page_id;
	}
	if ( $existing_link_page_id && ! $force ) {
		return (int) $existing_link_page_id;
	}

	$artist_post = get_post( $artist_id );
	if ( ! $artist_post ) {
		return new WP_Error( 'artist_not_found', 'Artist profile post not found' );
	}

	$link_page_title     = $artist_post->post_title;
	$artist_profile_slug = $artist_post->post_name;
	if ( empty( $link_page_title ) || empty( $artist_profile_slug ) ) {
		return new WP_Error( 'incomplete_data', 'Artist profile must have title and slug for link page creation' );
	}

	$result = ec_provision_owned_link_page_composed(
		$owner_reference,
		$link_page_title,
		$artist_profile_slug,
		static function ( $new_link_page_id ) use ( $artist_id, $force ) {
			do_action( 'ec_link_page_created', $new_link_page_id, $artist_id, (bool) $force );
			return true;
		},
		$force
	);
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	$new_link_page_id = (int) $result['link_page_id'];
	if ( function_exists( 'ec_purge_link_page_after_mutation' ) ) {
		ec_purge_link_page_after_mutation( $new_link_page_id );
	}
	return $new_link_page_id;
}

/**
 * Snapshot optional default styles for a newly created link page.
 *
 * This write is an optimization, not a provisioning invariant. The canonical
 * read path merges ec_get_link_page_defaults_for( 'styles' ) whenever stored
 * custom styles are absent, so a published page remains complete and valid if
 * this best-effort snapshot cannot be persisted.
 *
 * @param int $link_page_id The link page ID
 * @param int $artist_id    The associated artist profile ID
 */
// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- shared default-data hook signature; $artist_id reserved for caller parity.
function ec_setup_default_link_page_data( $link_page_id, $artist_id ) {
	// Apply default styles using centralized filter system
	$default_styles = ec_get_link_page_defaults_for( 'styles' );
	if ( ! empty( $default_styles ) ) {
		update_post_meta( $link_page_id, '_link_page_custom_css_vars', $default_styles );
	}
}

/**
 * Check if a link page should be created for an artist profile
 *
 * This function provides a clean way to determine creation eligibility
 * without actually performing the creation.
 *
 * @param int $artist_id The artist profile ID
 * @return bool|WP_Error True if creation should proceed, WP_Error if not eligible
 */
function ec_should_create_link_page( $artist_id ) {
	// Validate artist profile
	if ( ! $artist_id || get_post_type( $artist_id ) !== 'artist_profile' ) {
		return new WP_Error( 'invalid_artist_profile', 'Invalid artist profile ID' );
	}

	// Check if link page already exists
	$existing_link_page_id = apply_filters( 'ec_get_link_page_id', 0, $artist_id );
	if ( $existing_link_page_id && get_post_type( $existing_link_page_id ) === extrachill_artist_platform_link_page_post_type() ) {
		return new WP_Error( 'already_exists', 'Link page already exists for this artist profile' );
	}

	// Check artist profile has required data
	$artist_post = get_post( $artist_id );
	if ( ! $artist_post ) {
		return new WP_Error( 'artist_not_found', 'Artist profile post not found' );
	}

	if ( empty( $artist_post->post_title ) || empty( $artist_post->post_name ) ) {
		return new WP_Error( 'incomplete_data', 'Artist profile must have title and slug' );
	}

	/**
	 * Filters whether a link page should be created for an artist profile.
	 *
	 * This filter allows plugins to add additional conditions for link page creation.
	 * Return WP_Error to prevent creation with a specific reason.
	 *
	 * @since 1.0.0
	 *
	 * @param bool|WP_Error $should_create True to allow creation, WP_Error to prevent.
	 * @param int           $artist_id     The artist profile ID being evaluated.
	 * @param WP_Post       $artist_post   The artist profile post object.
	 */
	return apply_filters( 'ec_should_create_link_page', true, $artist_id, $artist_post );
}
