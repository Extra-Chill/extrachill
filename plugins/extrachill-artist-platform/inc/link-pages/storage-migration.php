<?php
/**
 * Artist-owned validation and attachment semantics for Link Page migration.
 *
 * @package ExtraChillArtistPlatform
 */

defined( 'ABSPATH' ) || exit;

/**
 * Claim only canonical owners belonging to this source Artist site.
 *
 * @param array $context Migration owner context.
 */
function ec_artist_link_page_migration_claim_owner( $context ) {
	$artist_blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'artist' ) : 0;
	$owner          = $context['owner'] ?? array();
	return 'post' === ( $owner['kind'] ?? '' ) && 'artist_profile' === ( $owner['subtype'] ?? '' ) && (int) ( $owner['blog_id'] ?? 0 ) === $artist_blog_id && (int) $context['source_blog_id'] === $artist_blog_id;
}

/**
 * Build a deterministic, read-only Artist migration projection.
 *
 * @param array $context Migration context.
 */
function ec_artist_link_page_migration_plan( $context ) {
	$source_blog_id = (int) $context['source_blog_id'];
	$artist_blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'artist' ) : $source_blog_id;
	if ( $source_blog_id !== $artist_blog_id ) {
		return new WP_Error( 'artist_link_page_migration_source_mismatch', 'Artist-owned Link Pages must be inventoried on the Artist owner blog.' ); }
	$rows              = array();
	$attachments       = array();
	$attachment_owners = array();
	$source_before     = get_current_blog_id();
	if ( get_current_blog_id() !== $source_blog_id ) {
		switch_to_blog( $source_blog_id );
	}
	try {
		foreach ( $context['link_page_ids'] as $link_page_id ) {
			$link_page_id = (int) $link_page_id;
			$artist_id    = (int) get_post_meta( $link_page_id, '_associated_artist_profile_id', true );
			if ( ! $artist_id ) {
				return new WP_Error( 'artist_link_page_migration_missing_owner', 'A Link Page has no legacy associated profile.', array( 'link_page_id' => $link_page_id ) );
			}
			$owner = ec_get_link_page_owner( $link_page_id );
			if ( is_wp_error( $owner ) || 'post' !== $owner['kind'] || $artist_blog_id !== (int) $owner['blog_id'] || 'artist_profile' !== $owner['subtype'] || $artist_id !== (int) $owner['object_id'] ) {
				return new WP_Error( 'artist_link_page_migration_owner_mismatch', 'Legacy and canonical Link Page ownership disagree.', array( 'link_page_id' => $link_page_id ) );
			}
			$legacy_profile_image = (int) get_post_meta( $link_page_id, '_link_page_profile_image_id', true );
			$background_image     = (int) get_post_meta( $link_page_id, '_link_page_background_image_id', true );
			$profile              = get_post( $artist_id );
			$reciprocal_rows      = get_post_meta( $artist_id, '_extrch_link_page_id', false );
			// phpcs:disable WordPress.DB.SlowDBQuery -- Exact reciprocal uniqueness audit.
			$other_profiles = get_posts(
				array(
					'post_type'      => 'artist_profile',
					'post_status'    => 'any',
					'meta_key'       => '_extrch_link_page_id',
					'meta_value'     => (string) $link_page_id,
					'posts_per_page' => -1,
					'fields'         => 'ids',
				)
			);
			// phpcs:enable WordPress.DB.SlowDBQuery
			if ( ! $profile || 'artist_profile' !== $profile->post_type || 1 !== count( $reciprocal_rows ) || (int) $reciprocal_rows[0] !== $link_page_id || array_map( 'intval', $other_profiles ) !== array( $artist_id ) ) {
				return new WP_Error(
					'artist_link_page_migration_reciprocal_mismatch',
					'An owner profile has a stale or ambiguous reciprocal pointer.',
					array(
						'link_page_id' => $link_page_id,
						'profile_id'   => $artist_id,
					)
				);
			}
			$profile_image = (int) get_post_thumbnail_id( $artist_id );
			foreach ( array( $legacy_profile_image, $background_image, $profile_image ) as $attachment_id ) {
				if ( ! $attachment_id ) {
					continue;
				}
				if ( isset( $attachment_owners[ $attachment_id ] ) && $owner['reference'] !== $attachment_owners[ $attachment_id ] ) {
					return new WP_Error( 'artist_link_page_migration_attachment_owner_mismatch', 'An Artist attachment is shared by multiple Link Page owners.', array( 'attachment_id' => $attachment_id ) );
				}
				$attachments[]                       = $attachment_id;
				$attachment_owners[ $attachment_id ] = $owner['reference'];
			}
			$projection = ec_read_link_page( $link_page_id );
			if ( is_wp_error( $projection ) || (int) ( $projection['link_page_id'] ?? 0 ) !== $link_page_id ) {
				return new WP_Error( 'artist_link_page_migration_projection_mismatch', 'The current Link Page operation projection is not exact.', array( 'link_page_id' => $link_page_id ) );
			}
			$rows[] = array(
				'link_page_id'               => $link_page_id,
				'profile_id'                 => $artist_id,
				'owner_reference'            => $owner['reference'],
				'reciprocal_link_page_id'    => $link_page_id,
				'profile_image_id'           => $profile_image,
				'legacy_profile_image_id'    => $legacy_profile_image,
				'background_image_id'        => $background_image,
				'profile_remains_on_blog_id' => $artist_blog_id,
			);
		}
		usort(
			$rows,
			static function ( $a, $b ) {
				return $a['link_page_id'] <=> $b['link_page_id'];
			}
		);
		$attachments = array_values( array_unique( array_map( 'absint', $attachments ) ) );
		sort( $attachments, SORT_NUMERIC );
		$semantics = array();
		foreach ( $attachments as $attachment_id ) {
			$attachment = get_post( $attachment_id );
			if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
				return new WP_Error( 'artist_link_page_migration_attachment_source_mismatch', 'An Artist-contributed attachment does not exist on the source blog.', array( 'attachment_id' => $attachment_id ) ); }
			$parent      = in_array( (int) $attachment->post_parent, $context['link_page_ids'], true ) ? (int) $attachment->post_parent : 0;
			$semantics[] = array(
				'attachment_id'      => $attachment_id,
				'destination_parent' => $parent,
				'owner_reference'    => $attachment_owners[ $attachment_id ],
				'reason'             => $parent ? 'migrated-link-page-parent-preserved' : 'external-owner-parent-remapped',
			);
		}
		$data    = array(
			'profiles'             => $rows,
			'attachment_ids'       => $attachments,
			'attachment_semantics' => $semantics,
			'qr_storage'           => 'generated-on-demand-no-persisted-attachment',
		);
		$encoded = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $encoded ) {
			return new WP_Error( 'artist_link_page_migration_fingerprint_failed', 'The Artist migration projection could not be fingerprinted.' );
		}
		$data['fingerprint'] = hash( 'sha256', $encoded );
		return $data;
	} finally {
		while ( get_current_blog_id() !== $source_before && ! empty( $GLOBALS['_wp_switched_stack'] ) ) {
			restore_current_blog(); }
	}
}

/**
 * Artist owns no destination mutations; exact IDs keep reciprocal pointers valid.
 *
 * @param array $context Migration context.
 */
function ec_artist_link_page_migration_apply( $context ) {
	unset( $context );
	return true;
}

/**
 * Re-run the exact owner projection after core copy.
 *
 * @param array $context Migration context.
 */
function ec_artist_link_page_migration_validate( $context ) {
	$plan = ec_artist_link_page_migration_plan( $context );
	return is_wp_error( $plan ) ? $plan : true;
}

/**
 * Artist never mutates owner profiles during migration.
 *
 * @param array $context Migration context.
 */
function ec_artist_link_page_migration_rollback( $context ) {
	unset( $context );
	return true;
}

/** Register the Artist-owned migration extension. */
function ec_artist_register_link_page_migration_adapter() {
	if ( ! function_exists( 'ec_register_link_page_migration_participant' ) ) {
		return true;
	}
	return ec_register_link_page_migration_participant(
		'artist-platform',
		'1',
		array(
			'claim_owner' => 'ec_artist_link_page_migration_claim_owner',
			'plan'        => 'ec_artist_link_page_migration_plan',
			'apply'       => 'ec_artist_link_page_migration_apply',
			'validate'    => 'ec_artist_link_page_migration_validate',
			'rollback'    => 'ec_artist_link_page_migration_rollback',
		)
	);
}
