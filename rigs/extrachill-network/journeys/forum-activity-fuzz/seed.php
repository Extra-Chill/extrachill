<?php
/**
 * Seed the forum-activity-fuzz journey.
 *
 * A small, lived-in community: four fictional members, the production room
 * set (created by the community plugin's activation), a busy topic that
 * paginates, a quiet topic, and stress-shaped content (long unbroken words,
 * long URLs, emoji, a long reply that truncates). The acting member (811)
 * owns one fresh reply (inside the edit lock) and one old reply (outside
 * it), plus a topic of their own. Production's bbPress options (slugs, edit
 * lock, page sizes) are mirrored so URLs and pagination behave like the
 * live site. Everything is fictional; nothing is copied from production.
 *
 * @package ExtraChillNetwork
 */

const FORUM_FUZZ_MEMBER_ID = 811;

$ff_sites = get_sites(
	array(
		'domain' => 'community.extrachill.com',
		'number' => 1,
	)
);
if ( empty( $ff_sites ) ) {
	throw new RuntimeException( 'Journey seed could not resolve site by domain: community.extrachill.com' );
}
$ff_blog_id = (int) $ff_sites[0]->blog_id;

$ff_users = array(
	array( 811, 'fuzzfan', 'Fuzz Fan (Fixture)', 120, '2025-06-01 12:00:00' ),
	array( 812, 'scenehost', 'Scene Host (Fixture)', 900, '2024-02-01 12:00:00' ),
	array( 813, 'bandmate', 'Band Mate (Fixture)', 450, '2024-09-01 12:00:00' ),
	array( 814, 'quietlurker', 'Quiet Lurker (Fixture)', 10, '2026-01-01 12:00:00' ),
);

global $wpdb;
foreach ( $ff_users as $ff_user ) {
	list( $ff_id, $ff_login, $ff_name, $ff_points, $ff_registered ) = $ff_user;
	if ( ! get_user_by( 'id', $ff_id ) ) {
		$ff_ok = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- forced fixture ID.
			$wpdb->users,
			array(
				'ID'              => $ff_id,
				'user_login'      => $ff_login,
				'user_pass'       => wp_hash_password( wp_generate_password( 32, true, true ) ),
				'user_nicename'   => $ff_login,
				'user_email'      => $ff_login . '@example.invalid',
				'user_registered' => $ff_registered,
				'display_name'    => $ff_name,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( false === $ff_ok ) {
			throw new RuntimeException( 'Unable to force-create fixture user ' . $ff_id . '.' );
		}
		clean_user_cache( $ff_id );
	}
	add_user_to_blog( $ff_blog_id, $ff_id, 'subscriber' );
	update_user_meta( $ff_id, 'extrachill_total_points', $ff_points );
}

switch_to_blog( $ff_blog_id );

// Mirror production's bbPress routing and pacing options.
update_option( '_bbp_root_slug', 'forums' );
update_option( '_bbp_include_root', 0 );
update_option( '_bbp_forum_slug', 'r' );
update_option( '_bbp_topic_slug', 't' );
update_option( '_bbp_user_slug', 'u' );
update_option( '_bbp_edit_slug', 'edit' );
update_option( '_bbp_edit_lock', 30 );
update_option( '_bbp_replies_per_page', 15 );
update_option( '_bbp_topics_per_page', 12 );
delete_option( 'rewrite_rules' );

restore_current_blog();

// bbPress (and the community plugin) only load on the community site, never
// in this main-site run-php process, so forum content is created by the
// journey fixture on the first community request (forum_fuzz_seed_content).
update_site_option( 'ec_rig_forum_fuzz_seed_pending', 1 );
update_site_option( 'ec_rig_forum_fuzz_reached', array() );
update_site_option( 'ec_rig_forum_fuzz_observations', array() );
update_site_option(
	'ec_rig_journey_fixture_forum_fuzz',
	array(
		'user_id' => FORUM_FUZZ_MEMBER_ID,
		'blog_id' => $ff_blog_id,
	)
);

echo 'forum-activity-fuzz seeded users and options on blog ' . $ff_blog_id . "; content pending first community request\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
