<?php
/**
 * PHPUnit bootstrap for extrachill-seo unit tests.
 *
 * Provides lightweight stubs for the WordPress functions used by the
 * schema modules. Tests run without a full WordPress test harness so
 * pure helpers can be exercised quickly. Integration coverage of the
 * `wp_head` rendering pipeline lives in higher-level smoke tests.
 *
 * @package ExtraChill\SEO\Tests
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// ---------------------------------------------------------------------------
// WordPress function stubs.
//
// Only stub what the code under test actually calls. Each stub mirrors the
// minimal contract used by inc/schema/schema-event.php.
// ---------------------------------------------------------------------------

if ( ! function_exists( 'parse_blocks' ) ) {
	/**
	 * Minimal port of WordPress `parse_blocks()` good enough for these tests.
	 * Uses the real WP_Block_Parser when available via Composer autoload;
	 * otherwise falls back to a recursive comment scanner.
	 */
	function parse_blocks( $content ) {
		// Real WordPress block parser would parse comment delimiters; for
		// tests we hand-roll a tiny parser sufficient for the fixtures we use.
		return \ExtraChill\SEO\Tests\Support\MiniBlockParser::parse( (string) $content );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $string, $remove_breaks = false ) {
		$string = (string) $string;
		$string = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $string ) ?? $string;
		$string = strip_tags( $string );
		if ( $remove_breaks ) {
			$string = preg_replace( '/[\r\n\t ]+/', ' ', $string ) ?? $string;
		}
		return trim( $string );
	}
}

if ( ! function_exists( 'wp_trim_words' ) ) {
	function wp_trim_words( $text, $num_words = 55, $more = '...' ) {
		$text  = trim( (string) $text );
		$words = preg_split( '/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY ) ?: array();
		if ( count( $words ) <= $num_words ) {
			return $text;
		}
		return implode( ' ', array_slice( $words, 0, $num_words ) ) . $more;
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		return (string) $url;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( 'wp_timezone' ) ) {
	function wp_timezone() {
		return new DateTimeZone( 'UTC' );
	}
}

if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $post = 0 ) {
		if ( 0 === $post && ! empty( $GLOBALS['ec_seo_test_queried_object'] ) ) {
			$post = $GLOBALS['ec_seo_test_queried_object'];
		}

		if ( $post instanceof WP_Post ) {
			return $post->permalink ?? 'https://events.example.com/events/' . $post->post_name;
		}
		return 'https://events.example.com/?p=' . (int) $post;
	}
}

if ( ! function_exists( 'get_the_post_thumbnail_url' ) ) {
	function get_the_post_thumbnail_url( $post = null, $size = 'post-thumbnail' ) {
		if ( $post instanceof WP_Post && ! empty( $post->thumbnail_url ) ) {
			return $post->thumbnail_url;
		}
		return false;
	}
}

if ( ! function_exists( 'get_the_terms' ) ) {
	function get_the_terms( $post, $taxonomy ) {
		$id = $post instanceof WP_Post ? $post->ID : (int) $post;
		return \ExtraChill\SEO\Tests\Support\TermRegistry::get( $id, $taxonomy );
	}
}

if ( ! function_exists( 'get_term_meta' ) ) {
	function get_term_meta( $term_id, $key, $single = false ) {
		return \ExtraChill\SEO\Tests\Support\TermMetaRegistry::get( (int) $term_id, (string) $key );
	}
}

if ( ! function_exists( 'is_singular' ) ) {
	function is_singular( $post_types = '' ) {
		if ( empty( $GLOBALS['ec_seo_test_is_singular'] ) ) {
			return false;
		}

		if ( '' === $post_types || array() === $post_types ) {
			return true;
		}

		$queried = $GLOBALS['ec_seo_test_queried_object'] ?? null;
		if ( ! $queried instanceof WP_Post ) {
			// No queried object staged: preserve the legacy flag-only behavior.
			return true;
		}

		return in_array( $queried->post_type, (array) $post_types, true );
	}
}

if ( ! function_exists( 'get_queried_object' ) ) {
	function get_queried_object() {
		return $GLOBALS['ec_seo_test_queried_object'] ?? null;
	}
}

if ( ! function_exists( 'is_404' ) ) {
	function is_404() {
		return ! empty( $GLOBALS['ec_seo_test_is_404'] );
	}
}

if ( ! function_exists( 'is_page' ) ) {
	function is_page() {
		return false;
	}
}

if ( ! function_exists( 'is_tax' ) ) {
	function is_tax() {
		return false;
	}
}

if ( ! function_exists( 'is_front_page' ) ) {
	function is_front_page() {
		return false;
	}
}

if ( ! function_exists( 'is_home' ) ) {
	function is_home() {
		return false;
	}
}

if ( ! function_exists( 'is_archive' ) ) {
	function is_archive() {
		return false;
	}
}

if ( ! function_exists( 'is_category' ) ) {
	function is_category() {
		return false;
	}
}

if ( ! function_exists( 'is_tag' ) ) {
	function is_tag() {
		return false;
	}
}

if ( ! function_exists( 'is_author' ) ) {
	function is_author() {
		return false;
	}
}

if ( ! function_exists( 'is_search' ) ) {
	function is_search() {
		return false;
	}
}

// ---------------------------------------------------------------------------
// Filter registry.
//
// Minimal but real filter plumbing: schema modules register emitters on
// `extrachill_seo_schema_graph` at require time, and integration tests add
// site-level overrides (breadcrumb items, document title separator) the same
// way production plugins do. With no callbacks registered, apply_filters()
// returns the value untouched, matching the previous stub's contract.
// ---------------------------------------------------------------------------

if ( ! isset( $GLOBALS['ec_seo_test_filters'] ) ) {
	$GLOBALS['ec_seo_test_filters'] = array();
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['ec_seo_test_filters'][ $hook_name ][ $priority ][] = array(
			'callback'      => $callback,
			'accepted_args' => $accepted_args,
		);
		return true;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook_name, $value, ...$args ) {
		if ( empty( $GLOBALS['ec_seo_test_filters'][ $hook_name ] ) ) {
			return $value;
		}

		$grouped = $GLOBALS['ec_seo_test_filters'][ $hook_name ];
		ksort( $grouped );

		foreach ( $grouped as $callbacks ) {
			foreach ( $callbacks as $entry ) {
				$value = call_user_func_array(
					$entry['callback'],
					array_merge( array( $value ), array_slice( $args, 0, $entry['accepted_args'] - 1 ) )
				);
			}
		}

		return $value;
	}
}

if ( ! function_exists( 'has_filter' ) ) {
	function has_filter( $hook_name, $callback = false ) {
		return ! empty( $GLOBALS['ec_seo_test_filters'][ $hook_name ] );
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		return true;
	}
}

// ---------------------------------------------------------------------------
// URL + query-context stubs.
// ---------------------------------------------------------------------------

if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( $value ) {
		return rtrim( (string) $value, '/' );
	}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) {
		$base = 'https://events.extrachill.com';
		if ( '' === $path ) {
			return $base;
		}
		return $base . '/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( $args, $url ) {
		// Test request paths carry no query args; return the URL unchanged.
		return (string) $url;
	}
}

if ( ! class_exists( 'WP_Seo_Test_Router' ) ) {
	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- stub for ec_seo_get_current_url().
	$GLOBALS['wp'] = (object) array(
		'request' => 'events/jane-rundquist-too-blue-5',
	);
}

// ---------------------------------------------------------------------------
// WP_Post stub.
// ---------------------------------------------------------------------------

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public int $ID                = 0;
		public string $post_title     = '';
		public string $post_content   = '';
		public string $post_excerpt   = '';
		public string $post_name      = '';
		public string $post_type      = 'data_machine_events';
		public ?string $permalink     = null;
		public ?string $thumbnail_url = null;

		public function __construct( array $props = array() ) {
			foreach ( $props as $key => $value ) {
				$this->$key = $value;
			}
		}
	}
}

if ( ! class_exists( 'WP_Term' ) ) {
	class WP_Term {
		public int $term_id     = 0;
		public string $name     = '';
		public string $slug     = '';
		public string $taxonomy = '';

		public function __construct( array $props = array() ) {
			foreach ( $props as $key => $value ) {
				$this->$key = $value;
			}
		}
	}
}

// ---------------------------------------------------------------------------
// Document title + meta description pipeline stubs.
//
// These back the schema-webpage / schema-breadcrumb emitters and
// inc/core/meta-tags.php so the full serialization pipeline can run in
// tests. Behavior mirrors the production data flow on the events site
// (blog 7): term-based title enrichment, entity-bearing separators.
// ---------------------------------------------------------------------------

if ( ! function_exists( 'single_post_title' ) ) {
	function single_post_title( $prefix = '', $display = true ) {
		$queried = $GLOBALS['ec_seo_test_queried_object'] ?? null;
		$title   = $queried instanceof WP_Post ? $queried->post_title : '';
		return $display ? $prefix . $title : $title;
	}
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( $show = '' ) {
		if ( 'description' === $show ) {
			return 'Online Music Scene';
		}
		return 'Extra Chill Events';
	}
}

if ( ! function_exists( 'wp_get_document_title' ) ) {
	/**
	 * Minimal port of core wp_get_document_title(): pre-get short-circuit,
	 * singular title part, site/tagline part, separator + parts filters.
	 */
	function wp_get_document_title() {
		$title = apply_filters( 'pre_get_document_title', '' );
		if ( '' !== $title ) {
			return $title;
		}

		$title = array( 'title' => '' );

		if ( is_singular() ) {
			$title['title'] = single_post_title( '', false );
		}

		if ( is_front_page() ) {
			$title['tagline'] = get_bloginfo( 'description' );
		} else {
			$title['site'] = get_bloginfo( 'name' );
		}

		$sep   = apply_filters( 'document_title_separator', '-' );
		$title = apply_filters( 'document_title_parts', $title );
		$title = implode( " $sep ", array_filter( $title ) );

		return apply_filters( 'document_title', $title );
	}
}

if ( ! function_exists( 'get_current_blog_id' ) ) {
	function get_current_blog_id() {
		return 7; // events.extrachill.com
	}
}

if ( ! function_exists( 'ec_get_blog_slug_by_id' ) ) {
	function ec_get_blog_slug_by_id( $blog_id ) {
		return 7 === (int) $blog_id ? 'events' : '';
	}
}

if ( ! function_exists( 'ec_get_site_labels' ) ) {
	function ec_get_site_labels() {
		return array( 'events' => 'Events' );
	}
}

if ( ! function_exists( 'is_paged' ) ) {
	function is_paged() {
		return false;
	}
}

if ( ! function_exists( 'get_query_var' ) ) {
	function get_query_var( $query_var ) {
		unset( $query_var );
		return 0;
	}
}

if ( ! function_exists( 'is_post_type_archive' ) ) {
	function is_post_type_archive( $post_types = '' ) {
		unset( $post_types );
		return false;
	}
}

if ( ! function_exists( 'single_cat_title' ) ) {
	function single_cat_title( $prefix = '', $display = true ) {
		unset( $prefix, $display );
		return '';
	}
}

if ( ! function_exists( 'single_tag_title' ) ) {
	function single_tag_title( $prefix = '', $display = true ) {
		unset( $prefix, $display );
		return '';
	}
}

if ( ! function_exists( 'get_the_author' ) ) {
	function get_the_author() {
		return '';
	}
}

if ( ! function_exists( 'get_the_author_meta' ) ) {
	function get_the_author_meta( $field, $user_id = 0 ) {
		unset( $field, $user_id );
		return '';
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key = '', $single = false ) {
		unset( $post_id, $key, $single );
		return '';
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		unset( $thing );
		return false;
	}
}

if ( ! function_exists( 'wp_get_post_terms' ) ) {
	function wp_get_post_terms( $post_id, $taxonomy, $args = array() ) {
		$terms = \ExtraChill\SEO\Tests\Support\TermRegistry::get( (int) $post_id, $taxonomy );
		if ( false === $terms || ! is_array( $terms ) ) {
			return array();
		}
		if ( isset( $args['fields'] ) && 'names' === $args['fields'] ) {
			return array_map(
				static function ( $term ) {
					return $term->name;
				},
				$terms
			);
		}
		return $terms;
	}
}

if ( ! function_exists( 'get_queried_object_id' ) ) {
	function get_queried_object_id() {
		$queried = $GLOBALS['ec_seo_test_queried_object'] ?? null;
		return $queried instanceof WP_Post ? $queried->ID : 0;
	}
}

if ( ! function_exists( 'datamachine_get_event_dates' ) ) {
	function datamachine_get_event_dates( $event_id ) {
		unset( $event_id );
		return (object) array( 'start_datetime' => '2026-09-19 20:00:00' );
	}
}

if ( ! function_exists( 'wp_date' ) ) {
	function wp_date( $format, $timestamp = null ) {
		return gmdate( $format, $timestamp ?? time() );
	}
}

// ---------------------------------------------------------------------------
// Post/term accessors used by the webpage + breadcrumb emitters.
// ---------------------------------------------------------------------------

if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( $post = 0 ) {
		$queried = $GLOBALS['ec_seo_test_queried_object'] ?? null;
		if ( $post instanceof WP_Post ) {
			return $post->post_title;
		}
		if ( ! $queried instanceof WP_Post ) {
			return '';
		}
		if ( 0 === $post || ( is_int( $post ) && $post === $queried->ID ) ) {
			return $queried->post_title;
		}
		return '';
	}
}

if ( ! function_exists( 'get_term_link' ) ) {
	function get_term_link( $term ) {
		return home_url( '/' . ( $term->taxonomy ?? 'term' ) . '/' . ( $term->slug ?? '' ) );
	}
}

if ( ! function_exists( 'has_post_thumbnail' ) ) {
	function has_post_thumbnail( $post = null ) {
		unset( $post );
		return false;
	}
}

if ( ! function_exists( 'get_the_date' ) ) {
	function get_the_date( $format = '', $post = null ) {
		unset( $format, $post );
		return '2026-09-19T12:00:00+00:00';
	}
}

if ( ! function_exists( 'get_the_modified_date' ) ) {
	function get_the_modified_date( $format = '', $post = null ) {
		unset( $format, $post );
		return '2026-09-19T12:00:00+00:00';
	}
}

// ---------------------------------------------------------------------------
// Test support helpers + data-machine-events public API stub.
// ---------------------------------------------------------------------------

require_once __DIR__ . '/Support/MiniBlockParser.php';
require_once __DIR__ . '/Support/TermRegistry.php';
require_once __DIR__ . '/Support/TermMetaRegistry.php';
require_once __DIR__ . '/Support/VenueDataRegistry.php';
require_once __DIR__ . '/Support/TicketUrlRegistry.php';

if ( ! function_exists( 'data_machine_events_get_venue_data' ) ) {
	function data_machine_events_get_venue_data( int $term_id ): ?array {
		return \ExtraChill\SEO\Tests\Support\VenueDataRegistry::get( $term_id );
	}
}

// Global-scope test doubles for the two data-machine-events helpers this
// plugin consumes. These mirror the GLOBAL exports that data-machine-events'
// inc/public-api.php provides (data-machine-events#820); the contract this
// suite tests is: ec_seo_resolve_offer_url() must find both helpers at these
// exact global names. If the names consumed in production ever drift from
// this contract, SchemaEventOfferUrlGlobalResolutionTest fails.
if ( ! function_exists( 'data_machine_events_is_affiliate_ticket_url' ) ) {
	function data_machine_events_is_affiliate_ticket_url( string $url ): bool {
		return \ExtraChill\SEO\Tests\Support\TicketUrlRegistry::is_affiliate( $url );
	}
}

if ( ! function_exists( 'datamachine_unwrap_affiliate_url' ) ) {
	function datamachine_unwrap_affiliate_url( string $url ): string {
		return \ExtraChill\SEO\Tests\Support\TicketUrlRegistry::unwrap( $url );
	}
}

// ---------------------------------------------------------------------------
// Load the schema module under test.
// ---------------------------------------------------------------------------

require_once __DIR__ . '/../inc/schema/schema-helpers.php';
require_once __DIR__ . '/../inc/schema/schema-event.php';
require_once __DIR__ . '/../inc/schema/schema-breadcrumb.php';
require_once __DIR__ . '/../inc/core/meta-tags.php';
require_once __DIR__ . '/../inc/core/canonical.php';
require_once __DIR__ . '/../inc/core/open-graph.php';
require_once __DIR__ . '/../inc/schema/schema-output.php';
require_once __DIR__ . '/../inc/schema/schema-webpage.php';
