<?php
/**
 * PHPUnit bootstrap for Extra Chill Analytics.
 *
 * The PHP error-log parser and the summary active-count helper are pure (no
 * WP / DB dependencies) and are unit-tested directly. WordPress functions
 * referenced at file scope or by the parser are stubbed so the real source
 * files can be included and exercised without a WordPress test scaffold.
 *
 * The existing source-string contract tests (e.g. GetCrosslinkTargetsTest)
 * need none of this and are unaffected by these definitions.
 *
 * @package ExtraChill\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	// Satisfy the plugin's `defined( 'ABSPATH' ) || exit;` include guards.
	define( 'ABSPATH', true );
}

// Time constants used across the plugin.
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'MB_IN_BYTES' ) ) {
	define( 'MB_IN_BYTES', 1048576 );
}

if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/ec-analytics-wp-content' );
}

require_once __DIR__ . '/class-wp-post.php';
require_once __DIR__ . '/class-wp-error.php';

// Minimal WordPress function stubs (only when a real WP is not present). These
// are intentional polyfill stubs for the unit-test harness, written to satisfy
// the WordPress coding standard rather than wrap WP_Filesystem.
if ( ! function_exists( 'add_action' ) ) {
	/**
	 * Stub for the WordPress add_action() function.
	 *
	 * @param mixed ...$args Hook name, callback, and optional priority/args.
	 */
	function add_action( ...$args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$GLOBALS['extrachill_analytics_test_registered_actions'][] = $args;
		return true;
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * Stub for the WordPress add_filter() function.
	 *
	 * @param mixed ...$args Hook name, callback, and optional priority/args.
	 */
	function add_filter( ...$args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return true;
	}
}
if ( ! function_exists( '__' ) ) {
	/**
	 * Return untranslated fixture text.
	 *
	 * @param string $text Text to translate.
	 * @return string Original text.
	 */
	function __( $text ) {
		return $text;
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * Encode a fixture value as JSON.
	 *
	 * @param mixed $value Value to encode.
	 * @return string|false Encoded value.
	 */
	function wp_json_encode( $value ) {
		return json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- This is the wp_json_encode test polyfill.
	}
}
if ( ! function_exists( 'wp_register_ability' ) ) {
	/**
	 * Capture ability registrations for contract tests.
	 *
	 * @param string $name Ability name.
	 * @param array  $args Ability arguments.
	 * @return bool True.
	 */
	function wp_register_ability( $name, $args ) {
		$GLOBALS['extrachill_analytics_registered_abilities'][ $name ] = $args;
		return true;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Stub for the WordPress apply_filters() function.
	 *
	 * @param string $tag    The filter hook name.
	 * @param mixed  $value  The value to return (filters are no-ops here).
	 * @return mixed The untouched value.
	 */
	function apply_filters( $tag, $value ) {
		return $value;
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * Check the test error fixture type.
	 *
	 * @param mixed $value Candidate value.
	 * @return bool
	 */
	function is_wp_error( $value ) {
		return $value instanceof WP_Error;
	}
}
if ( ! function_exists( 'wp_salt' ) ) {
	/**
	 * Return a stable test salt.
	 *
	 * @param string $scheme Salt scheme.
	 * @return string
	 */
	function wp_salt( $scheme = 'auth' ) {
		return 'test-salt-' . $scheme;
	}
}
if ( ! function_exists( 'wp_using_ext_object_cache' ) ) {
	/**
	 * Return the persistent-cache fixture state.
	 *
	 * @return bool
	 */
	function wp_using_ext_object_cache() {
		return ! isset( $GLOBALS['extrachill_analytics_test_ext_object_cache'] )
			|| (bool) $GLOBALS['extrachill_analytics_test_ext_object_cache'];
	}
}
if ( ! function_exists( 'wp_cache_add' ) ) {
	/**
	 * Atomically add a cache fixture when absent.
	 *
	 * @param string $key        Cache key.
	 * @param mixed  $value      Cache value.
	 * @param string $group      Cache group.
	 * @param int    $expiration Expiration (unused in fixture).
	 * @return bool
	 */
	function wp_cache_add( $key, $value, $group = '', $expiration = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( isset( $GLOBALS['extrachill_analytics_test_cache'][ $group ][ $key ] ) ) {
			return false;
		}
		$GLOBALS['extrachill_analytics_test_cache'][ $group ][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'wp_cache_incr' ) ) {
	/**
	 * Atomically increment an existing cache fixture.
	 *
	 * @param string $key    Cache key.
	 * @param int    $offset Increment amount.
	 * @param string $group  Cache group.
	 * @return int|false
	 */
	function wp_cache_incr( $key, $offset = 1, $group = '' ) {
		if ( ! isset( $GLOBALS['extrachill_analytics_test_cache'][ $group ][ $key ] ) ) {
			return false;
		}
		$GLOBALS['extrachill_analytics_test_cache'][ $group ][ $key ] += (int) $offset;
		return $GLOBALS['extrachill_analytics_test_cache'][ $group ][ $key ];
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * Stub for sanitize_key().
	 *
	 * @param string $key Input key.
	 * @return string Sanitized key.
	 */
	function sanitize_key( $key ) {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $key ) );
	}
}
if ( ! function_exists( 'sanitize_title' ) ) {
	/**
	 * Normalize a title fixture to a slug.
	 *
	 * @param string $title Candidate title.
	 * @return string
	 */
	function sanitize_title( $title ) {
		return trim( strtolower( preg_replace( '/[^a-z0-9]+/i', '-', (string) $title ) ), '-' );
	}
}
if ( ! function_exists( 'untrailingslashit' ) ) {
	/**
	 * Remove trailing slashes from a fixture string.
	 *
	 * @param string $value Input value.
	 * @return string
	 */
	function untrailingslashit( $value ) {
		return rtrim( (string) $value, '/\\' );
	}
}
if ( ! function_exists( 'extrachill_analytics_events_table' ) ) {
	/**
	 * Return the fixture analytics events table name.
	 *
	 * @return string
	 */
	function extrachill_analytics_events_table() {
		return 'wp_extrachill_analytics_events';
	}
}
if ( ! function_exists( 'extrachill_get_analytics_events' ) ) {
	/**
	 * Return a paginated outbound-report fixture when configured by a test.
	 *
	 * @param array $args Event query arguments.
	 * @return array<object> Fixture event rows.
	 */
	function extrachill_get_analytics_events( $args = array() ) {
		if ( isset( $GLOBALS['extrachill_analytics_bridge_fixture_rows'] ) ) {
			$GLOBALS['extrachill_analytics_bridge_query_args'][] = $args;
			$rows = $GLOBALS['extrachill_analytics_bridge_fixture_rows'];
			if ( ! empty( $args['before_id'] ) ) {
				$rows = array_values(
					array_filter(
						$rows,
						static function ( $row ) use ( $args ) {
							return (int) $row->id < (int) $args['before_id'];
						}
					)
				);
			}
			if ( isset( $args['orderby'], $args['order'] ) && 'id' === $args['orderby'] && 'DESC' === $args['order'] ) {
				usort(
					$rows,
					static function ( $a, $b ) {
						return (int) $b->id <=> (int) $a->id;
					}
				);
			}

			return array_slice( $rows, 0, isset( $args['limit'] ) ? (int) $args['limit'] : 100 );
		}

		$offset = isset( $args['offset'] ) ? (int) $args['offset'] : 0;
		$limit  = isset( $args['limit'] ) ? (int) $args['limit'] : 100;
		$rows   = isset( $GLOBALS['extrachill_analytics_outbound_fixture_rows'] )
			? $GLOBALS['extrachill_analytics_outbound_fixture_rows']
			: array();

		return array_slice( $rows, $offset, $limit );
	}
}
if ( ! function_exists( 'extrachill_get_analytics_event_stats' ) ) {
	/**
	 * Return event-detail fixtures configured by summary ability tests.
	 *
	 * @param string $event_type Event type (unused in the stub).
	 * @param int    $days       Lookback days (unused in the stub).
	 * @param int    $blog_id    Blog ID (unused in the stub).
	 * @return array<string,mixed> Event detail fixture.
	 */
	function extrachill_get_analytics_event_stats( $event_type, $days = 30, $blog_id = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return isset( $GLOBALS['extrachill_analytics_event_stats_fixture'] )
			? $GLOBALS['extrachill_analytics_event_stats_fixture']
			: array(
				'total'      => 0,
				'by_date'    => array(),
				'by_source'  => array(),
				'by_context' => array(),
			);
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/**
	 * Stub for wp_strip_all_tags().
	 *
	 * @param string $text Markup that may contain HTML tags.
	 * @return string Tag-free text.
	 */
	function wp_strip_all_tags( $text ) {
		return strip_tags( (string) $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	/**
	 * Stub for wp_unslash() used by the security classifier.
	 *
	 * Recursively strips backslashes so the classifier can be unit-tested
	 * against the same normalization it applies in production.
	 *
	 * @param string|array $value String or array of strings to unslash.
	 * @return string|array Unslashed value, preserving shape.
	 */
	function wp_unslash( $value ) {
		if ( is_array( $value ) ) {
			return array_map( 'wp_unslash', $value );
		}
		return stripslashes( (string) $value );
	}
}
if ( ! function_exists( 'get_site_option' ) ) {
	/**
	 * Stub for get_site_option().
	 *
	 * @param string $option  Option name (unused in the stub).
	 * @param mixed  $fallback Default to return when the option is absent.
	 * @return mixed The fallback default.
	 */
	function get_site_option( $option, $fallback = false ) {
		if ( isset( $GLOBALS['extrachill_analytics_test_site_options'] ) && array_key_exists( $option, $GLOBALS['extrachill_analytics_test_site_options'] ) ) {
			return $GLOBALS['extrachill_analytics_test_site_options'][ $option ];
		}
		return $fallback;
	}
}
if ( ! function_exists( 'update_site_option' ) ) {
	/**
	 * Stub for update_site_option().
	 *
	 * @param string $option Option name (unused in the stub).
	 * @param mixed  $val    Option value (unused in the stub).
	 * @return bool Always true in the stub.
	 */
	function update_site_option( $option, $val ) {
		$GLOBALS['extrachill_analytics_test_site_options'][ $option ] = $val;
		return true;
	}
}
if ( ! function_exists( 'is_main_site' ) ) {
	/**
	 * Return the main-site fixture state.
	 *
	 * @return bool
	 */
	function is_main_site() {
		return ! empty( $GLOBALS['extrachill_analytics_test_is_main_site'] );
	}
}
if ( ! function_exists( 'wp_next_scheduled' ) ) {
	/**
	 * Return a scheduled event fixture.
	 *
	 * @param string $hook Cron hook.
	 * @return int|false
	 */
	function wp_next_scheduled( $hook ) {
		return $GLOBALS['extrachill_analytics_test_scheduled'][ $hook ] ?? false;
	}
}
if ( ! function_exists( 'wp_schedule_event' ) ) {
	/**
	 * Record a recurring event fixture.
	 *
	 * @param int    $timestamp  Event timestamp.
	 * @param string $recurrence Recurrence key.
	 * @param string $hook       Cron hook.
	 * @return bool
	 */
	function wp_schedule_event( $timestamp, $recurrence, $hook ) {
		$GLOBALS['extrachill_analytics_test_scheduled'][ $hook ] = $timestamp;
		$GLOBALS['extrachill_analytics_test_recurring'][]        = compact( 'timestamp', 'recurrence', 'hook' );
		return true;
	}
}
if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	/**
	 * Record a single event fixture.
	 *
	 * @param int    $timestamp Event timestamp.
	 * @param string $hook      Cron hook.
	 * @return bool
	 */
	function wp_schedule_single_event( $timestamp, $hook ) {
		$GLOBALS['extrachill_analytics_test_scheduled'][ $hook ] = $timestamp;
		$GLOBALS['extrachill_analytics_test_single'][]           = compact( 'timestamp', 'hook' );
		return true;
	}
}
if ( ! function_exists( 'add_site_option' ) ) {
	/**
	 * Add an atomic network-option fixture.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Option value.
	 * @return bool
	 */
	function add_site_option( $option, $value ) {
		if ( array_key_exists( $option, $GLOBALS['extrachill_analytics_test_site_options'] ) ) {
			return false;
		}
		$GLOBALS['extrachill_analytics_test_site_options'][ $option ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_site_option' ) ) {
	/**
	 * Delete a network-option fixture.
	 *
	 * @param string $option Option name.
	 * @return bool
	 */
	function delete_site_option( $option ) {
		unset( $GLOBALS['extrachill_analytics_test_site_options'][ $option ] );
		return true;
	}
}
if ( ! function_exists( 'get_site_transient' ) ) {
	/**
	 * Return a network-transient fixture.
	 *
	 * @param string $key Transient key.
	 * @return mixed
	 */
	function get_site_transient( $key ) {
		return $GLOBALS['extrachill_analytics_test_site_transients'][ $key ] ?? false;
	}
}
if ( ! function_exists( 'set_site_transient' ) ) {
	/**
	 * Store a network-transient fixture.
	 *
	 * @param string $key        Transient key.
	 * @param mixed  $value      Transient value.
	 * @param int    $expiration Expiration in seconds.
	 * @return bool
	 */
	function set_site_transient( $key, $value, $expiration ) {
		$GLOBALS['extrachill_analytics_test_site_transients'][ $key ] = $value;
		$GLOBALS['extrachill_analytics_test_transient_ttls'][ $key ]  = $expiration;
		return true;
	}
}
if ( ! function_exists( 'delete_site_transient' ) ) {
	/**
	 * Delete a network-transient fixture.
	 *
	 * @param string $key Transient key.
	 * @return bool
	 */
	function delete_site_transient( $key ) {
		unset( $GLOBALS['extrachill_analytics_test_site_transients'][ $key ] );
		return true;
	}
}
if ( ! function_exists( 'get_user_by' ) ) {
	/**
	 * Return the user fixture.
	 *
	 * @param string $field Lookup field.
	 * @param string $value Lookup value.
	 * @return object|false
	 */
	function get_user_by( $field, $value ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return $GLOBALS['extrachill_analytics_test_user'] ?? false;
	}
}
if ( ! function_exists( 'wp_salt' ) ) {
	/**
	 * Return a deterministic test salt.
	 *
	 * @param string $scheme Salt scheme.
	 * @return string
	 */
	function wp_salt( $scheme = 'auth' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return 'email-privacy-test-salt';
	}
}
if ( ! function_exists( 'get_current_network_id' ) ) {
	/**
	 * Return the authoritative network ID fixture.
	 *
	 * @return int
	 */
	function get_current_network_id() {
		return 1;
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * Stub for sanitize_text_field().
	 *
	 * @param mixed $str Input value.
	 * @return string Scalar cast to string, else empty string.
	 */
	function sanitize_text_field( $str ) {
		return is_scalar( $str ) ? (string) $str : '';
	}
}
if ( ! function_exists( 'is_preview' ) ) {
	/**
	 * Return the preview-request fixture state.
	 *
	 * @return bool Whether the request is a preview.
	 */
	function is_preview() {
		return ! empty( $GLOBALS['extrachill_analytics_test_is_preview'] );
	}
}
if ( ! function_exists( 'is_singular' ) ) {
	/**
	 * Return the singular-route fixture state.
	 *
	 * @return bool Whether the route is singular.
	 */
	function is_singular() {
		return ! empty( $GLOBALS['extrachill_analytics_test_is_singular'] );
	}
}
if ( ! function_exists( 'is_search' ) ) {
	/**
	 * Return the search-route fixture state.
	 *
	 * @return bool Whether the route is search results.
	 */
	function is_search() {
		return ! empty( $GLOBALS['extrachill_analytics_test_is_search'] );
	}
}
if ( ! function_exists( 'is_front_page' ) ) {
	/**
	 * Return the front-page fixture state.
	 *
	 * @return bool Whether the route is the front page.
	 */
	function is_front_page() {
		return ! empty( $GLOBALS['extrachill_analytics_test_is_front_page'] );
	}
}
if ( ! function_exists( 'is_home' ) ) {
	/**
	 * Return the posts-home fixture state.
	 *
	 * @return bool Whether the route is the posts home.
	 */
	function is_home() {
		return ! empty( $GLOBALS['extrachill_analytics_test_is_home'] );
	}
}
if ( ! function_exists( 'is_archive' ) ) {
	/**
	 * Return the archive-route fixture state.
	 *
	 * @return bool Whether the route is an archive.
	 */
	function is_archive() {
		return ! empty( $GLOBALS['extrachill_analytics_test_is_archive'] );
	}
}
if ( ! function_exists( 'is_admin' ) ) {
	/**
	 * Return the admin-request fixture state.
	 *
	 * @return bool Whether the request is administrative.
	 */
	function is_admin() {
		return ! empty( $GLOBALS['extrachill_analytics_test_is_admin'] );
	}
}
if ( ! function_exists( 'wp_doing_ajax' ) ) {
	/**
	 * Return the AJAX-request fixture state.
	 *
	 * @return bool Whether WordPress is handling AJAX.
	 */
	function wp_doing_ajax() {
		return ! empty( $GLOBALS['extrachill_analytics_test_doing_ajax'] );
	}
}
if ( ! function_exists( 'wp_doing_cron' ) ) {
	/**
	 * Return the cron-request fixture state.
	 *
	 * @return bool Whether WordPress is handling cron.
	 */
	function wp_doing_cron() {
		return ! empty( $GLOBALS['extrachill_analytics_test_doing_cron'] );
	}
}
if ( ! function_exists( 'get_current_blog_id' ) ) {
	/**
	 * Return the current blog fixture ID.
	 *
	 * @return int Blog ID.
	 */
	function get_current_blog_id() {
		return isset( $GLOBALS['extrachill_analytics_test_blog_id'] ) ? (int) $GLOBALS['extrachill_analytics_test_blog_id'] : 1;
	}
}
if ( ! function_exists( 'is_multisite' ) ) {
	/**
	 * The unit-test harness exercises a single-blog fixture.
	 *
	 * @return bool False for the fixture.
	 */
	function is_multisite() {
		return false;
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	/**
	 * Stub current_user_can() against a capability map in $GLOBALS.
	 *
	 * @param string $capability Capability name.
	 * @return bool Whether the test granted the capability.
	 */
	function current_user_can( $capability ) {
		$caps = isset( $GLOBALS['extrachill_ingest_capabilities'] ) ? $GLOBALS['extrachill_ingest_capabilities'] : array();
		return ! empty( $caps[ $capability ] );
	}
}
if ( ! function_exists( 'current_user_can_for_site' ) ) {
	/**
	 * Stub target-site capability checks against a per-site map.
	 *
	 * @param int    $site_id    Site ID.
	 * @param string $capability Capability name.
	 * @return bool Whether the test granted the capability on that site.
	 */
	function current_user_can_for_site( $site_id, $capability ) {
		$sites = isset( $GLOBALS['extrachill_ingest_site_capabilities'] ) ? $GLOBALS['extrachill_ingest_site_capabilities'] : array();
		return ! empty( $sites[ (int) $site_id ][ $capability ] );
	}
}
if ( ! function_exists( 'switch_to_blog' ) ) {
	/**
	 * Switch the current blog fixture ID.
	 *
	 * @param int $blog_id Target blog ID.
	 * @return bool Always true.
	 */
	function switch_to_blog( $blog_id ) {
		$GLOBALS['extrachill_analytics_test_blog_stack'][] = get_current_blog_id();
		$GLOBALS['extrachill_analytics_test_blog_id']      = (int) $blog_id;
		return true;
	}
}
if ( ! function_exists( 'restore_current_blog' ) ) {
	/**
	 * Restore the prior blog fixture ID.
	 *
	 * @return bool True when a prior blog was restored.
	 */
	function restore_current_blog() {
		if ( empty( $GLOBALS['extrachill_analytics_test_blog_stack'] ) ) {
			return false;
		}
		$GLOBALS['extrachill_analytics_test_blog_id'] = array_pop( $GLOBALS['extrachill_analytics_test_blog_stack'] );
		return true;
	}
}
if ( ! function_exists( 'home_url' ) ) {
	/**
	 * Return the configured home URL for the current blog fixture.
	 *
	 * @param string $path Optional path.
	 * @return string Home URL.
	 */
	function home_url( $path = '' ) {
		$blog_id = get_current_blog_id();
		$homes   = isset( $GLOBALS['extrachill_analytics_test_home_urls'] ) ? $GLOBALS['extrachill_analytics_test_home_urls'] : array();
		$home    = isset( $homes[ $blog_id ] ) ? $homes[ $blog_id ] : 'https://extrachill.com';
		return rtrim( $home, '/' ) . '/' . ltrim( $path, '/' );
	}
}
if ( ! function_exists( 'site_url' ) ) {
	/**
	 * Return the configured site URL fixture.
	 *
	 * @param string $path Optional path.
	 * @return string
	 */
	function site_url( $path = '' ) {
		return home_url( $path );
	}
}
if ( ! function_exists( 'ec_get_domain_map' ) ) {
	/**
	 * Return mapped-domain fixtures.
	 *
	 * @return array<string,int>
	 */
	function ec_get_domain_map() {
		return isset( $GLOBALS['extrachill_analytics_test_domain_map'] )
			? $GLOBALS['extrachill_analytics_test_domain_map']
			: array( 'extrachill.com' => 1 );
	}
}
if ( ! function_exists( 'ec_get_blog_slug_by_id' ) ) {
	/**
	 * Return a logical site slug fixture.
	 *
	 * @param int $blog_id Blog ID.
	 * @return string
	 */
	function ec_get_blog_slug_by_id( $blog_id ) {
		$slugs = isset( $GLOBALS['extrachill_analytics_test_blog_slugs'] ) ? $GLOBALS['extrachill_analytics_test_blog_slugs'] : array( 1 => 'main' );
		return isset( $slugs[ (int) $blog_id ] ) ? $slugs[ (int) $blog_id ] : '';
	}
}
if ( ! function_exists( 'ec_get_blog_id' ) ) {
	/**
	 * Resolve a logical site fixture.
	 *
	 * @param string $slug Logical site slug.
	 * @return int|null
	 */
	function ec_get_blog_id( $slug ) {
		$slugs   = isset( $GLOBALS['extrachill_analytics_test_blog_slugs'] ) ? $GLOBALS['extrachill_analytics_test_blog_slugs'] : array( 1 => 'main' );
		$blog_id = array_search( $slug, $slugs, true );
		return false === $blog_id ? null : (int) $blog_id;
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * Stub for wp_parse_url().
	 *
	 * @param string   $url URL to parse.
	 * @param int|null $component Optional PHP_URL_* component.
	 * @return array<string,mixed>|string|int|null|false Parse result.
	 */
	function wp_parse_url( $url, $component = -1 ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		return parse_url( (string) $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
	}
}
if ( ! function_exists( 'get_permalink' ) ) {
	/**
	 * Return a canonical permalink for a post fixture.
	 *
	 * @param WP_Post|int $post Post fixture or ID.
	 * @return string Canonical fixture URL.
	 */
	function get_permalink( $post ) {
		$post_id    = $post instanceof WP_Post ? (int) $post->ID : (int) $post;
		$permalinks = isset( $GLOBALS['extrachill_analytics_test_permalinks'] ) ? $GLOBALS['extrachill_analytics_test_permalinks'] : array();
		return isset( $permalinks[ $post_id ] ) ? (string) $permalinks[ $post_id ] : '';
	}
}
if ( ! function_exists( 'ec_track_post_views' ) ) {
	/**
	 * Capture legacy post-counter increments.
	 *
	 * @param int $post_id Post ID.
	 */
	function ec_track_post_views( $post_id ) {
		$GLOBALS['extrachill_analytics_test_tracked_post_views'][] = (int) $post_id;
	}
}
if ( ! function_exists( 'get_post_type' ) ) {
	/**
	 * Return a post-type fixture.
	 *
	 * @param int $post_id Post ID.
	 * @return string Post type.
	 */
	function get_post_type( $post_id ) {
		$types = isset( $GLOBALS['extrachill_analytics_test_post_types'] ) ? $GLOBALS['extrachill_analytics_test_post_types'] : array();
		return isset( $types[ (int) $post_id ] ) ? $types[ (int) $post_id ] : 'post';
	}
}
if ( ! function_exists( 'do_action' ) ) {
	/**
	 * Capture fired action hooks.
	 *
	 * @param string $hook Hook name.
	 * @param mixed  ...$args Hook arguments.
	 */
	function do_action( $hook, ...$args ) {
		$GLOBALS['extrachill_analytics_test_actions'][] = array( $hook, $args );
	}
}
if ( ! function_exists( 'extrachill_analytics_classify_user_agent' ) ) {
	/**
	 * Classify a browser user-agent fixture.
	 *
	 * @param string $user_agent User agent.
	 * @return string Browser or bot class.
	 */
	function extrachill_analytics_classify_user_agent( $user_agent ) {
		return false !== stripos( (string) $user_agent, 'bot' ) ? 'bot' : 'browser';
	}
}
if ( ! function_exists( 'extrachill_track_analytics_event' ) ) {
	/**
	 * Capture an analytics event write.
	 *
	 * @param string $event_type Event type.
	 * @param array  $event_data Event payload.
	 * @param string $source_url Source URL.
	 * @param string $visitor_id Visitor UUID.
	 * @return int Fixture event ID.
	 */
	function extrachill_track_analytics_event( $event_type, $event_data, $source_url, $visitor_id ) {
		$GLOBALS['extrachill_analytics_test_events'][] = array( $event_type, $event_data, $source_url, $visitor_id );
		return 1;
	}
}
if ( ! function_exists( 'url_to_postid' ) ) {
	/**
	 * Capture resolver URLs and resolve against both test fixture maps.
	 *
	 * @param string $url URL to resolve.
	 * @return int Post ID or 0.
	 */
	function url_to_postid( $url ) {
		$GLOBALS['extrachill_analytics_test_resolved_urls'][] = $url;
		$posts      = isset( $GLOBALS['extrachill_analytics_test_url_post_ids'] ) ? $GLOBALS['extrachill_analytics_test_url_post_ids'] : array();
		$map        = isset( $GLOBALS['extrachill_ingest_url_map'] ) ? $GLOBALS['extrachill_ingest_url_map'] : array();
		$url        = (string) $url;
		$path       = isset( wp_parse_url( $url )['path'] ) ? trim( (string) wp_parse_url( $url )['path'], '/' ) : '';
		$candidates = array( $url, $path, '/' . $path . '/' );
		foreach ( $candidates as $candidate ) {
			if ( isset( $posts[ $candidate ] ) ) {
				return (int) $posts[ $candidate ];
			}
			if ( isset( $map[ $candidate ] ) ) {
				return (int) $map[ $candidate ];
			}
		}
		return 0;
	}
}
if ( ! function_exists( 'ec_resolve_frontend_paths' ) ) {
	/**
	 * Test double for the Network batch resolver contract.
	 *
	 * @param array $paths Host-relative paths.
	 * @param array $args Resolver arguments.
	 * @return array Contract-shaped fixture response.
	 */
	function ec_resolve_frontend_paths( array $paths, array $args = array() ) {
		unset( $args );
		$GLOBALS['extrachill_network_resolver_calls'][] = $paths;
		if ( ! empty( $GLOBALS['extrachill_network_resolver_incomplete'] ) ) {
			return array(
				'scan'    => array( 'status' => 'incomplete' ),
				'results' => array(),
			);
		}
		$fixtures = isset( $GLOBALS['extrachill_network_resolver_results'] ) ? $GLOBALS['extrachill_network_resolver_results'] : array();
		$results  = array();
		foreach ( $paths as $path ) {
			$results[] = isset( $fixtures[ $path ] ) ? $fixtures[ $path ] : array(
				'path'   => $path,
				'status' => 'unresolved',
			);
		}
		return array(
			'scan'    => array( 'status' => 'complete' ),
			'results' => $results,
		);
	}
}
if ( ! function_exists( 'get_post' ) ) {
	/**
	 * Stub get_post returning an object with post_name from a $GLOBALS map.
	 *
	 * Reads the `extrachill_ingest_post_map` map (post_id => post_name).
	 *
	 * @param int $id Post ID.
	 * @return stdClass|null
	 */
	function get_post( $id ) {
		if ( $id instanceof WP_Post ) {
			return $id;
		}

		$classifier_posts = isset( $GLOBALS['extrachill_analytics_classifier_posts'] ) ? $GLOBALS['extrachill_analytics_classifier_posts'] : array();
		$id               = (int) $id;
		if ( isset( $classifier_posts[ $id ] ) ) {
			return $classifier_posts[ $id ];
		}

		$map = isset( $GLOBALS['extrachill_ingest_post_map'] ) ? $GLOBALS['extrachill_ingest_post_map'] : array();
		if ( isset( $map[ $id ] ) ) {
			$post            = new stdClass();
			$post->ID        = $id;
			$post->post_name = $map[ $id ];
			return $post;
		}
		return null;
	}
}
if ( ! function_exists( 'get_permalink' ) ) {
	/**
	 * Return a classifier-test permalink.
	 *
	 * @param WP_Post|int $post Post fixture or ID.
	 * @return string Permalink.
	 */
	function get_permalink( $post ) {
		$id         = $post instanceof WP_Post ? $post->ID : (int) $post;
		$permalinks = isset( $GLOBALS['extrachill_analytics_classifier_permalinks'] ) ? $GLOBALS['extrachill_analytics_classifier_permalinks'] : array();
		return isset( $permalinks[ $id ] ) ? $permalinks[ $id ] : 'https://extrachill.com/post-' . $id . '/';
	}
}
if ( ! function_exists( 'get_the_terms' ) ) {
	/**
	 * Return classifier-test category fixtures.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $taxonomy Taxonomy name.
	 * @return array<int,object>|false Category terms or false.
	 */
	function get_the_terms( $post_id, $taxonomy ) {
		$terms = isset( $GLOBALS['extrachill_analytics_classifier_terms'][ (int) $post_id ] ) ? $GLOBALS['extrachill_analytics_classifier_terms'][ (int) $post_id ] : array();
		if ( 'category' !== $taxonomy || empty( $terms ) ) {
			return false;
		}

		return array_map(
			static function ( $slug ) {
				return (object) array( 'slug' => $slug );
			},
			$terms
		);
	}
}

require_once dirname( __DIR__ ) . '/inc/core/php-error-log.php';
require_once dirname( __DIR__ ) . '/inc/core/abilities/get-php-error-summary.php';
require_once dirname( __DIR__ ) . '/inc/core/security-classifier.php';
require_once dirname( __DIR__ ) . '/inc/core/retention-cohorts.php';
