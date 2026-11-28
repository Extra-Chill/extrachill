<?php
/**
 * Grade the forum-activity-fuzz journey.
 *
 * Exploratory: each task is a case judged by its "reached" marker, plus a
 * few persisted-state checks. Every page observation is attached so the
 * run's result JSON is the review surface. There are no pinned findings;
 * this journey is not in the regression set.
 *
 * @package ExtraChillNetwork
 */

$ff_fixture = get_site_option( 'ec_rig_journey_fixture_forum_fuzz', array() );
if ( empty( $ff_fixture['user_id'] ) ) {
	throw new RuntimeException( 'forum-activity-fuzz fixture missing; seed did not run.' );
}
$ff_user_id      = (int) $ff_fixture['user_id'];
$ff_reached      = (array) get_site_option( 'ec_rig_forum_fuzz_reached', array() );
$ff_observations = (array) get_site_option( 'ec_rig_forum_fuzz_observations', array() );

$ff_tasks = array(
	'anon_home_browse'           => 'Visitor lands on the community home and opens a room.',
	'anon_read_busy_topic'       => 'Visitor reads a busy topic and pages to page 2.',
	'anon_stress_topic'          => 'Visitor reads a topic with long words/URLs/emoji and expands a truncated reply.',
	'anon_try_engage'            => 'Visitor tries to upvote a reply.',
	'anon_search'                => 'Visitor searches for "tuesday".',
	'anon_recent_and_leaderboard' => 'Visitor opens Recent and Leaderboard.',
	'member_home'                => 'Member opens home and their notifications.',
	'member_upvote'              => 'Member upvotes a reply.',
	'member_reply'               => 'Member writes and submits a reply and sees it.',
	'member_reply_to_user'       => 'Member clicks Reply on someone\'s post.',
	'member_edit_reply'          => 'Member edits their fresh reply and sees the change.',
	'admin_edit_screens'         => 'Admin opens the same reply and topic edit screens.',
	'member_edit_topic'          => 'Member edits their topic title and sees the change.',
	'member_new_topic'           => 'Member starts a new topic from the home modal and lands on it.',
	'member_profile_edit'        => 'Member saves a custom title and bio.',
	'member_profile_view'        => 'Member views their profile and settings.',
	'mobile_home_and_menu'       => 'Member on a phone opens home and the new-topic modal.',
	'mobile_read_stress'         => 'Member on a phone reads the stress topic.',
	'mobile_reply'               => 'Member on a phone replies to a topic and sees it.',
	'mobile_profile'             => 'Member on a phone views and opens profile edit.',
);

$ff_results = array();
foreach ( $ff_tasks as $ff_id => $ff_task ) {
	$ff_results[] = array(
		'id'      => str_replace( '_', '-', $ff_id ),
		'task'    => $ff_task,
		'outcome' => isset( $ff_reached[ $ff_id ] ) ? 'pass' : 'finding',
	);
}

switch_to_blog( (int) $ff_fixture['blog_id'] );
$ff_fresh       = get_post( (int) ( $ff_fixture['fresh_own_reply'] ?? 0 ) );
$ff_own_topic   = get_post( (int) ( $ff_fixture['own_topic'] ?? 0 ) );
$ff_new_topics  = get_posts(
	array(
		'post_type'   => 'topic',
		'author'      => $ff_user_id,
		'post_status' => 'any',
		'numberposts' => 10,
	)
);
$ff_new_replies = get_posts(
	array(
		'post_type'   => 'reply',
		'author'      => $ff_user_id,
		'post_status' => 'any',
		'numberposts' => 10,
	)
);
$ff_state = array(
	'fresh_reply_content' => $ff_fresh ? $ff_fresh->post_content : null,
	'own_topic_title'     => $ff_own_topic ? $ff_own_topic->post_title : null,
	'member_topics'       => array_map( static fn( $p ) => array( $p->ID, $p->post_status, $p->post_title, $p->post_content ), $ff_new_topics ),
	'member_replies'      => array_map( static fn( $p ) => array( $p->ID, $p->post_status, wp_trim_words( $p->post_content, 30 ) ), $ff_new_replies ),
	'custom_title'        => get_user_meta( $ff_user_id, 'ec_custom_title', true ),
	'bio'                 => get_user_meta( $ff_user_id, 'description', true ),
	'points'              => get_user_meta( $ff_user_id, 'extrachill_total_points', true ),
);
restore_current_blog();

$ff_summary = array(
	'schema'       => 'extrachill-network/journey-result/forum-activity-fuzz/v1',
	'results'      => $ff_results,
	'regressions'  => array(),
	'unpin'        => array(),
	'reached'      => $ff_reached,
	'state'        => $ff_state,
	'fixture'      => $ff_fixture,
	'seed_pending' => (bool) get_site_option( 'ec_rig_forum_fuzz_seed_pending' ),
	'seed_error'   => get_site_option( 'ec_rig_forum_fuzz_seed_error', null ),
	'observations' => $ff_observations,
	'passed'       => count( array_filter( $ff_results, static fn( $r ) => 'pass' === $r['outcome'] ) ),
	'findings'     => count( array_filter( $ff_results, static fn( $r ) => 'finding' === $r['outcome'] ) ),
);
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Machine-readable journey result.
printf( "EXTRACHILL_JOURNEY_RESULT:%s\n", base64_encode( (string) wp_json_encode( $ff_summary ) ) );
