<?php
/**
 * Seed the community-member-blocks journey.
 *
 * One single-scenario fixture member (not a persona) who joined in 2024, so
 * the profile heatmap has past-year tabs, plus four ranked neighbours so the
 * leaderboard paginates. The leaderboard page is set to two rows per page:
 * page 1 is server-rendered, page 2 must come from the public ability run
 * endpoint. Everything is fictional; nothing is copied from production.
 *
 * @package ExtraChillNetwork
 */

const COMMUNITY_MEMBER_BLOCKS_USER_ID = 801;

$cmb_sites = get_sites(
	array(
		'domain' => 'community.extrachill.com',
		'number' => 1,
	)
);
if ( empty( $cmb_sites ) ) {
	throw new RuntimeException( 'Journey seed could not resolve site by domain: community.extrachill.com' );
}
$cmb_blog_id = (int) $cmb_sites[0]->blog_id;

// Member first, then neighbours ranked below them. Forced IDs because the
// journey's auth-user-id must be known before boot.
$cmb_users = array(
	array( 801, 'scenemember', 'Scene Member (Fixture)', 500, '2024-03-01 12:00:00' ),
	array( 802, 'neighbourone', 'Neighbour One (Fixture)', 400, '2025-01-10 12:00:00' ),
	array( 803, 'neighbourtwo', 'Neighbour Two (Fixture)', 300, '2025-02-10 12:00:00' ),
	array( 804, 'neighbourthree', 'Neighbour Three (Fixture)', 200, '2025-03-10 12:00:00' ),
	array( 805, 'neighbourfour', 'Neighbour Four (Fixture)', 100, '2025-04-10 12:00:00' ),
);

global $wpdb;
foreach ( $cmb_users as $cmb_user ) {
	list( $cmb_id, $cmb_login, $cmb_name, $cmb_points, $cmb_registered ) = $cmb_user;
	if ( ! get_user_by( 'id', $cmb_id ) ) {
		$cmb_inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- forced fixture ID.
			$wpdb->users,
			array(
				'ID'              => $cmb_id,
				'user_login'      => $cmb_login,
				'user_pass'       => wp_hash_password( wp_generate_password( 32, true, true ) ),
				'user_nicename'   => $cmb_login,
				'user_email'      => $cmb_login . '@example.invalid',
				'user_registered' => $cmb_registered,
				'display_name'    => $cmb_name,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( false === $cmb_inserted ) {
			throw new RuntimeException( 'Unable to force-create fixture user ' . $cmb_id . '.' );
		}
		clean_user_cache( $cmb_id );
	}
	add_user_to_blog( $cmb_blog_id, $cmb_id, 'subscriber' );
	update_user_meta( $cmb_id, 'extrachill_total_points', $cmb_points );
}

// Notification emails start enabled so the settings step's save is observable.
if ( function_exists( 'ec_users_set_notification_emails_disabled' ) ) {
	ec_users_set_notification_emails_disabled( COMMUNITY_MEMBER_BLOCKS_USER_ID, false );
}
delete_user_meta( COMMUNITY_MEMBER_BLOCKS_USER_ID, 'ec_custom_title' );

switch_to_blog( $cmb_blog_id );
$cmb_missing = array();
foreach ( array( 'settings', 'leaderboard' ) as $cmb_slug ) {
	if ( ! get_page_by_path( $cmb_slug ) instanceof WP_Post ) {
		$cmb_missing[] = $cmb_slug;
	}
}
if ( $cmb_missing ) {
	restore_current_blog();
	// The community plugin's activation hook creates these; missing means the
	// rig's activation step regressed, not this journey.
	throw new RuntimeException( 'Community activation pages missing: ' . implode( ', ', $cmb_missing ) );
}
$cmb_leaderboard = get_page_by_path( 'leaderboard' );
wp_update_post(
	array(
		'ID'           => $cmb_leaderboard->ID,
		'post_content' => '<!-- wp:extrachill/leaderboard {"perPage":2} /-->',
	)
);
restore_current_blog();

update_site_option( 'ec_rig_community_member_blocks_reached', array() );
update_site_option(
	'ec_rig_journey_fixture_community_member_blocks',
	array(
		'user_id' => COMMUNITY_MEMBER_BLOCKS_USER_ID,
		'blog_id' => $cmb_blog_id,
	)
);

echo 'community-member-blocks seeded: member ' . COMMUNITY_MEMBER_BLOCKS_USER_ID . ' on blog ' . $cmb_blog_id . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
