<?php
/**
 * Subforum Display Styling
 *
 * Renders location-tagged subforums as taxonomy badges with topic counts.
 * Falls back to button styling for subforums without location terms.
 *
 * @package ExtraChill\Community
 */

/**
 * Replace subforum list with taxonomy badges for location-tagged forums.
 *
 * When a parent forum's subforums have location taxonomy terms, renders them
 * as taxonomy badges (matching the events calendar pattern) with topic counts.
 * Falls back to default bbPress output for non-location subforums.
 *
 * @param string $output  The default bbp_list_forums HTML output.
 * @param array  $r       Parsed arguments.
 * @param array  $args    Original arguments.
 * @return string Modified HTML output.
 */
add_filter( 'bbp_list_forums', 'extrachill_community_subforum_taxonomy_badges', 10, 3 );
function extrachill_community_subforum_taxonomy_badges( $output, $r, $args ) {
	$forum_id   = ! empty( $r['forum_id'] ) ? $r['forum_id'] : 0;
	$sub_forums = ! empty( $forum_id )
		? bbp_forum_get_subforums( $forum_id )
		: array();

	if ( empty( $sub_forums ) ) {
		return $output;
	}

	// Check if any subforum has a location term — if so, use badge rendering.
	$has_location_subforums = false;
	foreach ( $sub_forums as $sub_forum ) {
		$terms = wp_get_object_terms( $sub_forum->ID, 'location', array( 'fields' => 'all' ) );
		if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
			$has_location_subforums = true;
			break;
		}
	}

	if ( ! $has_location_subforums ) {
		return $output;
	}

	// Build taxonomy badge markup matching the events calendar pattern.
	$badges = array();
	foreach ( $sub_forums as $sub_forum ) {
		$permalink   = bbp_get_forum_permalink( $sub_forum->ID );
		$title       = bbp_get_forum_title( $sub_forum->ID );
		$topic_count = (int) get_post_meta( $sub_forum->ID, '_bbp_topic_count', true );
		$terms       = wp_get_object_terms( $sub_forum->ID, 'location', array( 'fields' => 'all' ) );

		// Build CSS classes — taxonomy badge pattern: taxonomy-badge location-badge location-{slug}.
		$classes = array( 'taxonomy-badge', 'location-badge' );
		if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
			$classes[] = 'location-' . $terms[0]->slug;
		}

		$count_display = $topic_count > 0
			? ' (' . number_format_i18n( $topic_count ) . ')'
			: '';

		$badges[] = sprintf(
			'<a href="%s" class="%s">%s%s</a>',
			esc_url( $permalink ),
			esc_attr( implode( ' ', $classes ) ),
			esc_html( $title ),
			esc_html( $count_display )
		);
	}

	return '<div class="taxonomy-badges">' . implode( '', $badges ) . '</div>';
}

/**
 * Add button-3 button-small classes to non-location subforum links.
 *
 * Location-tagged subforums use taxonomy badges (rendered via bbp_list_forums filter).
 * This fallback styles any remaining subforums as buttons.
 *
 * @param array $classes  Existing classes.
 * @param int   $forum_id Forum ID.
 * @return array Modified classes.
 */
add_filter( 'bbp_list_forums_subforum_classes', 'extrachill_subforum_button_classes', 10, 2 );
function extrachill_subforum_button_classes( $classes, $forum_id ) {
	$classes[] = 'button-3';
	$classes[] = 'button-small';
	return $classes;
}

/**
 * Order subforums by most recent activity.
 */
add_filter( 'bbp_after_forum_get_subforums_parse_args', 'extrachill_order_subforums_by_activity' );
function extrachill_order_subforums_by_activity( $r ) {
	$r['orderby']  = 'meta_value';
	$r['meta_key'] = '_bbp_last_active_time';
	$r['order']    = 'DESC';
	return $r;
}

/**
 * Order subforums by most recent activity on single forum pages.
 */
add_filter( 'bbp_after_has_forums_parse_args', 'extrachill_order_has_forums_by_activity' );
function extrachill_order_has_forums_by_activity( $r ) {
	if ( isset( $r['post_parent'] ) && $r['post_parent'] !== 0 ) {
		$r['orderby']  = 'meta_value';
		$r['meta_key'] = '_bbp_last_active_time';
		$r['order']    = 'DESC';
	}
	return $r;
}
