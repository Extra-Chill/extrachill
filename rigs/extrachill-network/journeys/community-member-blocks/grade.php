<?php
/**
 * Grade the community-member-blocks journey.
 *
 * Each browser step is a case, judged by the marker it records only after
 * all its assertions pass. Persisted checks confirm that the two saves
 * really reached the server. Regression contract as in the other journeys:
 * known findings are pinned to issues; the journeys workflow fails on an
 * unpinned finding or a pinned one that passes.
 *
 * @package ExtraChillNetwork
 */

$cmb_fixture = get_site_option( 'ec_rig_journey_fixture_community_member_blocks', array() );
if ( empty( $cmb_fixture['user_id'] ) ) {
	throw new RuntimeException( 'community-member-blocks fixture missing; seed did not run.' );
}
$cmb_user_id = (int) $cmb_fixture['user_id'];
$cmb_reached = (array) get_site_option( 'ec_rig_community_member_blocks_reached', array() );
$cmb_results = array();

$cmb_steps = array(
	'settings_notifications_save' => 'A member opens Settings, the Notifications tab loads their preferences, and saving shows a confirmation.',
	'edit_profile_save'           => 'A member opens Edit Profile, it loads their profile, and saving a custom title shows a confirmation.',
	'leaderboard_logged_out'      => 'A logged-out visitor sees the leaderboard immediately and can page to page 2.',
	'heatmap_logged_out_year'     => 'A logged-out visitor on a member profile sees the contribution heatmap and can switch to a past year.',
);
foreach ( $cmb_steps as $cmb_step_id => $cmb_task ) {
	$cmb_results[] = array(
		'id'       => str_replace( '_', '-', $cmb_step_id ),
		'task'     => $cmb_task,
		'outcome'  => isset( $cmb_reached[ $cmb_step_id ] ) ? 'pass' : 'finding',
		'evidence' => array( 'reached_at' => $cmb_reached[ $cmb_step_id ] ?? null ),
	);
}

$cmb_emails_enabled = function_exists( 'ec_users_notification_emails_enabled' ) ? ec_users_notification_emails_enabled( $cmb_user_id ) : null;
$cmb_results[]      = array(
	'id'       => 'notification-preference-persisted',
	'task'     => 'Turning off notification emails and saving is stored for the member.',
	'outcome'  => false === $cmb_emails_enabled ? 'pass' : 'finding',
	'evidence' => array( 'emails_enabled' => $cmb_emails_enabled ),
);

$cmb_title     = (string) get_user_meta( $cmb_user_id, 'ec_custom_title', true );
$cmb_results[] = array(
	'id'       => 'custom-title-persisted',
	'task'     => 'The custom title saved from Edit Profile is stored for the member.',
	'outcome'  => 'Scene Regular' === $cmb_title ? 'pass' : 'finding',
	'evidence' => array( 'ec_custom_title' => $cmb_title ),
);

$cmb_known_findings = array();
$cmb_regressions    = array();
$cmb_unpin          = array();
foreach ( $cmb_results as &$cmb_result ) {
	$cmb_result['pinned_issue'] = $cmb_known_findings[ $cmb_result['id'] ] ?? null;
	if ( 'finding' === $cmb_result['outcome'] && null === $cmb_result['pinned_issue'] ) {
		$cmb_regressions[] = $cmb_result['id'];
	}
	if ( 'pass' === $cmb_result['outcome'] && null !== $cmb_result['pinned_issue'] ) {
		$cmb_unpin[] = $cmb_result['id'] . ' (' . $cmb_result['pinned_issue'] . ')';
	}
}
unset( $cmb_result );

$cmb_summary = array(
	'schema'      => 'extrachill-network/journey-result/community-member-blocks/v1',
	'results'     => $cmb_results,
	'regressions' => $cmb_regressions,
	'unpin'       => $cmb_unpin,
	'reached'     => $cmb_reached,
	'passed'      => count( array_filter( $cmb_results, static fn( $r ) => 'pass' === $r['outcome'] ) ),
	'findings'    => count( array_filter( $cmb_results, static fn( $r ) => 'finding' === $r['outcome'] ) ),
);
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Machine-readable journey result.
printf( "EXTRACHILL_JOURNEY_RESULT:%s\n", base64_encode( (string) wp_json_encode( $cmb_summary ) ) );
