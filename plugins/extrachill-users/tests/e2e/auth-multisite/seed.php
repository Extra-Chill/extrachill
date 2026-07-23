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
update_site_option(
	'extrachill_auth_fuzz_fixture',
	array(
		'existing_user_id'  => (int) $user_id,
		'initial_user_count' => count( get_users( array( 'blog_id' => 0, 'fields' => 'ID' ) ) ),
	)
);
