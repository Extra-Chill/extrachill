<?php
/**
 * Creation Filter Functions for ExtraChill Artist Platform
 * 
 * Centralized creation logic using WordPress filters for extensibility.
 * Handles creation vs editing mode distinction and prevents duplicate creation.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resolve and optionally repair the reciprocal artist/link-page association.
 *
 * @param int  $artist_id Artist profile ID.
 * @param bool $repair    Whether a valid inverse-only association may be repaired.
 * @return int|WP_Error Reciprocal link page ID, 0, or a repair failure.
 */
function ec_get_reciprocal_link_page_id( $artist_id, $repair = true ) {
	$artist_id = absint( $artist_id );
	if ( ! $artist_id || 'artist_profile' !== get_post_type( $artist_id ) ) {
		return 0;
	}

	$profile_link_id = (int) get_post_meta( $artist_id, '_extrch_link_page_id', true );
	if ( $profile_link_id ) {
		$associated_artist_id = (int) get_post_meta( $profile_link_id, '_associated_artist_profile_id', true );
		if ( 'artist_link_page' === get_post_type( $profile_link_id ) && $artist_id === $associated_artist_id ) {
			return $profile_link_id;
		}
		delete_post_meta( $artist_id, '_extrch_link_page_id', $profile_link_id );
	}

	$link_page_id = function_exists( 'ec_get_link_page_id' ) ? (int) ec_get_link_page_id( $artist_id ) : 0;
	if ( ! $link_page_id || 'artist_link_page' !== get_post_type( $link_page_id ) || $artist_id !== (int) get_post_meta( $link_page_id, '_associated_artist_profile_id', true ) ) {
		return 0;
	}
	if ( ! $repair ) {
		return 0;
	}

	update_post_meta( $artist_id, '_extrch_link_page_id', $link_page_id );
	if ( (int) get_post_meta( $artist_id, '_extrch_link_page_id', true ) !== $link_page_id ) {
		return new WP_Error(
			'link_page_association_repair_failed',
			'An existing link page could not be associated with its artist profile.',
			array( 'link_page_id' => $link_page_id, 'retryable' => true )
		);
	}
	return $link_page_id;
}

/**
 * Roll back a link page created by the current operation.
 *
 * The page is deleted only after both metadata directions match the captured
 * pre-operation state. Unsafe partial state is left intact for reconciliation.
 *
 * @param int $artist_id            Artist profile ID.
 * @param int $new_link_page_id     Newly created link page ID.
 * @param int $previous_link_page_id Previously reciprocal link page ID, or 0.
 * @return true|WP_Error True when rollback completed, otherwise a manual repair error.
 */
function ec_rollback_created_link_page( $artist_id, $new_link_page_id, $previous_link_page_id = 0 ) {
	delete_post_meta( $artist_id, '_extrch_link_page_id', $new_link_page_id );
	if ( $previous_link_page_id ) {
		update_post_meta( $artist_id, '_extrch_link_page_id', $previous_link_page_id );
	}
	delete_post_meta( $new_link_page_id, '_associated_artist_profile_id', $artist_id );

	$profile_link_id      = (int) get_post_meta( $artist_id, '_extrch_link_page_id', true );
	$new_associated_id    = (int) get_post_meta( $new_link_page_id, '_associated_artist_profile_id', true );
	$previous_associated_id = $previous_link_page_id ? (int) get_post_meta( $previous_link_page_id, '_associated_artist_profile_id', true ) : 0;
	$metadata_restored    = $profile_link_id === (int) $previous_link_page_id
		&& $new_associated_id !== (int) $artist_id
		&& ( ! $previous_link_page_id || $previous_associated_id === (int) $artist_id );
	if ( ! $metadata_restored ) {
		return new WP_Error(
			'link_page_association_compensation_failed',
			'Link page association compensation failed. Manual reconciliation is required.',
			array(
				'artist_id'            => (int) $artist_id,
				'link_page_id'         => (int) $new_link_page_id,
				'previous_link_page_id' => (int) $previous_link_page_id,
				'retryable'            => false,
			)
		);
	}
	if ( ! wp_delete_post( $new_link_page_id, true ) ) {
		return new WP_Error(
			'link_page_association_compensation_failed',
			'Link page metadata was restored, but the new page could not be removed. Manual reconciliation is required.',
			array( 'link_page_id' => (int) $new_link_page_id, 'retryable' => false )
		);
	}

	return true;
}

/**
 * Create a link page for an artist profile (centralized creation logic)
 * 
 * @param int  $artist_id The artist profile ID to create a link page for
 * @param bool $force     Force creation even if link page already exists
 * @return int|WP_Error   Link page ID on success, WP_Error on failure
 */
function ec_create_link_page( $artist_id, $force = false ) {
	if ( ! $artist_id || 'artist_profile' !== get_post_type( $artist_id ) ) {
		return new WP_Error( 'invalid_artist_profile', 'Invalid artist profile ID for link page creation' );
	}

	$existing_link_page_id = ec_get_reciprocal_link_page_id( $artist_id );
	if ( is_wp_error( $existing_link_page_id ) ) {
		return $existing_link_page_id;
	}
	$previous_link_page_id = (int) $existing_link_page_id;
	if ( $existing_link_page_id && ! $force ) {
		$associated_artist_id = (int) get_post_meta( $existing_link_page_id, '_associated_artist_profile_id', true );
		if ( 'artist_link_page' === get_post_type( $existing_link_page_id ) && $artist_id === $associated_artist_id ) {
			return $existing_link_page_id;
		}
		delete_post_meta( $artist_id, '_extrch_link_page_id', $existing_link_page_id );
	}

	$artist_post = get_post( $artist_id );
	if ( ! $artist_post ) {
		return new WP_Error( 'artist_not_found', 'Artist profile post not found' );
	}

	$link_page_title    = $artist_post->post_title;
	$artist_profile_slug = $artist_post->post_name;
	if ( empty( $link_page_title ) || empty( $artist_profile_slug ) ) {
		return new WP_Error( 'incomplete_data', 'Artist profile must have title and slug for link page creation' );
	}

	$new_link_page_id = wp_insert_post(
		array(
			'post_type'   => 'artist_link_page',
			'post_title'  => $link_page_title,
			'post_name'   => $artist_profile_slug,
			'post_status' => 'publish',
			'meta_input'  => array(
				'_associated_artist_profile_id' => $artist_id,
			),
		),
		true
	);

	if ( is_wp_error( $new_link_page_id ) ) {
		return $new_link_page_id;
	}
	if ( ! $new_link_page_id ) {
		return new WP_Error( 'creation_failed', 'Failed to create link page' );
	}

	update_post_meta( $artist_id, '_extrch_link_page_id', $new_link_page_id );
	$profile_link_id      = (int) get_post_meta( $artist_id, '_extrch_link_page_id', true );
	$associated_artist_id = (int) get_post_meta( $new_link_page_id, '_associated_artist_profile_id', true );
	if ( (int) $new_link_page_id !== $profile_link_id || $artist_id !== $associated_artist_id ) {
		$rollback = ec_rollback_created_link_page( $artist_id, $new_link_page_id, $force ? $previous_link_page_id : 0 );
		if ( is_wp_error( $rollback ) ) {
			return $rollback;
		}
		return new WP_Error(
			'link_page_association_failed',
			'Link page association could not be persisted. No link page was created.',
			array( 'retryable' => true )
		);
	}
	if ( $force && $previous_link_page_id && $previous_link_page_id !== (int) $new_link_page_id ) {
		delete_post_meta( $previous_link_page_id, '_associated_artist_profile_id', $artist_id );
		if ( $artist_id === (int) get_post_meta( $previous_link_page_id, '_associated_artist_profile_id', true ) ) {
			$rollback = ec_rollback_created_link_page( $artist_id, $new_link_page_id, $previous_link_page_id );
			if ( is_wp_error( $rollback ) ) {
				return $rollback;
			}
			return new WP_Error(
				'link_page_previous_detach_failed',
				'The previous link page could not be detached. The original association was restored.',
				array( 'retryable' => true )
			);
		}
	}

	ec_setup_default_link_page_data( $new_link_page_id, $artist_id );

    /**
     * Fires after a link page has been created successfully.
     *
     * This action hook allows other plugins and theme functions to perform
     * additional setup operations after link page creation. The link page
     * and associated artist profile are both available and properly linked.
     *
     * @since 1.0.0
     *
     * @param int $new_link_page_id The ID of the newly created link page.
     * @param int $artist_id        The ID of the associated artist profile.
     * @param bool $force           Whether creation was forced.
     */
    do_action( 'ec_link_page_created', $new_link_page_id, $artist_id, $force );

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
    if ( $existing_link_page_id && get_post_type( $existing_link_page_id ) === 'artist_link_page' ) {
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
