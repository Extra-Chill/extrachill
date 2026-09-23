<?php
/**
 * One-time backfill: make every artist Link Page own its content.
 *
 * Copies identity (name, profile image) and socials from artist profiles
 * onto their Link Pages so pages render from their own storage
 * (extrachill-link-pages#36). Dry run by default. Safe to re-run: pages that
 * already own their socials are skipped and identity pushes no-op when the
 * page already matches.
 *
 * Run on the artist site:
 *   wp --url=<artist site> eval 'print_r( ec_artist_backfill_link_page_owned_content( false ) );'
 *
 * @package ExtraChillArtistPlatform
 */

defined( 'ABSPATH' ) || exit;

/**
 * Backfill page-owned identity and socials for every artist.
 *
 * @param bool $apply False for a dry run (default), true to write.
 * @return array Report: counts plus per-artist detail for anything notable.
 */
function ec_artist_backfill_link_page_owned_content( $apply = false ) {
	$report = array(
		'mode'            => $apply ? 'apply' : 'dry-run',
		'artists'         => 0,
		'no_link_page'    => array(),
		'identity_pushed' => 0,
		'identity_errors' => array(),
		'socials_copied'  => 0,
		'socials_already' => 0,
		'socials_none'    => 0,
		'socials_dropped' => array(),
		'socials_errors'  => array(),
	);
	if ( ! function_exists( 'ec_sanitize_social_links' ) || ! function_exists( 'ec_read_link_page_persistence' ) ) {
		$report['fatal'] = 'link_pages_runtime_unavailable';
		return $report;
	}
	$manager    = extrachill_artist_platform_social_links();
	$artist_ids = get_posts(
		array(
			'post_type'      => 'artist_profile',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
		)
	);
	foreach ( $artist_ids as $artist_id ) {
		++$report['artists'];
		$link_page_id = (int) ec_get_link_page_for_artist( $artist_id );
		if ( ! $link_page_id ) {
			$report['no_link_page'][] = (int) $artist_id;
			continue;
		}

		// Identity.
		$current = ec_read_link_page_persistence( $link_page_id );
		if ( is_wp_error( $current ) ) {
			$report['identity_errors'][ $artist_id ] = $current->get_error_code();
			continue;
		}
		$artist    = get_post( $artist_id );
		$title     = $artist ? $artist->post_title : '';
		$image_id  = (int) get_post_thumbnail_id( $artist_id );
		$needs_run = ! $current['display_title_is_owned'] || $current['display_title'] !== $title || (int) $current['profile_image_id'] !== $image_id;
		if ( $needs_run ) {
			$pushed = $apply ? ec_artist_push_link_page_identity( $artist_id ) : true;
			if ( is_wp_error( $pushed ) ) {
				$report['identity_errors'][ $artist_id ] = $pushed->get_error_code();
			} else {
				++$report['identity_pushed'];
			}
		}

		// Socials.
		// @phpstan-ignore phpstan.function.notFound (provided by the extrachill-link-pages runtime; checked above via ec_read_link_page_persistence.)
		$owns = ec_with_link_page_storage_blog(
			static function () use ( $link_page_id ) {
				return metadata_exists( 'post', $link_page_id, ExtraChillArtistPlatform_SocialLinks::PAGE_META_KEY );
			}
		);
		if ( true === $owns ) {
			++$report['socials_already'];
			continue;
		}
		$legacy = get_post_meta( $artist_id, ExtraChillArtistPlatform_SocialLinks::META_KEY, true );
		$legacy = is_array( $legacy ) ? $legacy : maybe_unserialize( $legacy );
		$legacy = is_array( $legacy ) ? array_values( $legacy ) : array();
		if ( ! $legacy ) {
			++$report['socials_none'];
			continue;
		}
		$keep = array();
		foreach ( $legacy as $index => $entry ) {
			// @phpstan-ignore phpstan.function.notFound (provided by the extrachill-link-pages runtime; checked at the top of this function.)
			$one = ec_sanitize_social_links( array( $entry ) );
			if ( is_wp_error( $one ) ) {
				$report['socials_dropped'][ $artist_id ][ $index ] = array(
					'reason' => $one->get_error_code(),
					'entry'  => $entry,
				);
				continue;
			}
			$keep[] = $entry;
		}
		// @phpstan-ignore phpstan.function.notFound (provided by the extrachill-link-pages runtime; checked at the top of this function.)
		$clean = ec_sanitize_social_links( $keep );
		if ( is_wp_error( $clean ) ) {
			$report['socials_errors'][ $artist_id ] = $clean->get_error_code();
			continue;
		}
		if ( $apply ) {
			$written = $manager->write_page_socials( $artist_id, $clean );
			if ( is_wp_error( $written ) ) {
				$report['socials_errors'][ $artist_id ] = $written->get_error_code();
				continue;
			}
		}
		++$report['socials_copied'];
	}
	return $report;
}
