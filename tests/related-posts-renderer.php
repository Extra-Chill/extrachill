<?php
/**
 * Regression coverage for the shared related-posts renderer.
 *
 * The renderer is the theme-owned, network-wide affordance consumed by
 * plugins (e.g. extrachill-events) through
 * extrachill_render_related_tax_section(). This fixture pins the public
 * contract: dedup registry semantics, both card layouts, empty-item
 * handling, and the override/custom-display hook contract.
 *
 * @package ExtraChill
 */

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed, Generic.Files.OneObjectStructurePerFile, Generic.CodeAnalysis.UnusedFunctionParameter, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Test doubles and their consuming test intentionally share this fixture; unused params mirror the core signatures and var_export/fwrite are failure reporting.

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Filter double. The override hook is driven by the fixture global.
	 *
	 * @param string $tag   Filter name.
	 * @param mixed  $value Value.
	 * @return mixed
	 */
	function apply_filters( $tag, $value ) {
		if ( 'extrachill_related_posts_allowed_taxonomies' === $tag ) {
			return array_merge( (array) $value, array( 'venue' ) );
		}
		if ( 'extrachill_override_related_posts_display' === $tag && ! empty( $GLOBALS['rp_fixture_override'] ) ) {
			return true;
		}
		return $value;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	/**
	 * Action double that records invocations.
	 *
	 * @param string $tag  Action name.
	 * @param mixed  ...$args Arguments.
	 */
	function do_action( $tag, ...$args ) {
		$GLOBALS['rp_fixture_actions'][] = array( $tag, $args );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		return (string) $url;
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'get_post_type' ) ) {
	function get_post_type( $post = null ) {
		return 'post';
	}
}
if ( ! function_exists( 'get_the_terms' ) ) {
	function get_the_terms( $post_id, $taxonomy ) {
		return $GLOBALS['rp_fixture_terms'][ $taxonomy ] ?? false;
	}
}
if ( ! function_exists( 'get_term_link' ) ) {
	function get_term_link( $term ) {
		return 'https://extrachill.test/artist/a/';
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return false;
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		return $GLOBALS['rp_fixture_transients'][ $key ] ?? false;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $ttl = 0 ) {
		$GLOBALS['rp_fixture_transients'][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $post = null ) {
		$id = is_object( $post ) ? $post->ID : (int) $post;
		return 'https://extrachill.test/p/' . $id . '/';
	}
}
if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( $post = 0 ) {
		$id = is_object( $post ) ? $post->ID : (int) $post;
		return 'Post ' . $id;
	}
}
if ( ! function_exists( 'get_the_date' ) ) {
	function get_the_date( $format = '', $post = null ) {
		return 'Sep 1, 2026';
	}
}
if ( ! function_exists( 'has_post_thumbnail' ) ) {
	function has_post_thumbnail( $post = null ) {
		return false;
	}
}
if ( ! function_exists( 'get_the_post_thumbnail' ) ) {
	function get_the_post_thumbnail( $post = null, $size = 'post-thumbnail', $attr = array() ) {
		return '';
	}
}

if ( ! class_exists( 'WP_Query' ) ) {
	class WP_Query {
		public $posts;
		public $post_count;
		public function __construct( $args = array() ) {
			$this->posts      = $GLOBALS['rp_fixture_query_posts'];
			$this->post_count = count( $this->posts );
		}
	}
}

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public $ID;
		public function __construct( $id ) {
			$this->ID = $id;
		}
	}
}

$GLOBALS['rp_fixture_actions']     = array();
$GLOBALS['rp_fixture_override']    = false;
$GLOBALS['rp_fixture_transients']  = array();
$GLOBALS['rp_fixture_terms']       = array(
	'venue' => array(
		(object) array(
			'term_id' => 77,
			'name'    => 'The Hall',
		),
	),
);
$GLOBALS['rp_fixture_query_posts'] = array(
	new WP_Post( 10 ),
	new WP_Post( 11 ),
);

require_once dirname( __DIR__ ) . '/inc/single/related-posts.php';

function rp_fail( $message ) {
	fwrite( STDERR, $message . "\n" );
	exit( 1 );
}

function rp_assert_same( $expected, $actual, $label ) {
	if ( $expected !== $actual ) {
		rp_fail(
			sprintf(
				"%s failed.\nExpected: %s\nActual:   %s",
				$label,
				var_export( $expected, true ),
				var_export( $actual, true )
			)
		);
	}
}

// 1. Dedup registry: first pass returns everything, later passes drop repeats.
rp_assert_same( array( 1, 2, 3 ), extrachill_related_posts_filter_displayed( array( 1, 2, 3 ) ), 'Dedup first pass' );
rp_assert_same( array( 4 ), extrachill_related_posts_filter_displayed( array( 3, 4 ) ), 'Dedup drops already-displayed IDs' );

// 2. Section renderer: no items, no markup.
rp_assert_same( '', extrachill_render_related_tax_section( array( 'items' => array() ) ), 'Empty items render nothing' );

// 3. Deduped-to-nothing sections render nothing.
$GLOBALS['rp_fixture_actions'] = array();
$first                         = extrachill_render_related_tax_section(
	array(
		'heading_prefix' => 'More from ',
		'term_link'      => 'https://extrachill.test/artist/a/',
		'term_name'      => 'A',
		'items'          => array(
			array(
				'id'        => 10,
				'permalink' => 'https://extrachill.test/p/10/',
				'title'     => 'Ten',
				'meta_html' => 'Sep 1, 2026',
			),
		),
	)
);
if ( false === strpos( $first, 'related-tax-section' ) ) {
	rp_fail( 'Section renderer did not emit the section for fresh items.' );
}
$second = extrachill_render_related_tax_section(
	array(
		'heading_prefix' => 'More from ',
		'term_link'      => 'https://extrachill.test/artist/a/',
		'term_name'      => 'A',
		'items'          => array(
			array(
				'id'        => 10,
				'permalink' => 'https://extrachill.test/p/10/',
				'title'     => 'Ten',
				'meta_html' => 'Sep 1, 2026',
			),
			array(
				'id'        => 11,
				'permalink' => 'https://extrachill.test/p/11/',
				'title'     => 'Eleven',
				'meta_html' => 'Sep 2, 2026',
			),
		),
	)
);
if ( false !== strpos( $second, '/p/10/' ) ) {
	rp_fail( 'Dedup guard did not suppress the already-displayed post across sections.' );
}
if ( false === strpos( $second, '/p/11/' ) ) {
	rp_fail( 'Dedup guard removed a fresh post.' );
}

// 4. Link layout: whole card is one anchor; title/meta are spans.
$link_card = extrachill_render_related_tax_card(
	array(
		'id'         => 20,
		'permalink'  => 'https://extrachill.test/p/20/',
		'title'      => 'Twenty',
		'thumb_html' => '<img src="thumb.jpg" alt="" />',
		'meta_html'  => 'Sep 3, 2026',
	)
);
if ( 1 !== preg_match( '/^\t*<a href="https:\/\/extrachill\.test\/p\/20\/" class="related-tax-card">/', $link_card ) ) {
	rp_fail( 'Link layout card must be wrapped in a single related-tax-card anchor.' );
}
foreach ( array( '<span class="related-tax-thumb">', '<span class="related-tax-title">', '<span class="related-tax-meta">' ) as $span ) {
	if ( false === strpos( $link_card, $span ) ) {
		rp_fail( 'Link layout card is missing ' . $span . '.' );
	}
}
$thumbless = extrachill_render_related_tax_card(
	array(
		'permalink' => 'https://extrachill.test/p/21/',
		'title'     => 'Twenty One',
		'meta_html' => 'Sep 4, 2026',
	)
);
if ( false !== strpos( $thumbless, 'related-tax-thumb' ) ) {
	rp_fail( 'Empty thumb_html must omit the thumbnail element.' );
}

// 5. Block layout: block container, linked h4 title, badges between thumb and title.
$block_card = extrachill_render_related_tax_card(
	array(
		'id'          => 30,
		'layout'      => 'block',
		'permalink'   => 'https://extrachill.test/p/30/',
		'title'       => 'Thirty',
		'thumb_html'  => '<img src="thumb-30.jpg" alt="" loading="lazy">',
		'badges_html' => '<span class="badge">B</span>',
		'meta_html'   => '<div class="meta-row">row</div>',
	)
);
if ( false === strpos( $block_card, '<div class="related-tax-card">' ) ) {
	rp_fail( 'Block layout card must use a block container.' );
}
foreach ( array( '<div class="related-tax-thumb">', '<h4 class="related-tax-title">', '<a href="https://extrachill.test/p/30/">Thirty</a>', '<div class="related-tax-meta">' ) as $fragment ) {
	if ( false === strpos( $block_card, $fragment ) ) {
		rp_fail( 'Block layout card is missing ' . $fragment . '.' );
	}
}
$thumb_pos = strpos( $block_card, 'thumb-30.jpg' );
$badge_pos = strpos( $block_card, '<span class="badge">B</span>' );
$title_pos = strpos( $block_card, '<h4 class="related-tax-title">' );
if ( ! ( $thumb_pos < $badge_pos && $badge_pos < $title_pos ) ) {
	rp_fail( 'Block layout card must render badges between thumbnail and title.' );
}

// 6. Display function keeps the override/custom-display hook contract.
$GLOBALS['rp_fixture_override'] = true;
ob_start();
extrachill_display_related_posts( 'venue', 99 );
$override_output = ob_get_clean();
rp_assert_same( '', $override_output, 'Override mode must delegate rendering to the custom display action' );
if ( empty( $GLOBALS['rp_fixture_actions'] ) ) {
	rp_fail( 'Override mode did not fire extrachill_custom_related_posts_display.' );
}
$custom_action = $GLOBALS['rp_fixture_actions'][0];
rp_assert_same( 'extrachill_custom_related_posts_display', $custom_action[0], 'Custom display action name' );
rp_assert_same( array( 'venue', 99 ), $custom_action[1], 'Custom display action args' );

echo "Related-posts renderer honors dedup registry, both card layouts, and the override/custom-display hook contract.\n";
