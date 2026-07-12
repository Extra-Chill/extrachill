<?php
/**
 * Artist Taxonomy Integration for bbPress
 *
 * Registers the shared 'artist' taxonomy for bbPress topics, enabling
 * explicitly selected artist context on forum content.
 *
 * Community only attaches and queries the shared taxonomy. Artist Platform
 * remains the canonical owner of artist identity and profile URLs.
 *
 * @package ExtraChillCommunity
 * @subpackage Core
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the artist taxonomy for the bbPress topic post type.
 *
 * This runs at priority 20 so the shared taxonomy already exists when we
 * attach it, matching the location and festival integrations.
 *
 * Scope: topics only. Replies inherit their parent topic's artist context, so
 * tagging at the topic level is the high-value target; attaching to 'reply'
 * would duplicate identity per-post without adding signal.
 */
function extrachill_register_artist_for_bbpress() {
	if ( ! taxonomy_exists( 'artist' ) ) {
		return;
	}

	register_taxonomy_for_object_type( 'artist', 'topic' );
}
add_action( 'init', 'extrachill_register_artist_for_bbpress', 20 );

/**
 * Persist existing artist terms selected in the bbPress topic composer.
 *
 * The picker only submits IDs for existing shared artist terms. Its marker
 * lets an intentionally empty selection clear an edited topic while leaving
 * programmatic topic creation untouched.
 *
 * @param int $topic_id The topic ID.
 */
function extrachill_community_save_topic_artist( $topic_id ) {
	if ( ! taxonomy_exists( 'artist' ) ) {
		return;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- bbPress verifies its form nonce before firing bbp_new_topic/bbp_edit_topic.
	if ( ! isset( $_POST['bbp_topic_artist_submitted'] ) ) {
		return;
	}

	$raw       = isset( $_POST['bbp_topic_artist'] ) ? wp_unslash( $_POST['bbp_topic_artist'] ) : array();
	$submitted = is_array( $raw ) ? array_map( 'absint', $raw ) : array( absint( $raw ) );
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	$term_ids = array();
	foreach ( array_unique( array_filter( $submitted ) ) as $term_id ) {
		$term = get_term( $term_id, 'artist' );
		if ( $term && ! is_wp_error( $term ) ) {
			$term_ids[] = (int) $term->term_id;
		}
	}

	// Empty (or all-invalid) selection clears the artist context.
	wp_set_object_terms( $topic_id, $term_ids, 'artist' );
}
add_action( 'bbp_new_topic', 'extrachill_community_save_topic_artist', 20 );
add_action( 'bbp_edit_topic', 'extrachill_community_save_topic_artist', 20 );

/**
 * Make native Community artist archives list artist-tagged discussions.
 *
 * Artist Platform keeps canonical artist identity and profile URLs. This only
 * makes Community's local artist taxonomy view a discussion archive.
 *
 * @param WP_Query $query The query being prepared.
 */
function extrachill_community_artist_archive_include_topics( $query ) {
	if ( is_admin() || ! $query->is_main_query() || ! $query->is_tax( 'artist' ) ) {
		return;
	}

	$query->set( 'post_type', array( 'topic' ) );
	// bbPress topics are publish or closed; include both in the discussion view.
	$query->set( 'post_status', array( 'publish', 'closed' ) );
}
add_action( 'pre_get_posts', 'extrachill_community_artist_archive_include_topics' );
