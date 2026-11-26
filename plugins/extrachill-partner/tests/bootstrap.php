<?php
/** Minimal standalone WordPress test doubles; HTTP retains pre_http_request interception. */
define( 'ABSPATH', __DIR__ );
define( 'MINUTE_IN_SECONDS', 60 );
class WP_Error {}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function add_action( $name, $callback ) { $GLOBALS['actions'][ $name ][] = $callback; }
function add_filter( $name, $callback ) { $GLOBALS['filters'][ $name ][] = $callback; }
function apply_filters( $name, $value, ...$args ) {
	foreach ( $GLOBALS['filters'][ $name ] ?? array() as $callback ) { $value = $callback( $value, ...$args ); }
	return $value;
}
function get_option( $name, $default = false ) { return $GLOBALS['options'][ $name ] ?? $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['options'][ $name ] = $value; $GLOBALS['autoload'][ $name ] = $autoload; }
function add_option( $name, $value, $deprecated = '', $autoload = null ) { if ( ! isset( $GLOBALS['options'][ $name ] ) ) { update_option( $name, $value, $autoload ); } }
function get_transient( $name ) { return $GLOBALS['transients'][ $name ] ?? false; }
function set_transient( $name, $value, $ttl ) { $GLOBALS['transients'][ $name ] = $value; $GLOBALS['ttls'][ $name ] = $ttl; }
function wp_remote_get( $url, $args ) { return apply_filters( 'pre_http_request', false, $args, $url ); }
function wp_remote_retrieve_response_code( $response ) { return $response['response']['code']; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function __( $text, $domain = '' ) { return $text; }
function esc_html( $text ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text ) { return esc_html( $text ); }
function esc_html__( $text, $domain ) { return esc_html( $text ); }
function esc_attr__( $text, $domain ) { return esc_attr( $text ); }
function esc_url( $url ) { return preg_match( '/^(javascript|data):/i', $url ) ? '' : htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' ); }
function current_user_can( $capability ) { return $GLOBALS['admin'] ?? false; }
function plugins_url( $path, $file ) { return 'https://partner.example/wp-content/plugins/extrachill-partner/' . $path; }
function wp_enqueue_style( $name ) {}
function wp_register_style( ...$args ) {}
function wp_register_script( ...$args ) {}
function wp_set_script_translations( ...$args ) {}
function register_block_type( $path ) { $GLOBALS['blocks'][ basename( $path ) ] = $path; }
function add_shortcode( $name, $callback ) { $GLOBALS['shortcodes'][ $name ] = $callback; }
function shortcode_atts( $defaults, $attributes ) { return array_merge( $defaults, (array) $attributes ); }
function home_url( $path ) { return 'https://partner.example/subdirectory/'; }
function wp_parse_url( $url ) { return parse_url( $url ); }
function add_query_arg( $key, $value, $url = null ) {
	if ( is_array( $key ) ) { $url = $value; $query = $key; } else { $query = array( $key => $value ); }
	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $query );
}
function wp_date( $format, $timestamp, $zone ) { return ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $zone )->format( $format ); }
function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
function add_settings_error( ...$args ) { $GLOBALS['settings_errors'][] = $args; }
require dirname( __DIR__ ) . '/extrachill-partner.php';
foreach ( $GLOBALS['actions']['init'] as $callback ) { $callback(); }
