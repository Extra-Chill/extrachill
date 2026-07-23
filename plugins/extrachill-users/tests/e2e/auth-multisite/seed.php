<?php

$sites = get_site_option( 'extrachill_auth_fuzz_sites', array() );
$plan = get_site_option( 'extrachill_auth_fuzz_plan', array() );
if ( count( $sites ) !== 4 || empty( $plan['seed'] ) ) {
	throw new RuntimeException( 'Auth fuzz topology or case plan is missing.' );
}
$_SERVER['REMOTE_ADDR'] = '127.0.0.248';

function auth_fuzz_ensure_page( string $slug, string $title, string $content ): void {
	global $wpdb;
	$page_id = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = 'page' AND post_status != 'trash' LIMIT 1",
			$slug
		)
	);
	if ( $page_id > 0 ) {
		return;
	}
	$result = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_title'   => $title,
			'post_name'    => $slug,
			'post_status'  => 'publish',
			'post_content' => $content,
		),
		true
	);
	if ( is_wp_error( $result ) || ! $result ) {
		throw new RuntimeException( 'Could not create the ' . $slug . ' fixture page.' );
	}
}

foreach ( $sites as $key => $site_id ) {
	switch_to_blog( (int) $site_id );
	update_option( 'permalink_structure', '/%postname%/' );
	auth_fuzz_ensure_page( 'login', 'Login', '<!-- wp:extrachill/login-register /-->' );
	if ( 'community' === $key ) {
		auth_fuzz_ensure_page( 'onboarding', 'Onboarding', '<!-- wp:extrachill/onboarding /-->' );
	}
	flush_rewrite_rules();
	restore_current_blog();
}
$user_id = username_exists( 'auth_fuzz_existing' );
if ( ! $user_id ) {
	$user_id = wp_create_user( 'auth_fuzz_existing', 'existing-pass-248', 'existing-auth-fuzz@example.test' );
}
if ( is_wp_error( $user_id ) ) {
	throw new RuntimeException( 'Could not create the existing auth persona.' );
}
add_user_to_blog( (int) $sites['community'], (int) $user_id, 'subscriber' );

$nonmember_id = username_exists( 'auth_fuzz_nonmember' );
if ( ! $nonmember_id ) {
	$nonmember_id = wp_create_user( 'auth_fuzz_nonmember', 'nonmember-pass-248', 'nonmember-auth-fuzz@example.test' );
}
$blocked_id = username_exists( 'auth_fuzz_blocked' );
if ( ! $blocked_id ) {
	$blocked_id = wp_create_user( 'auth_fuzz_blocked', 'blocked-pass-248', 'blocked-auth-fuzz@example.test' );
}
$onboarding_id = username_exists( 'auth_fuzz_onboarding' );
if ( ! $onboarding_id ) {
	$onboarding_id = wp_create_user( 'auth_fuzz_onboarding', 'onboarding-pass-248', 'onboarding-auth-fuzz@example.test' );
}
$victim_id = username_exists( 'auth_fuzz_victim' );
if ( ! $victim_id ) {
	$victim_id = wp_create_user( 'auth_fuzz_victim', 'victim-pass-248', 'victim-auth-fuzz@example.test' );
}
foreach ( array( $nonmember_id, $blocked_id, $onboarding_id, $victim_id ) as $persona_id ) {
	if ( is_wp_error( $persona_id ) ) {
		throw new RuntimeException( 'Could not create an adversarial auth persona.' );
	}
}
add_user_to_blog( (int) $sites['community'], (int) $blocked_id, 'subscriber' );
add_user_to_blog( (int) $sites['community'], (int) $onboarding_id, 'subscriber' );
add_user_to_blog( (int) $sites['community'], (int) $victim_id, 'subscriber' );
update_user_meta( (int) $blocked_id, extrachill_users_moderation_meta_key(), array(
	'state'      => 'banned',
	'reason_key' => 'other',
	'effects'    => extrachill_users_get_moderation_policy( 'other' )['effects'],
) );
update_user_meta( (int) $onboarding_id, 'onboarding_completed', '0' );
update_site_option(
	'extrachill_auth_fuzz_fixture',
	array(
		'existing_user_id'   => (int) $user_id,
		'nonmember_user_id'  => (int) $nonmember_id,
		'blocked_user_id'    => (int) $blocked_id,
		'onboarding_user_id' => (int) $onboarding_id,
		'victim_user_id'     => (int) $victim_id,
		'initial_user_count' => count( get_users( array( 'blog_id' => 0, 'fields' => 'ID' ) ) ),
	)
);
