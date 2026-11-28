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

foreach ( $ff_users as $ff_user ) {
	if ( function_exists( 'bbp_set_user_role' ) ) {
		bbp_set_user_role( $ff_user[0], bbp_get_participant_role() );
	}
}

$ff_forum = static function ( $slug ) {
	$post = get_page_by_path( $slug, OBJECT, bbp_get_forum_post_type() );
	if ( ! $post instanceof WP_Post ) {
		throw new RuntimeException( 'Community activation forum missing: ' . $slug );
	}
	return (int) $post->ID;
};
$ff_live    = $ff_forum( 'live-shows-scenes' );
$ff_music   = $ff_forum( 'music-discussion' );
$ff_artists = $ff_forum( 'artist-corner' );

$ff_para = static function ( ...$paragraphs ) {
	$out = '';
	foreach ( $paragraphs as $p ) {
		$out .= "<!-- wp:paragraph -->\n<p>" . $p . "</p>\n<!-- /wp:paragraph -->\n\n";
	}
	return trim( $out );
};

$ff_topic = static function ( $forum_id, $author, $title, $content, $ago, $slug ) {
	$date = gmdate( 'Y-m-d H:i:s', time() - $ago );
	$id   = bbp_insert_topic(
		array(
			'post_parent'   => $forum_id,
			'post_author'   => $author,
			'post_title'    => $title,
			'post_name'     => $slug,
			'post_content'  => $content,
			'post_date'     => get_date_from_gmt( $date ),
			'post_date_gmt' => $date,
		),
		array( 'forum_id' => $forum_id )
	);
	if ( ! $id || is_wp_error( $id ) ) {
		throw new RuntimeException( 'Unable to seed topic: ' . $title );
	}
	return (int) $id;
};
$ff_reply = static function ( $forum_id, $topic_id, $author, $content, $ago ) {
	$date = gmdate( 'Y-m-d H:i:s', time() - $ago );
	$id   = bbp_insert_reply(
		array(
			'post_parent'   => $topic_id,
			'post_author'   => $author,
			'post_content'  => $content,
			'post_date'     => get_date_from_gmt( $date ),
			'post_date_gmt' => $date,
		),
		array(
			'forum_id' => $forum_id,
			'topic_id' => $topic_id,
		)
	);
	if ( ! $id || is_wp_error( $id ) ) {
		throw new RuntimeException( 'Unable to seed reply on topic ' . $topic_id );
	}
	return (int) $id;
};

// Busy topic: 22 replies so it paginates at 15 per page.
$ff_busy = $ff_topic(
	$ff_live,
	812,
	'Best small rooms for a Tuesday night show?',
	$ff_para( 'Trying to book a weeknight bill for three local bands. Which rooms actually show up on a Tuesday? Looking for 100 to 200 cap, decent sound, not a sports bar.' ),
	3 * DAY_IN_SECONDS,
	'tuesday-rooms'
);
$ff_voices = array( 813, 814, 812 );
for ( $i = 1; $i <= 20; $i++ ) {
	$ff_reply(
		$ff_live,
		$ff_busy,
		$ff_voices[ $i % 3 ],
		$ff_para( 'Reply ' . $i . ': the back room on the east side has been solid lately, sound person actually cares. Load in is rough though.' ),
		3 * DAY_IN_SECONDS - $i * HOUR_IN_SECONDS
	);
}
$ff_old_own_reply = $ff_reply( $ff_live, $ff_busy, FORUM_FUZZ_MEMBER_ID, $ff_para( 'Old take from Fuzz Fan: Tuesdays work if you pair with a trivia crowd.' ), 2 * DAY_IN_SECONDS );
$ff_fresh_own_reply = $ff_reply( $ff_live, $ff_busy, FORUM_FUZZ_MEMBER_ID, $ff_para( 'Fresh take from Fuzz Fan: I will be at the next one, count me in.' ), 60 );

// Stress topic: long unbroken word, long URL, emoji, long reply (truncation).
$ff_stress = $ff_topic(
	$ff_music,
	813,
	'Supercalifragilisticexpialidocious-shoegaze-dreampop-postrock-crossover recs 🎸🌊✨ (the long title edition, with a title that keeps going past where it should probably stop)',
	$ff_para(
		'Drop your favourite records in this lane. Bonus points for anything on tape.',
		'Reference: https://example.invalid/a/really/long/path/that/never/breaks/because/it/is/one/token/0123456789abcdefghijklmnopqrstuvwxyz0123456789abcdefghijklmnopqrstuvwxyz',
		'Aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
	),
	DAY_IN_SECONDS,
	'long-title-recs'
);
$ff_reply(
	$ff_music,
	$ff_stress,
	812,
	$ff_para( ...array_fill( 0, 14, 'This is a long reply that should be truncated behind a Show More toggle. It keeps rambling about reverb pedals, tape hiss, and the one show where the fog machine set off the alarm.' ) ),
	20 * HOUR_IN_SECONDS
);
$ff_reply( $ff_music, $ff_stress, 814, $ff_para( '🎧🎧🎧 <strong>bold</strong> and <em>italic</em> and a <a href="https://example.invalid/">link</a>' ), 10 * HOUR_IN_SECONDS );

// Member's own topic (edit target) and a quiet topic with zero replies.
$ff_own_topic = $ff_topic( $ff_artists, FORUM_FUZZ_MEMBER_ID, 'Fuzz Fan here, new to the scene', $ff_para( 'Hey all, I play bass and just moved to town. Say hi!' ), 120, 'fuzz-fan-intro' );
$ff_quiet     = $ff_topic( $ff_artists, 814, 'Anyone recording at home on a budget?', $ff_para( 'What is the cheapest interface that does not suck?' ), 5 * HOUR_IN_SECONDS, 'budget-home-recording' );

// A reply that mentions the member, so notifications have something in them.
$ff_reply( $ff_artists, $ff_own_topic, 812, $ff_para( 'Welcome @fuzzfan! Come out to the Tuesday show.' ), 30 );

$ff_busy_slug  = get_post_field( 'post_name', $ff_busy );
$ff_stress_url = bbp_get_topic_permalink( $ff_stress );
restore_current_blog();

update_site_option( 'ec_rig_forum_fuzz_reached', array() );
update_site_option( 'ec_rig_forum_fuzz_observations', array() );
update_site_option(
	'ec_rig_journey_fixture_forum_fuzz',
	array(
		'user_id'          => FORUM_FUZZ_MEMBER_ID,
		'blog_id'          => $ff_blog_id,
		'busy_topic'       => $ff_busy,
		'busy_slug'        => $ff_busy_slug,
		'stress_topic'     => $ff_stress,
		'own_topic'        => $ff_own_topic,
		'quiet_topic'      => $ff_quiet,
		'fresh_own_reply'  => $ff_fresh_own_reply,
		'old_own_reply'    => $ff_old_own_reply,
	)
);

echo 'forum-activity-fuzz seeded on blog ' . $ff_blog_id . ': busy ' . $ff_busy . ' (' . $ff_busy_slug . '), stress ' . $ff_stress . ' ' . $ff_stress_url . ', own topic ' . $ff_own_topic . ', fresh reply ' . $ff_fresh_own_reply . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
