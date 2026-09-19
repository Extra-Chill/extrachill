<?php
/**
 * Shared WordPress function stubs for the Core unit tests that run without
 * the managed WordPress runtime.
 *
 * Extracted from the inline prelude of AccountMarketTest (with the union of
 * ArchiveEventsFirstTest's stubs at extraction time) so the test file keeps
 * a single OO declaration. Every stub is guarded: when the managed suite
 * loads real WordPress, these definitions are skipped entirely. Suites with
 * their own expectations own their doubles locally — see
 * Stubs/archive-layout-stubs.php and the near-me-unit precedent.
 *
 * @package ExtraChillEvents\Tests\Unit\Core
 */

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter() {
		return true;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action() {
		return true;
	}
}

if ( ! function_exists( 'add_rewrite_tag' ) ) {
	function add_rewrite_tag( $tag, $regex ) {
		$GLOBALS['test_rewrite_tags'][] = array(
			'tag'   => $tag,
			'regex' => $regex,
		);
	}
}

if ( ! function_exists( 'add_rewrite_rule' ) ) {
	function add_rewrite_rule( $regex, $query, $after ) {
		$GLOBALS['test_rewrite_rules'][] = array(
			'regex' => $regex,
			'query' => $query,
			'after' => $after,
		);
	}
}

if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in() {
		return (bool) ( $GLOBALS['test_is_user_logged_in'] ?? false );
	}
}

if ( ! function_exists( 'wp_get_ability' ) ) {
	function wp_get_ability( $name ) {
		if ( 'extrachill/get-user-settings' === $name ) {
			return $GLOBALS['test_account_market_ability'] ?? null;
		}
		return 'extrachill/update-user-settings' === $name ? ( $GLOBALS['test_update_scene_ability'] ?? null ) : null;
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error() {
		return false;
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $value ) {
		return strtolower( preg_replace( '/[^a-z0-9]+/i', '-', trim( (string) $value ) ) );
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ) {
		return trim( (string) $value );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $value ) {
		return filter_var( $value, FILTER_SANITIZE_URL );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return $value;
	}
}

if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( $scheme = 'auth' ) {
		unset( $scheme );
		return 'account-market-test-secret';
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $value ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
	}
}

if ( ! function_exists( 'is_tax' ) ) {
	function is_tax( $taxonomies = array() ) {
		unset( $taxonomies );
		return (bool) ( $GLOBALS['test_is_tax'] ?? false );
	}
}

if ( ! function_exists( 'get_current_blog_id' ) ) {
	function get_current_blog_id() {
		if ( isset( $GLOBALS['venue_membership_test']['current_blog_id'] ) ) {
			return (int) $GLOBALS['venue_membership_test']['current_blog_id'];
		}
		if ( isset( $GLOBALS['ec_artist_test']['blog_id'] ) ) {
			return (int) $GLOBALS['ec_artist_test']['blog_id'];
		}
		return (int) ( $GLOBALS['ec_locations_blog_id'] ?? 7 );
	}
}

if ( ! function_exists( 'is_front_page' ) ) {
	function is_front_page() {
		return (bool) ( $GLOBALS['test_is_front_page'] ?? false );
	}
}

if ( ! function_exists( 'is_page' ) ) {
	function is_page( $slug = '' ) {
		return ( $GLOBALS['test_page_slug'] ?? '' ) === $slug;
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return (int) ( $GLOBALS['test_current_user_id'] ?? 0 );
	}
}

if ( ! function_exists( 'is_admin' ) ) {
	function is_admin() {
		return false;
	}
}

if ( ! function_exists( 'status_header' ) ) {
	function status_header( $code ) {
		$GLOBALS['test_status_header'] = $code;
	}
}

if ( ! function_exists( 'ec_is_events_site' ) ) {
	function ec_is_events_site() {
		return true;
	}
}

if ( ! function_exists( 'extrachill_events_is_near_me_page' ) ) {
	function extrachill_events_is_near_me_page() {
		return (bool) ( $GLOBALS['test_is_near_me_page'] ?? false );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $name, $value ) {
		return $value;
	}
}

if ( ! function_exists( 'get_query_var' ) ) {
	function get_query_var( $name, $default = '' ) {
		if ( array_key_exists( $name, $GLOBALS['test_query_vars'] ?? array() ) ) {
			return $GLOBALS['test_query_vars'][ $name ];
		}
		if ( 'ec_events_router' === $name && ! empty( $GLOBALS['test_is_all_events_page'] ) ) {
			return 'all';
		}
		return $default;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $value ) {
		return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $value ) {
		return esc_html( $value );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $value ) {
		return $value;
	}
}

if ( ! function_exists( '_n' ) ) {
	function _n( $single, $plural, $number ) {
		return 1 === (int) $number ? $single : $plural;
	}
}

if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( $number ) {
		return number_format( (int) $number );
	}
}

if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( $value ) {
		echo esc_html( $value );
	}
}

if ( ! function_exists( 'esc_attr_e' ) ) {
	function esc_attr_e( $value ) {
		echo esc_html( $value );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $value ) {
		return esc_html( $value );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $value ) {
		return esc_html( $value );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $value ) {
		return rtrim( $value, '/' ) . '/';
	}
}

if ( ! function_exists( 'ec_get_site_url' ) ) {
	function ec_get_site_url() {
		return 'https://community.example';
	}
}

if ( ! function_exists( 'remove_query_arg' ) ) {
	function remove_query_arg() {
		return 'https://events.example/all/';
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( $key, $value = null, $url = '' ) {
		if ( is_array( $key ) ) {
			$url = (string) $value;
			return $url . '?' . http_build_query( $key );
		}
		return $url . '?' . rawurlencode( $key ) . '=' . rawurlencode( $value );
	}
}

if ( ! function_exists( 'wp_login_url' ) ) {
	function wp_login_url( $redirect ) {
		return 'https://events.example/login/?redirect_to=' . rawurlencode( $redirect );
	}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '/' ) {
		return 'https://events.example' . $path;
	}
}

if ( ! function_exists( 'get_queried_object' ) ) {
	function get_queried_object() {
		return $GLOBALS['test_queried_term'] ?? null;
	}
}

if ( ! function_exists( 'get_ancestors' ) ) {
	function get_ancestors() {
		return $GLOBALS['test_term_ancestors'] ?? array();
	}
}

if ( ! function_exists( 'get_term_link' ) ) {
	function get_term_link( $term ) {
		return $GLOBALS['test_term_link'] ?? 'https://events.example/location/' . $term->slug . '/';
	}
}

if ( ! function_exists( 'wp_nonce_field' ) ) {
	function wp_nonce_field( $action, $name ) {
		echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="nonce-' . esc_attr( $action ) . '">';
	}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( $action ) {
		return 'nonce-' . $action;
	}
}

if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( $path = '' ) {
		return 'https://events.example/wp-json/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'nocache_headers' ) ) {
	function nocache_headers() {
		$GLOBALS['test_nocache_headers'] = true;
	}
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	function wp_verify_nonce( $nonce, $action ) {
		return 'nonce-' . $action === $nonce;
	}
}

if ( ! function_exists( 'do_blocks' ) ) {
	function do_blocks( $content ) {
		return $content;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) {
		return (string) json_encode( $data );
	}
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $in_footer = false ) {
		$GLOBALS['test_enqueued_scripts'][ $handle ] = compact( 'src', 'deps', 'ver', 'in_footer' );
	}
}
