<?php
/**
 * Location Taxonomy Registration for bbPress Forums
 *
 * Extends theme-registered location taxonomy to forums.
 * Forums become location hubs with bidirectional cross-site linking.
 *
 * @package ExtraChillCommunity
 * @since 1.2.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register location taxonomy for forums and topics
 *
 * Hooks at priority 20 to run after theme registers the taxonomy.
 *
 * Forums already act as location hubs. Topics gain the network-registered
 * hierarchical `location` taxonomy so they can carry a location term and
 * render the canonical location badge via extrachill_display_taxonomy_badges(),
 * exactly like posts on every other site in the network.
 */
function extrachill_community_register_location_for_forums() {
	if ( taxonomy_exists( 'location' ) ) {
		register_taxonomy_for_object_type( 'location', 'forum' );
		register_taxonomy_for_object_type( 'location', 'topic' );
	}
}
add_action( 'init', 'extrachill_community_register_location_for_forums', 20 );

/**
 * Persist a topic's location term(s) on create/edit.
 *
 * Reads the composer term-picker's `bbp_topic_location[]` array from the
 * submitted topic form and assigns the matching EXISTING hierarchical location
 * terms. Pick-from-existing only — every submitted ID is validated against a
 * real location term, so no freeform creation and the network's curated
 * location tree never drifts. Selecting nothing clears the topic's location.
 *
 * The picker submits a `bbp_topic_location_submitted` marker so we can tell a
 * "no terms chosen, clear it" submit apart from a programmatic (ability/REST)
 * topic creation where the field is absent entirely. A legacy scalar
 * `bbp_topic_location` value is still honored for backward compatibility.
 *
 * bbPress verifies its own form nonce in bbp_new_topic_handler() /
 * bbp_edit_topic_handler() before firing these actions, so the POST payload is
 * already authenticated when we read it here.
 *
 * @param int $topic_id The topic ID.
 */
function extrachill_community_save_topic_location( $topic_id ) {
	if ( ! taxonomy_exists( 'location' ) ) {
		return;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- bbPress verifies its form nonce before firing bbp_new_topic/bbp_edit_topic.

	$has_picker = isset( $_POST['bbp_topic_location_submitted'] );
	$has_legacy = isset( $_POST['bbp_topic_location'] );

	// Field is optional and absent on programmatic (ability/REST) topic creation.
	if ( ! $has_picker && ! $has_legacy ) {
		return;
	}

	$submitted = array();
	if ( isset( $_POST['bbp_topic_location'] ) ) {
		$raw       = wp_unslash( $_POST['bbp_topic_location'] );
		$submitted = is_array( $raw ) ? array_map( 'absint', $raw ) : array( absint( $raw ) );
	}

	// phpcs:enable WordPress.Security.NonceVerification.Missing

	// Pick-from-existing only: keep only IDs that resolve to real location terms.
	$term_ids = array();
	foreach ( array_unique( array_filter( $submitted ) ) as $term_id ) {
		$term = get_term( $term_id, 'location' );
		if ( $term && ! is_wp_error( $term ) ) {
			$term_ids[] = (int) $term->term_id;
		}
	}

	// Empty (or all-invalid) selection clears the location.
	wp_set_object_terms( $topic_id, $term_ids, 'location' );
}
add_action( 'bbp_new_topic', 'extrachill_community_save_topic_location', 20 );
add_action( 'bbp_edit_topic', 'extrachill_community_save_topic_location', 20 );

/**
 * Include topics in location taxonomy archives.
 *
 * After Phase 1B consolidation (#58) the geographic forums are gone; place
 * lives as a `location` term on topics inside "Live Shows & Scenes". The
 * `/location/<term>/` archive becomes the canonical location-filtered VIEW —
 * e.g. /location/charleston/ is the Charleston view of the Scenes room.
 *
 * The location taxonomy is registered to `post` (theme) and `forum`/`topic`
 * (this plugin). On the main location-archive query, add `topic` so the
 * archive renders the location-tagged topics. Forums no longer carry place
 * terms post-migration, so they naturally drop out.
 *
 * @param WP_Query $query The query being prepared.
 */
function extrachill_community_location_archive_include_topics( $query ) {
	if ( is_admin() || ! $query->is_main_query() ) {
		return;
	}
	if ( ! $query->is_tax( 'location' ) ) {
		return;
	}

	$query->set( 'post_type', array( 'topic' ) );
	// bbPress topics are publish or closed; include both so closed threads show.
	$query->set( 'post_status', array( 'publish', 'closed' ) );
}
add_action( 'pre_get_posts', 'extrachill_community_location_archive_include_topics' );

/**
 * Frame the location archive as a filtered view of Live Shows & Scenes.
 *
 * Adds a short "← Live Shows & Scenes" backlink + framing line above the
 * location-archive posts so visitors understand /location/charleston/ is the
 * Charleston view of the Scenes room, not a standalone forum. Reuses the
 * theme's `extrachill_archive_below_description` hook.
 */
function extrachill_community_location_archive_framing() {
	if ( ! is_tax( 'location' ) ) {
		return;
	}

	$term = get_queried_object();
	if ( ! $term || ! is_a( $term, 'WP_Term' ) ) {
		return;
	}

	$scenes = get_page_by_path( 'live-shows-scenes', OBJECT, 'forum' );
	if ( ! $scenes ) {
		return;
	}

	printf(
		'<p class="ec-location-scenes-context"><a href="%1$s">%2$s</a> &middot; %3$s</p>',
		esc_url( get_permalink( $scenes ) ),
		esc_html__( '← Live Shows & Scenes', 'extra-chill-community' ),
		esc_html(
			sprintf(
				/* translators: %s: location name */
				__( 'Showing %s', 'extra-chill-community' ),
				$term->name
			)
		)
	);
}
add_action( 'extrachill_archive_below_description', 'extrachill_community_location_archive_framing' );

/**
 * Append cross-site links to forum description
 *
 * When viewing a forum with a location term, show links to
 * location content on blog, events, and wire sites AFTER the description.
 *
 * @param string $description Forum description HTML.
 * @return string Description with cross-site links appended.
 */
function extrachill_community_append_location_links_to_description( $description ) {
	if ( ! function_exists( 'bbp_is_single_forum' ) || ! bbp_is_single_forum() ) {
		return $description;
	}

	$forum_id       = bbp_get_forum_id();
	$has_subforums  = ! empty( bbp_forum_get_subforums( $forum_id ) );
	$location_terms = get_the_terms( $forum_id, 'location' );
	$button_html    = '';

	if ( ! bbp_is_forum_category() && ! $has_subforums ) {
		$button_html = '<div class="page-content"><p class="ec-single-forum-create-topic"><a class="button-1 button-medium" href="#new-post">' . esc_html__( 'Create Topic', 'extra-chill-community' ) . '</a></p></div>';
	}

	if ( empty( $location_terms ) || is_wp_error( $location_terms ) ) {
		return $description . $button_html;
	}

	$term = $location_terms[0];

	if ( ! function_exists( 'extrachill_get_cross_site_term_links' ) ) {
		return $description . $button_html;
	}

	$links = extrachill_get_cross_site_term_links( $term, 'location' );

	if ( empty( $links ) ) {
		return $description . $button_html;
	}

	ob_start();
	echo '<div class="page-content"><div class="ec-cross-site-links ec-forum-location-links">';
	foreach ( $links as $link ) {
		extrachill_cross_site_link_button( $link );
	}
	echo '</div></div>';
	$links_html = ob_get_clean();

	return $description . $button_html . $links_html;
}
add_filter( 'bbp_get_single_forum_description', 'extrachill_community_append_location_links_to_description' );
