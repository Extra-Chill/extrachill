<?php

$plan = get_site_option( 'extrachill_auth_fuzz_plan', array() );
$fixture = get_site_option( 'extrachill_auth_fuzz_fixture', array() );
$sites = get_site_option( 'extrachill_auth_fuzz_sites', array() );
$passes = 0;

function auth_fuzz_assert( $condition, string $message ): void {
	global $passes;
	if ( ! $condition ) throw new RuntimeException( $message );
	++$passes;
}

function auth_fuzz_rest( string $route, array $params, int $user_id = 0 ): WP_REST_Response {
	if ( ! defined( 'REST_REQUEST' ) ) define( 'REST_REQUEST', true );
	wp_set_current_user( $user_id );
	$request = new WP_REST_Request( 'POST', $route );
	$request->set_body_params( $params );
	return rest_ensure_response( rest_do_request( $request ) );
}

function auth_fuzz_registration( array $overrides ): array {
	return array_merge( array(
		'email' => 'unused@example.test', 'password' => 'valid-pass-248', 'password_confirm' => 'valid-pass-248',
		'device_id' => '00000000-0000-4000-8000-000000000248', 'device_name' => 'Auth Fuzz', 'set_cookie' => false,
		'registration_source' => 'auth-fuzz', 'registration_method' => 'standard',
	), $overrides );
}

function auth_fuzz_network_user_count(): int {
	return count( get_users( array( 'blog_id' => 0, 'fields' => 'ID' ) ) );
}

$initial_count = (int) $fixture['initial_user_count'];
foreach ( $sites as $site_key => $site_id ) {
	switch_to_blog( (int) $site_id );
	$login_page = get_page_by_path( 'login' );
	auth_fuzz_assert( $login_page instanceof WP_Post, 'Login page is missing on ' . $site_key . '.' );
	auth_fuzz_assert( has_block( 'extrachill/login-register', $login_page ), 'Login block is missing on ' . $site_key . '.' );
	restore_current_blog();
}
foreach ( $plan['invalid_registrations'] as $case ) {
	$response = auth_fuzz_rest( '/extrachill/v1/auth/register', auth_fuzz_registration( $case ) );
	auth_fuzz_assert( $response->get_status() >= 400, $case['id'] . ' unexpectedly registered.' );
	auth_fuzz_assert( auth_fuzz_network_user_count() === $initial_count, $case['id'] . ' mutated the network user count.' );
}

$created = auth_fuzz_rest( '/extrachill/v1/auth/register', auth_fuzz_registration( array( 'email' => $plan['generated_email'] ) ) );
$created_data = (array) $created->get_data();
$created_id = (int) ( $created_data['user']['id'] ?? 0 );
auth_fuzz_assert( 200 === $created->get_status() && $created_id > 0, 'Valid generated registration failed: ' . wp_json_encode( $created_data ) );
auth_fuzz_assert( auth_fuzz_network_user_count() === $initial_count + 1, 'Valid registration did not create exactly one user.' );
auth_fuzz_assert( is_user_member_of_blog( $created_id, (int) $sites['community'] ), 'Registered user lacks Community membership.' );
$duplicate = auth_fuzz_rest( '/extrachill/v1/auth/register', auth_fuzz_registration( array( 'email' => $plan['generated_email'] ) ) );
auth_fuzz_assert( 400 === $duplicate->get_status(), 'Duplicate registration did not return a generic client error.' );
auth_fuzz_assert( auth_fuzz_network_user_count() === $initial_count + 1, 'Duplicate registration created another user.' );

$existing = get_user_by( 'id', (int) $fixture['existing_user_id'] );
foreach ( array( $existing->user_login, $existing->user_email ) as $identifier ) {
	$login = auth_fuzz_rest( '/extrachill/v1/auth/login', array( 'identifier' => $identifier, 'password' => 'existing-pass-248', 'device_id' => '00000000-0000-4000-8000-000000000249', 'set_cookie' => false ) );
	auth_fuzz_assert( 200 === $login->get_status(), 'Login failed for ' . ( is_email( $identifier ) ? 'email.' : 'username.' ) );
	auth_fuzz_assert( (int) ( $login->get_data()['user']['id'] ?? 0 ) === (int) $existing->ID, 'Login resolved the wrong network identity.' );
}
foreach ( $plan['redirect_cases'] as $redirect ) {
	$login = auth_fuzz_rest( '/extrachill/v1/auth/login', array( 'identifier' => $existing->user_login, 'password' => 'existing-pass-248', 'device_id' => '00000000-0000-4000-8000-000000000250', 'redirect_to' => $redirect ) );
	$destination = (string) ( $login->get_data()['redirect_url'] ?? '' );
	auth_fuzz_assert( ! str_contains( $destination, 'outside.example' ) && ! str_starts_with( $destination, 'javascript:' ), 'Unsafe redirect escaped the network.' );
}
for ( $attempt = 0; $attempt < 5; ++$attempt ) {
	$failed = auth_fuzz_rest( '/extrachill/v1/auth/login', array( 'identifier' => $existing->user_login, 'password' => 'wrong-pass', 'device_id' => '00000000-0000-4000-8000-000000000251' ) );
	auth_fuzz_assert( $failed->get_status() >= 400, 'Bad credential attempt authenticated.' );
}
auth_fuzz_assert( ec_is_login_blocked( $existing->user_login ), 'Rate-limit boundary did not block the generated identity.' );

update_site_option( 'extrachill_auth_fuzz_backend_created_id', $created_id );
printf( "Auth fuzz backend passed (%d assertions, seed %s).\n", $passes, $plan['seed'] );
