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
if ( ! function_exists( 'extrachill_get_analytics_events' ) ) {
	/**
	 * Return a paginated outbound-report fixture when configured by a test.
	 *
	 * @param array $args Event query arguments.
	 * @return array<object> Fixture event rows.
	 */
	function extrachill_get_analytics_events( $args = array() ) {
		$offset = isset( $args['offset'] ) ? (int) $args['offset'] : 0;
		$limit  = isset( $args['limit'] ) ? (int) $args['limit'] : 100;
		$rows   = isset( $GLOBALS['extrachill_analytics_outbound_fixture_rows'] )
			? $GLOBALS['extrachill_analytics_outbound_fixture_rows']
			: array();

		return array_slice( $rows, $offset, $limit );
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
	function update_site_option( $option, $val ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return true;
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
if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * Stub for wp_parse_url().
	 *
	 * @param string $url The URL to parse.
	 * @return array<string,mixed> Parse components.
	 */
	function wp_parse_url( $url ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		return parse_url( (string) $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
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
