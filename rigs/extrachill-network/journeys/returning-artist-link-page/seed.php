<?php
/**
 * Seed the returning-artist Link Page journey.
 *
 * Production page shapes on the artist site (as the musician journey seeds
 * them) and one single-scenario fixture musician
 * (not a persona): registered, onboarded as an artist, with no artist yet.
 * The journey's setup step creates the artist and its first link; every
 * later step is a fresh browser (no stored editor token) coming back from a
 * different part of the network.
 *
 * @package ExtraChillNetwork
 */

const RETURNING_ARTIST_USER_ID = 701;

/**
 * Resolve a rig site by domain, never by blog ID.
 *
 * @param string $domain Site domain.
 * @return int
 */
function returning_artist_site_id( string $domain ): int {
	$sites = get_sites(
		array(
			'domain' => $domain,
			'number' => 1,
		)
	);
	if ( empty( $sites ) ) {
		throw new RuntimeException( esc_html( 'Journey seed could not resolve site by domain: ' . $domain ) );
	}
	return (int) $sites[0]->blog_id;
}

/**
 * Publish a page at a slug on the current blog unless it exists.
 *
 * @param string $slug    Slug.
 * @param string $title   Title.
 * @param string $content Content.
 */
function returning_artist_ensure_page( string $slug, string $title, string $content ): void {
	if ( get_page_by_path( $slug ) instanceof WP_Post ) {
		return;
	}
	$page_id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_name'    => $slug,
			'post_content' => $content,
		),
		true
	);
	if ( is_wp_error( $page_id ) ) {
		throw new RuntimeException( esc_html( 'Could not publish ' . $slug . ': ' . $page_id->get_error_message() ) );
	}
}

$artist_blog_id = returning_artist_site_id( 'artist.extrachill.com' );
$member_sites   = array(
	returning_artist_site_id( 'extrachill.com' ),
	$artist_blog_id,
	returning_artist_site_id( 'community.extrachill.com' ),
	returning_artist_site_id( 'extrachill.link' ),
);

wp_set_current_user( 1 );

switch_to_blog( $artist_blog_id );
update_option( 'permalink_structure', '/%postname%/' );
returning_artist_ensure_page( 'create-artist', 'Create Artist', '<!-- wp:extrachill/artist-creator /-->' );
returning_artist_ensure_page( 'manage-artist', 'Manage Artist', '<!-- wp:extrachill/artist-manager /-->' );
returning_artist_ensure_page( 'manage-link-page', 'Manage Link Page', '<!-- wp:extrachill/link-page-editor /-->' );
returning_artist_ensure_page( 'analytics', 'Analytics', '<!-- wp:extrachill/artist-analytics /-->' );
returning_artist_ensure_page( 'login', 'Login', '<!-- wp:extrachill/login-register /-->' );
flush_rewrite_rules();
restore_current_blog();

global $wpdb;
if ( ! get_user_by( 'id', RETURNING_ARTIST_USER_ID ) ) {
	$inserted = $wpdb->insert(
		$wpdb->users,
		array(
			'ID'              => RETURNING_ARTIST_USER_ID,
			'user_login'      => 'night_shift_fixture',
			'user_pass'       => wp_hash_password( wp_generate_password( 32 ) ),
			'user_nicename'   => 'night_shift_fixture',
			'user_email'      => 'night-shift@example.invalid',
			'user_registered' => current_time( 'mysql', true ),
			'display_name'    => 'Night Shift (Fixture Musician)',
		),
		array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
	);
	if ( false === $inserted ) {
		throw new RuntimeException( 'Unable to create the returning-artist fixture user.' );
	}
	clean_user_cache( RETURNING_ARTIST_USER_ID );
}
foreach ( $member_sites as $member_site ) {
	if ( ! is_user_member_of_blog( RETURNING_ARTIST_USER_ID, $member_site ) ) {
		add_user_to_blog( $member_site, RETURNING_ARTIST_USER_ID, 'subscriber' );
	}
}
update_user_meta( RETURNING_ARTIST_USER_ID, 'user_is_artist', '1' );
update_user_meta( RETURNING_ARTIST_USER_ID, 'onboarding_completed', '1' );

$evidence = array(
	'schema'         => 'extrachill-network/journey-fixture/returning-artist-link-page/v1',
	'user_id'        => RETURNING_ARTIST_USER_ID,
	'artist_blog_id' => $artist_blog_id,
	'link_blog_id'   => returning_artist_site_id( 'extrachill.link' ),
);
update_site_option( 'ec_rig_journey_fixture_returning_artist', $evidence );

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Machine-readable fixture evidence.
printf( "EXTRACHILL_JOURNEY_FIXTURE:%s\n", base64_encode( (string) wp_json_encode( $evidence ) ) );
