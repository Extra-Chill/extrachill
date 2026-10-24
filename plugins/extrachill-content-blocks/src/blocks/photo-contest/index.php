<?php
/**
 * Photo Contest Block render support.
 *
 * Only block render config (the JSON island render.php embeds) and the Open
 * Graph deep-link overrides live here. The photo-contest domain — entries,
 * submission, voting, moderation, photo storage — lives in inc/photo-contest/
 * and is loaded by the plugin bootstrap; the abilities in
 * inc/abilities/photo-contest-*.php call it directly.
 *
 * @package ExtraChillContentBlocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Frontend config JSON for the photo-contest block (render.php JSON island).
 *
 * Contest pages sit behind full-page cache for anonymous visitors, so the
 * anonymous config must stay byte-identical and never vary per visitor.
 * Moderators (logged-in requests bypass the cache) additionally receive a
 * `canModerate` flag and a REST nonce so view.tsx can show Hide/Unhide
 * controls and call the hide ability through core's Abilities REST runner.
 * The hide ability's permission check remains the authority.
 *
 * @param array $attributes Block attributes.
 * @param int   $post_id    Contest post ID.
 * @return array Config array for wp_json_encode().
 */
function extrachill_content_blocks_photo_contest_render_config( array $attributes, $post_id ) {
	$config = array(
		'postId'         => (int) $post_id,
		'contestId'      => isset( $attributes['contestId'] ) ? (string) $attributes['contestId'] : '',
		'heading'        => isset( $attributes['heading'] ) ? (string) $attributes['heading'] : 'Enter the Contest',
		'eventTitle'     => isset( $attributes['eventTitle'] ) ? (string) $attributes['eventTitle'] : '',
		'showFinalists'  => ! empty( $attributes['showFinalists'] ),
		'finalistCount'  => isset( $attributes['finalistCount'] ) ? max( 1, (int) $attributes['finalistCount'] ) : 10,
		'isAdmin'        => is_admin(),
	);

	// Resolve every window date once in the site timezone, not against the
	// visitor's device timezone.
	foreach ( array( 'entriesOpenAt', 'votingOpensAt', 'entriesCloseAt', 'votingClosesAt' ) as $key ) {
		$date      = (string) ( $attributes[ $key ] ?? '' );
		$timestamp = extrachill_content_blocks_photo_contest_time( $date );
		$config[ $key ] = null === $timestamp ? '' : gmdate( 'c', $timestamp );
		if ( in_array( $key, array( 'entriesOpenAt', 'votingOpensAt' ), true ) ) {
			$config[ $key . 'Label' ] = extrachill_content_blocks_photo_contest_format_time( $date );
		}
	}

	if ( extrachill_content_blocks_photo_contest_can_moderate() ) {
		$config['canModerate'] = true;
		$config['restNonce']   = (string) wp_create_nonce( 'wp_rest' );
	}

	return $config;
}

/**
 * Compute Open Graph overrides for an entry deep link (?entry=<id>).
 *
 * Pure given an OG array and IDs; the wp_head-time wrappers below feed it the
 * request context. The entry photo becomes og:image so a shared deep link
 * unfurls with the entrant's photo, not the page's featured image.
 *
 * @param array $og_data  OG property => value map from extrachill-seo.
 * @param int   $post_id  Queried contest post ID.
 * @param int   $entry_id Entry attachment ID from ?entry=.
 * @return array Possibly-modified OG data.
 */
function extrachill_content_blocks_photo_contest_og_overrides( array $og_data, $post_id, $entry_id ) {
	$entry = get_post( $entry_id );
	if ( ! $entry || 'attachment' !== $entry->post_type || (int) $entry->post_parent !== (int) $post_id ) {
		return $og_data;
	}

	$contest_id = (string) get_post_meta( $entry->ID, EXTRACHILL_PHOTO_CONTEST_META_CONTEST, true );
	if ( '' === $contest_id ) {
		return $og_data;
	}

	if ( '' !== (string) get_post_meta( $entry->ID, EXTRACHILL_PHOTO_CONTEST_META_HIDDEN, true ) ) {
		return $og_data;
	}

	$image_url = function_exists( 'wp_get_attachment_image_url' )
		? (string) wp_get_attachment_image_url( $entry->ID, 'large' )
		: '';
	if ( '' === $image_url ) {
		$image_url = (string) wp_get_attachment_url( $entry->ID );
	}

	if ( '' === $image_url ) {
		return $og_data;
	}

	$display_name = (string) get_post_meta( $entry->ID, EXTRACHILL_PHOTO_CONTEST_META_NAME, true );

	$og_data['og:image'] = $image_url;
	unset( $og_data['og:image:width'], $og_data['og:image:height'] );

	if ( '' !== $display_name ) {
		$og_data['og:image:alt'] = sprintf( "%s's contest entry", $display_name );
		$og_data['og:title']     = sprintf( 'Vote for %s!', $display_name );
	}

	$og_data['og:url'] = add_query_arg( 'entry', (int) $entry->ID, (string) get_permalink( $post_id ) );

	return $og_data;
}

/**
 * Swap in the entry photo on ?entry= deep links (wp_head-time OG filter).
 *
 * @param array $og_data OG data from extrachill-seo.
 * @return array
 */
function extrachill_content_blocks_photo_contest_filter_og_data( $og_data ) {
	if ( ! is_array( $og_data ) || ! is_singular() || empty( $_GET['entry'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return $og_data;
	}

	$entry_id = absint( wp_unslash( $_GET['entry'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( 0 === $entry_id ) {
		return $og_data;
	}

	return extrachill_content_blocks_photo_contest_og_overrides( $og_data, (int) get_queried_object_id(), $entry_id );
}
add_filter( 'extrachill_seo_open_graph_data', 'extrachill_content_blocks_photo_contest_filter_og_data', 20 );

/**
 * Fallback OG image for contest posts without a featured image.
 *
 * The extrachill-seo plugin fires this only when the post has no featured
 * image; the data filter above still wins when one exists.
 *
 * @param string   $image_url Current (empty) image URL.
 * @param \WP_Post $post      Queried post.
 * @return string
 */
function extrachill_content_blocks_photo_contest_filter_og_image_url( $image_url, $post ) {
	if ( '' !== (string) $image_url || empty( $_GET['entry'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return $image_url;
	}

	$entry_id = absint( wp_unslash( $_GET['entry'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$entry    = get_post( $entry_id );
	if ( ! $entry || 'attachment' !== $entry->post_type || (int) $entry->post_parent !== (int) $post->ID ) {
		return $image_url;
	}

	if ( '' === (string) get_post_meta( $entry->ID, EXTRACHILL_PHOTO_CONTEST_META_CONTEST, true )
		|| '' !== (string) get_post_meta( $entry->ID, EXTRACHILL_PHOTO_CONTEST_META_HIDDEN, true ) ) {
		return $image_url;
	}

	$url = function_exists( 'wp_get_attachment_image_url' )
		? (string) wp_get_attachment_image_url( $entry->ID, 'large' )
		: '';

	return '' !== $url ? $url : (string) wp_get_attachment_url( $entry->ID );
}
add_filter( 'extrachill_seo_singular_og_image_url', 'extrachill_content_blocks_photo_contest_filter_og_image_url', 10, 2 );
