<?php
/**
 * Seed the musician link-page onboarding journey on the real network boot.
 *
 * Recreates the production page shape the journey walks (page slugs and
 * block content read read-only from production on 2026-09-26 via
 * `wp post list --post_type=page` / `wp post get --field=post_content` on
 * each site), and creates one
 * single-scenario fixture musician (not a persona -- see
 * personas/README.md) so the editor half of the journey can be judged even
 * when live browser registration cannot complete in the sandbox.
 *
 * Pages are plain WordPress pages; the browser steps make real front-end
 * HTTP requests that load every per-site plugin normally, so no
 * plugin-context bootstrap is needed here.
 *
 * @package ExtraChillNetwork
 */

const MUSICIAN_JOURNEY_FIXTURE_USER_ID = 601;

/**
 * Resolve a rig site by domain, never by blog ID.
 *
 * @param string $domain Site domain from network-topology.json.
 * @return int Blog ID.
 */
function musician_journey_site_id( string $domain ): int {
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
 * Publish a page at a slug on the current blog unless one already exists.
 *
 * @param string $slug    Page slug.
 * @param string $title   Page title.
 * @param string $content Page content.
 * @return int Page ID.
 */
function musician_journey_ensure_page( string $slug, string $title, string $content ): int {
	$existing = get_page_by_path( $slug );
	if ( $existing instanceof WP_Post ) {
		if ( 'publish' !== $existing->post_status || $existing->post_content !== $content ) {
			wp_update_post(
				array(
					'ID'           => $existing->ID,
					'post_status'  => 'publish',
					'post_content' => $content,
				)
			);
		}
		return (int) $existing->ID;
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
	return (int) $page_id;
}

/**
 * Force-create (or reuse) a fixture user at an exact, deterministic ID.
 *
 * @param int    $user_id Forced user ID.
 * @param string $login   user_login.
 * @param string $email   user_email.
 * @param string $display Display name.
 * @return WP_User
 */
function musician_journey_force_user( int $user_id, string $login, string $email, string $display ): WP_User {
	global $wpdb;
	if ( ! get_user_by( 'id', $user_id ) ) {
		$inserted = $wpdb->insert(
			$wpdb->users,
			array(
				'ID'              => $user_id,
				'user_login'      => $login,
				'user_pass'       => wp_hash_password( 'porch-lights-pass-601' ),
				'user_nicename'   => $login,
				'user_email'      => $email,
				'user_registered' => current_time( 'mysql', true ),
				'display_name'    => $display,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( false === $inserted ) {
			throw new RuntimeException( esc_html( 'Unable to force-create fixture user ' . $login . ' at ID ' . $user_id . '.' ) );
		}
		clean_user_cache( $user_id );
	}
	return new WP_User( $user_id );
}

$main_blog_id      = musician_journey_site_id( 'extrachill.com' );
$artist_blog_id    = musician_journey_site_id( 'artist.extrachill.com' );
$community_blog_id = musician_journey_site_id( 'community.extrachill.com' );
$link_blog_id      = musician_journey_site_id( 'extrachill.link' );

wp_set_current_user( 1 );

if ( ! in_array( get_site_option( 'registration' ), array( 'user', 'all' ), true ) ) {
	update_site_option( 'registration', 'user' );
}

$evidence = array(
	'schema'            => 'extrachill-network/journey-fixture/musician-link-page-onboarding/v1',
	'main_blog_id'      => $main_blog_id,
	'artist_blog_id'    => $artist_blog_id,
	'community_blog_id' => $community_blog_id,
	'link_blog_id'      => $link_blog_id,
	'pages'             => array(),
);

// extrachill.com: /power/ is the "Powered by Extra Chill" footer target on
// every Link Page. extrachill-blog provisions it on admin_init; production
// content is the sentinel the_content filter replaces.
switch_to_blog( $main_blog_id );
update_option( 'permalink_structure', '/%postname%/' );
$evidence['pages']['extrachill.com/power']      = musician_journey_ensure_page( 'power', 'The Power of Extra Chill', '<!-- extrachill-power-manifesto -->' );
$evidence['pages']['extrachill.com/login']      = musician_journey_ensure_page( 'login', 'Login', '<!-- wp:extrachill/login-register /-->' );
$evidence['pages']['extrachill.com/contact-us'] = musician_journey_ensure_page( 'contact-us', 'Contact Us', '<!-- wp:extrachill/contact-form /-->' );
flush_rewrite_rules();
restore_current_blog();

// artist.extrachill.com: the artist platform pages, exactly as production.
switch_to_blog( $artist_blog_id );
update_option( 'permalink_structure', '/%postname%/' );
$artist_home  = 'http://artist.extrachill.com';
$artist_pages = array(
	'login'            => array( 'Login', '<!-- wp:extrachill/login-register {"redirectUrl":"' . $artist_home . '"} /-->' ),
	'create-artist'    => array( 'Create Artist', '<!-- wp:extrachill/artist-creator /-->' ),
	'manage-artist'    => array( 'Manage Artist', '<!-- wp:extrachill/artist-manager /-->' ),
	'manage-link-page' => array( 'Manage Link Page', '<!-- wp:extrachill/link-page-editor /-->' ),
	'analytics'        => array( 'Analytics', '<!-- wp:extrachill/artist-analytics /-->' ),
);
foreach ( $artist_pages as $slug => $artist_page ) {
	$evidence['pages'][ 'artist.extrachill.com/' . $slug ] = musician_journey_ensure_page( $slug, $artist_page[0], $artist_page[1] );
}
flush_rewrite_rules();
restore_current_blog();

// community.extrachill.com: registration and onboarding surfaces.
switch_to_blog( $community_blog_id );
update_option( 'permalink_structure', '/%postname%/' );
$evidence['pages']['community.extrachill.com/login']      = musician_journey_ensure_page( 'login', 'Login', '<!-- wp:extrachill/login-register {"redirectUrl":"http://community.extrachill.com"} /-->' );
$evidence['pages']['community.extrachill.com/onboarding'] = musician_journey_ensure_page( 'onboarding', 'Onboarding', '<!-- wp:extrachill/onboarding /-->' );
flush_rewrite_rules();
restore_current_blog();

// extrachill.link: production carries a /login page on the Link Pages host.
switch_to_blog( $link_blog_id );
update_option( 'permalink_structure', '/%postname%/' );
$evidence['pages']['extrachill.link/login'] = musician_journey_ensure_page( 'login', 'Login', '<!-- wp:extrachill/login-register /-->' );
flush_rewrite_rules();
restore_current_blog();

// Fixture musician: registered, onboarded as an artist, no artist profile
// yet -- the exact state a real musician is in the moment onboarding
// finishes. Used by the editor-half browser steps via auth-user-id=601.
$musician = musician_journey_force_user( MUSICIAN_JOURNEY_FIXTURE_USER_ID, 'porch_lights_fixture', 'porch-lights@example.invalid', 'Sam Porch (Fixture Musician)' );
foreach ( array( $main_blog_id, $artist_blog_id, $community_blog_id, $link_blog_id ) as $member_blog_id ) {
	if ( ! is_user_member_of_blog( MUSICIAN_JOURNEY_FIXTURE_USER_ID, $member_blog_id ) ) {
		add_user_to_blog( $member_blog_id, MUSICIAN_JOURNEY_FIXTURE_USER_ID, 'subscriber' );
	}
}
update_user_meta( MUSICIAN_JOURNEY_FIXTURE_USER_ID, 'user_is_artist', '1' );
update_user_meta( MUSICIAN_JOURNEY_FIXTURE_USER_ID, 'onboarding_completed', '1' );
update_user_meta( MUSICIAN_JOURNEY_FIXTURE_USER_ID, 'onboarding_from_join', '1' );

$evidence['musician_user_id'] = $musician->ID;
update_site_option( 'ec_rig_journey_fixture_musician_link_page_onboarding', $evidence );

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Machine-readable fixture evidence.
printf( "EXTRACHILL_JOURNEY_FIXTURE:%s\n", base64_encode( (string) wp_json_encode( $evidence ) ) );
