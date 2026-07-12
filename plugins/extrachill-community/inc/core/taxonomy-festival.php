<?php
/**
 * Festival Taxonomy Integration for bbPress Topics.
 *
 * @package ExtraChillCommunity
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

/**
 * Attach the theme-owned festival taxonomy to bbPress topics.
 *
 * The theme registers the shared taxonomy with REST support. Community only
 * adds its topic post type so terms retain the same network-wide identity.
 */
function extrachill_community_register_festival_for_topics() {
	if ( taxonomy_exists( 'festival' ) ) {
		register_taxonomy_for_object_type( 'festival', 'topic' );
	}
}
add_action( 'init', 'extrachill_community_register_festival_for_topics', 20 );

/**
 * Persist existing festival terms selected in the bbPress topic composer.
 *
 * @param int $topic_id The topic ID.
 */
function extrachill_community_save_topic_festival( $topic_id ) {
	if ( ! taxonomy_exists( 'festival' ) ) {
		return;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- bbPress verifies its form nonce before firing bbp_new_topic/bbp_edit_topic.
	$has_picker = isset( $_POST['bbp_topic_festival_submitted'] );
	if ( ! $has_picker ) {
		return;
	}

	$raw       = isset( $_POST['bbp_topic_festival'] ) ? wp_unslash( $_POST['bbp_topic_festival'] ) : array();
	$submitted = is_array( $raw ) ? array_map( 'absint', $raw ) : array( absint( $raw ) );
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	$term_ids = array();
	foreach ( array_unique( array_filter( $submitted ) ) as $term_id ) {
		$term = get_term( $term_id, 'festival' );
		if ( $term && ! is_wp_error( $term ) ) {
			$term_ids[] = (int) $term->term_id;
		}
	}

	// Empty (or all-invalid) selection clears the festival.
	wp_set_object_terms( $topic_id, $term_ids, 'festival' );
}
add_action( 'bbp_new_topic', 'extrachill_community_save_topic_festival', 20 );
add_action( 'bbp_edit_topic', 'extrachill_community_save_topic_festival', 20 );

/**
 * Make native festival archives list festival-tagged discussions.
 *
 * The shared taxonomy owns the stable /festival/<slug>/ URL. Community
 * constrains its local archive query to the topic post type so the generic
 * network taxonomy count ability and the destination describe the same terms.
 *
 * @param WP_Query $query The query being prepared.
 */
function extrachill_community_festival_archive_include_topics( $query ) {
	if ( is_admin() || ! $query->is_main_query() || ! $query->is_tax( 'festival' ) ) {
		return;
	}

	$query->set( 'post_type', array( 'topic' ) );
	// bbPress topics are publish or closed; include both in the discussion view.
	$query->set( 'post_status', array( 'publish', 'closed' ) );
}
add_action( 'pre_get_posts', 'extrachill_community_festival_archive_include_topics' );
