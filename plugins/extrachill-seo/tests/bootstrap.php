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

if ( ! function_exists( 'is_singular' ) ) {
	function is_singular( $post_types = '' ) {
		return false;
	}
}

if ( ! function_exists( 'get_queried_object' ) ) {
	function get_queried_object() {
		return null;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		// No-op for unit tests; we exercise the helper functions directly.
		return true;
	}
}

// ---------------------------------------------------------------------------
// WP_Post stub.
// ---------------------------------------------------------------------------

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public int $ID = 0;
		public string $post_title = '';
		public string $post_content = '';
		public string $post_excerpt = '';
		public string $post_name = '';
		public string $post_type = 'data_machine_events';
		public ?string $permalink = null;
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
		public int $term_id = 0;
		public string $name = '';
		public string $slug = '';
		public string $taxonomy = '';

		public function __construct( array $props = array() ) {
			foreach ( $props as $key => $value ) {
				$this->$key = $value;
			}
		}
	}
}

// ---------------------------------------------------------------------------
// Test support helpers + data-machine-events public API stub.
// ---------------------------------------------------------------------------

require_once __DIR__ . '/Support/MiniBlockParser.php';
require_once __DIR__ . '/Support/TermRegistry.php';
require_once __DIR__ . '/Support/VenueDataRegistry.php';

if ( ! function_exists( 'data_machine_events_get_venue_data' ) ) {
	function data_machine_events_get_venue_data( int $term_id ): ?array {
		return \ExtraChill\SEO\Tests\Support\VenueDataRegistry::get( $term_id );
	}
}

// ---------------------------------------------------------------------------
// Load the schema module under test.
// ---------------------------------------------------------------------------

require_once __DIR__ . '/../inc/schema/schema-event.php';
