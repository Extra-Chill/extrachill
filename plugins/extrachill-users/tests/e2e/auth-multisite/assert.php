<?php

$plan = get_site_option( 'extrachill_auth_fuzz_plan', array() );
$fixture = get_site_option( 'extrachill_auth_fuzz_fixture', array() );
$sites = get_site_option( 'extrachill_auth_fuzz_sites', array() );
$passes = 0;
$_SERVER['REMOTE_ADDR'] = '127.0.0.249';

function auth_fuzz_assert( $condition, string $message ): void {
	global $passes;
	if ( ! $condition ) throw new RuntimeException( $message );
	++$passes;
}

function auth_fuzz_rest( string $route, array $params = array(), int $user_id = 0, string $method = 'POST' ): WP_REST_Response {
	if ( ! defined( 'REST_REQUEST' ) ) define( 'REST_REQUEST', true );
	wp_set_current_user( $user_id );
	$request = new WP_REST_Request( $method, $route );
	$request->set_body_params( $params );
	return rest_ensure_response( rest_do_request( $request ) );
}

function auth_fuzz_error_code( WP_REST_Response $response ): string {
	$data = (array) $response->get_data();
	return (string) ( $data['code'] ?? '' );
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
auth_fuzz_assert( wp_check_password( 'existing-pass-248', $existing->user_pass, $existing->ID ), 'Stored password verification failed for the existing auth persona.' );
$direct_auth = wp_authenticate( $existing->user_login, 'existing-pass-248' );
auth_fuzz_assert( $direct_auth instanceof WP_User, 'Direct authentication failed for the existing auth persona: ' . ( is_wp_error( $direct_auth ) ? implode( ',', $direct_auth->get_error_codes() ) : gettype( $direct_auth ) ) );
foreach ( array( $existing->user_login, $existing->user_email ) as $identifier ) {
	$login = auth_fuzz_rest( '/extrachill/v1/auth/login', array( 'identifier' => $identifier, 'password' => 'existing-pass-248', 'device_id' => '00000000-0000-4000-8000-000000000249', 'set_cookie' => false ) );
	auth_fuzz_assert( 200 === $login->get_status(), 'Login failed for ' . ( is_email( $identifier ) ? 'email: ' : 'username: ' ) . wp_json_encode( $login->get_data() ) );
	auth_fuzz_assert( (int) ( $login->get_data()['user']['id'] ?? 0 ) === (int) $existing->ID, 'Login resolved the wrong network identity.' );
}
$unknown_login = auth_fuzz_rest( '/extrachill/v1/auth/login', array( 'identifier' => 'missing-auth-fuzz', 'password' => 'wrong-pass', 'device_id' => '00000000-0000-4000-8000-000000000252' ) );
$known_login = auth_fuzz_rest( '/extrachill/v1/auth/login', array( 'identifier' => $existing->user_login, 'password' => 'wrong-pass', 'device_id' => '00000000-0000-4000-8000-000000000253' ) );
auth_fuzz_assert( $unknown_login->get_status() === $known_login->get_status(), 'Login status enumerates existing accounts.' );
auth_fuzz_assert( auth_fuzz_error_code( $unknown_login ) === auth_fuzz_error_code( $known_login ), 'Login code enumerates existing accounts.' );
auth_fuzz_assert( (string) ( $unknown_login->get_data()['message'] ?? '' ) === (string) ( $known_login->get_data()['message'] ?? '' ), 'Login message enumerates existing accounts.' );

$nonmember = get_user_by( 'id', (int) $fixture['nonmember_user_id'] );
$nonmember_login = auth_fuzz_rest( '/extrachill/v1/auth/login', array( 'identifier' => $nonmember->user_login, 'password' => 'nonmember-pass-248', 'device_id' => '00000000-0000-4000-8000-000000000254' ) );
auth_fuzz_assert( 403 === $nonmember_login->get_status() && 'extrachill_not_a_member' === auth_fuzz_error_code( $nonmember_login ), 'Non-Community user authenticated.' );
$blocked = get_user_by( 'id', (int) $fixture['blocked_user_id'] );
$blocked_login = auth_fuzz_rest( '/extrachill/v1/auth/login', array( 'identifier' => $blocked->user_login, 'password' => 'blocked-pass-248', 'device_id' => '00000000-0000-4000-8000-000000000255' ) );
$blocked_data = (array) $blocked_login->get_data();
auth_fuzz_assert( $blocked_login->get_status() >= 400 && empty( $blocked_data['access_token'] ) && empty( $blocked_data['refresh_token'] ), sprintf( 'Moderated user login returned status %d with credentials.', $blocked_login->get_status() ) );

$anonymous_me = auth_fuzz_rest( '/extrachill/v1/auth/me', array(), 0, 'GET' );
$anonymous_logout = auth_fuzz_rest( '/extrachill/v1/auth/logout', array( 'device_id' => '00000000-0000-4000-8000-000000000256' ) );
$anonymous_handoff = auth_fuzz_rest( '/extrachill/v1/auth/browser-handoff', array( 'redirect_url' => 'https://community.extrachill.com/' ) );
auth_fuzz_assert( $anonymous_me->get_status() >= 400, 'Anonymous auth/me request succeeded.' );
auth_fuzz_assert( $anonymous_logout->get_status() >= 400, 'Anonymous logout request succeeded.' );
auth_fuzz_assert( $anonymous_handoff->get_status() >= 400, 'Anonymous browser handoff request succeeded.' );

foreach ( $plan['handoff_redirect_cases'] as $redirect ) {
	$handoff = auth_fuzz_rest( '/extrachill/v1/auth/browser-handoff', array( 'redirect_url' => $redirect ), (int) $existing->ID );
	auth_fuzz_assert( 400 === $handoff->get_status(), 'Unsafe browser handoff destination was accepted: ' . $redirect );
}
$handoff = auth_fuzz_rest( '/extrachill/v1/auth/browser-handoff', array( 'redirect_url' => 'https://community.extrachill.com/settings/' ), (int) $existing->ID );
$handoff_url = (string) ( $handoff->get_data()['handoff_url'] ?? '' );
parse_str( (string) wp_parse_url( $handoff_url, PHP_URL_QUERY ), $handoff_query );
$handoff_token = (string) ( $handoff_query['ec_browser_handoff'] ?? '' );
auth_fuzz_assert( 200 === $handoff->get_status() && 64 === strlen( $handoff_token ), 'Valid browser handoff was not created.' );
$handoff_key = 'ec_browser_handoff_' . hash( 'sha256', $handoff_token );
auth_fuzz_assert( is_array( get_site_transient( $handoff_key ) ), 'Browser handoff was not stored under its token hash.' );
$claim_control_key = 'ec_browser_handoff_claim_control_' . hash( 'sha256', $handoff_token );
$main_site_id      = get_main_site_id();
$consumer_site_id  = (int) get_sites( array( 'number' => 1, 'site__not_in' => array( $main_site_id ), 'fields' => 'ids' ) )[0];

switch_to_blog( $main_site_id );
try {
	delete_option( $claim_control_key );
	auth_fuzz_assert( add_option( $claim_control_key, 'winner', '', false ), 'Real options storage could not create an atomic claim.' );
	auth_fuzz_assert( ! add_option( $claim_control_key, 'loser', '', false ), 'Real options storage allowed a duplicate atomic claim.' );
	delete_option( $claim_control_key );
} finally {
	restore_current_blog();
}

switch_to_blog( $consumer_site_id );
try {
	$consumed_handoff = extrachill_users_consume_browser_handoff_token( $handoff_token );
} finally {
	restore_current_blog();
}
auth_fuzz_assert( is_array( $consumed_handoff ) && (int) $consumed_handoff['user_id'] === (int) $existing->ID, 'Valid browser handoff could not be consumed.' );
auth_fuzz_assert( is_wp_error( extrachill_users_consume_browser_handoff_token( $handoff_token ) ), 'Browser handoff token was reusable.' );

$refresh_device = '00000000-0000-4000-8000-000000000257';
$token_login = auth_fuzz_rest( '/extrachill/v1/auth/login', array( 'identifier' => $existing->user_login, 'password' => 'existing-pass-248', 'device_id' => $refresh_device, 'set_cookie' => false ) );
$first_refresh = (string) ( $token_login->get_data()['refresh_token'] ?? '' );
auth_fuzz_assert( '' !== $first_refresh, 'Login did not issue a refresh token.' );
$wrong_device_refresh = auth_fuzz_rest( '/extrachill/v1/auth/refresh', array( 'refresh_token' => $first_refresh, 'device_id' => '00000000-0000-4000-8000-000000000258' ) );
auth_fuzz_assert( 401 === $wrong_device_refresh->get_status(), 'Refresh token worked on another device.' );
$rotated = auth_fuzz_rest( '/extrachill/v1/auth/refresh', array( 'refresh_token' => $first_refresh, 'device_id' => $refresh_device ) );
$second_refresh = (string) ( $rotated->get_data()['refresh_token'] ?? '' );
auth_fuzz_assert( 200 === $rotated->get_status() && '' !== $second_refresh && $second_refresh !== $first_refresh, 'Refresh token did not rotate.' );
delete_transient( 'wp_native_auth_refresh_' . md5( $refresh_device ) );
$replay = auth_fuzz_rest( '/extrachill/v1/auth/refresh', array( 'refresh_token' => $first_refresh, 'device_id' => $refresh_device ) );
auth_fuzz_assert( 401 === $replay->get_status() && 'refresh_token_reused' === auth_fuzz_error_code( $replay ), 'Superseded refresh token replay was not detected.' );
delete_transient( 'wp_native_auth_refresh_' . md5( $refresh_device ) );
$burned_family = auth_fuzz_rest( '/extrachill/v1/auth/refresh', array( 'refresh_token' => $second_refresh, 'device_id' => $refresh_device ) );
auth_fuzz_assert( 401 === $burned_family->get_status(), 'Token family remained active after replay.' );

$expired_device = '00000000-0000-4000-8000-000000000259';
$expired_login = auth_fuzz_rest( '/extrachill/v1/auth/login', array( 'identifier' => $existing->user_login, 'password' => 'existing-pass-248', 'device_id' => $expired_device, 'set_cookie' => false ) );
$expired_token = (string) ( $expired_login->get_data()['refresh_token'] ?? '' );
global $wpdb;
$refresh_table = wp_native_auth_refresh_tokens_table_name();
$wpdb->update( $refresh_table, array( 'expires_at' => '2000-01-01 00:00:00' ), array( 'device_id' => $expired_device ), array( '%s' ), array( '%s' ) );
$expired = auth_fuzz_rest( '/extrachill/v1/auth/refresh', array( 'refresh_token' => $expired_token, 'device_id' => $expired_device ) );
auth_fuzz_assert( 401 === $expired->get_status() && 'refresh_token_expired' === auth_fuzz_error_code( $expired ), 'Expired refresh token was accepted.' );

$victim = get_user_by( 'id', (int) $fixture['victim_user_id'] );
$victim_device = '00000000-0000-4000-8000-000000000260';
$victim_login = auth_fuzz_rest( '/extrachill/v1/auth/login', array( 'identifier' => $victim->user_login, 'password' => 'victim-pass-248', 'device_id' => $victim_device, 'set_cookie' => false ) );
$victim_token = (string) ( $victim_login->get_data()['refresh_token'] ?? '' );
$cross_user_logout = auth_fuzz_rest( '/extrachill/v1/auth/logout', array( 'device_id' => $victim_device ), (int) $existing->ID );
auth_fuzz_assert( 200 === $cross_user_logout->get_status() && empty( $cross_user_logout->get_data()['success'] ), 'User revoked another account\'s device.' );
$victim_refresh = auth_fuzz_rest( '/extrachill/v1/auth/refresh', array( 'refresh_token' => $victim_token, 'device_id' => $victim_device ) );
auth_fuzz_assert( 200 === $victim_refresh->get_status(), 'Cross-user logout invalidated the victim session.' );

$onboarding = get_user_by( 'id', (int) $fixture['onboarding_user_id'] );
foreach ( $plan['invalid_onboarding_usernames'] as $username ) {
	$invalid_onboarding = auth_fuzz_rest( '/extrachill/v1/users/onboarding', array( 'username' => $username ), (int) $onboarding->ID );
	auth_fuzz_assert( $invalid_onboarding->get_status() >= 400, 'Invalid onboarding username was accepted: ' . $username );
	auth_fuzz_assert( '0' === (string) get_user_meta( $onboarding->ID, 'onboarding_completed', true ), 'Invalid onboarding request mutated completion state.' );
}
$target_login_before = $existing->user_login;
$cross_user_onboarding = auth_fuzz_rest( '/extrachill/v1/users/onboarding', array( 'user_id' => (int) $existing->ID, 'username' => 'isolated_auth_fuzz' ), (int) $onboarding->ID );
auth_fuzz_assert( 200 === $cross_user_onboarding->get_status(), 'Authenticated onboarding persona could not complete onboarding.' );
auth_fuzz_assert( get_user_by( 'id', (int) $existing->ID )->user_login === $target_login_before, 'Onboarding request mutated another user.' );
$duplicate_onboarding = auth_fuzz_rest( '/extrachill/v1/users/onboarding', array( 'username' => 'second_auth_fuzz' ), (int) $onboarding->ID );
auth_fuzz_assert( 400 === $duplicate_onboarding->get_status() && 'already_completed' === auth_fuzz_error_code( $duplicate_onboarding ), 'Duplicate onboarding was not rejected.' );

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
$alias_bypass = auth_fuzz_rest( '/extrachill/v1/auth/login', array( 'identifier' => $existing->user_email, 'password' => 'existing-pass-248', 'device_id' => '00000000-0000-4000-8000-000000000261' ) );
auth_fuzz_assert( $alias_bypass->get_status() >= 400, 'Rate limit was bypassed through the account email alias.' );

update_site_option( 'extrachill_auth_fuzz_backend_created_id', $created_id );
printf( "Auth fuzz backend passed (%d assertions, seed %s).\n", $passes, $plan['seed'] );
