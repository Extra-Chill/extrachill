<?php
/**
 * Studio brand-socials render access contract.
 */

define( 'ABSPATH', __DIR__ . '/' );

$access_state = array(
	'manage_options'       => false,
	'manage_brand_socials' => false,
	'feature_available'    => false,
);

function __( $text ) {
	return $text;
}

function esc_html_e( $text ) {
	echo $text;
}

function esc_html( $text ) {
	return $text;
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

function esc_url( $url ) {
	return $url;
}

function wp_parse_args( $args, $defaults = array() ) {
	return array_merge( $defaults, $args );
}

function get_block_wrapper_attributes( $attributes = array() ) {
	return 'class="' . ( $attributes['class'] ?? '' ) . '"';
}

function is_user_logged_in() {
	return true;
}

function current_user_can( $capability ) {
	global $access_state;
	return ! empty( $access_state[ $capability ] );
}

function ec_is_team_member() {
	return true;
}

function ec_feature_available() {
	global $access_state;
	return $access_state['feature_available'];
}

function wp_get_current_user() {
	return (object) array( 'display_name' => 'Fixture Team Member' );
}

function get_bloginfo() {
	return 'Extra Chill Studio';
}

function home_url() {
	return 'https://studio.example.test/';
}

function wp_create_nonce() {
	return 'fixture-nonce';
}

function rest_url( $path = '' ) {
	return 'https://studio.example.test/wp-json/' . $path;
}

function is_multisite() {
	return false;
}

function wp_json_encode( $value ) {
	return json_encode( $value );
}

function apply_filters( $hook, $value ) {
	return $value;
}

function render_studio_access_fixture( array $state ): string {
	global $access_state;
	$access_state = array_merge( $access_state, $state );
	$attributes   = array();

	ob_start();
	include dirname( __DIR__ ) . '/src/blocks/studio/render.php';
	return ob_get_clean();
}

function assert_socials_access( bool $expected, array $state, string $message ): void {
	$output = render_studio_access_fixture( $state );
	$actual = str_contains( $output, 'data-can-brand-socials="true"' );
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message );
	}
}

assert_socials_access(
	false,
	array( 'manage_options' => false, 'manage_brand_socials' => false, 'feature_available' => false ),
	'Ordinary team members must not receive brand-social access.'
);
assert_socials_access(
	true,
	array( 'manage_options' => false, 'manage_brand_socials' => true, 'feature_available' => false ),
	'Explicitly granted team members must receive brand-social access.'
);
assert_socials_access(
	true,
	array( 'manage_options' => true, 'manage_brand_socials' => false, 'feature_available' => true ),
	'Administrators must retain brand-social access.'
);

echo "Studio brand-socials access contract passed.\n";
