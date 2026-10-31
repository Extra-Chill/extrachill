<?php
/**
 * "Did this help?" feedback smoke tests.
 *
 * Run with: php tests/feedback-smoke.php
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'EXTRACHILL_DOCS_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'EXTRACHILL_DOCS_PLUGIN_URL', 'https://docs.example/wp-content/plugins/extrachill-docs/' );

class WP_Post {
	public $ID          = 0;
	public $post_type   = 'page';
	public $post_status = 'publish';
	public $post_parent = 0;

	public function __construct( array $props ) {
		foreach ( $props as $key => $value ) {
			$this->$key = $value;
		}
	}
}

$GLOBALS['ec_test'] = array(
	'admin'      => false,
	'front'      => false,
	'singular'   => true,
	'post'       => null,
	'the_id'     => 0,
	'actions'    => array(),
	'enqueued'   => array(),
);

function add_action( $hook, $callback ) { $GLOBALS['ec_test']['actions'][ $hook ][] = $callback; }
function is_admin() { return $GLOBALS['ec_test']['admin']; }
function is_front_page() { return $GLOBALS['ec_test']['front']; }
function is_singular( $types = array() ) { unset( $types ); return $GLOBALS['ec_test']['singular']; }
function get_queried_object() { return $GLOBALS['ec_test']['post']; }
function get_queried_object_id() { return $GLOBALS['ec_test']['post'] ? $GLOBALS['ec_test']['post']->ID : 0; }
function get_the_ID() { return $GLOBALS['ec_test']['the_id']; }
function esc_html_e( $text ) { echo htmlspecialchars( $text, ENT_QUOTES ); }
function esc_url( $url ) { return $url; }
function trailingslashit( $value ) { return rtrim( $value, '/' ) . '/'; }
function wp_enqueue_script( $handle ) { $GLOBALS['ec_test']['enqueued'][] = $handle; }

require EXTRACHILL_DOCS_PLUGIN_DIR . 'inc/core/feedback.php';

$failures = array();
$check    = static function ( $condition, $message ) use ( &$failures ) {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};
$set_post = static function ( array $props ) {
	$GLOBALS['ec_test']['post']   = new WP_Post( $props );
	$GLOBALS['ec_test']['the_id'] = $props['ID'];
};

// Articles get feedback; navigation pages do not.
$set_post( array( 'ID' => 110, 'post_parent' => 54 ) );
$check( extrachill_docs_feedback_applies(), 'child page is an article' );

$set_post( array( 'ID' => 16, 'post_type' => 'ec_doc' ) );
$check( extrachill_docs_feedback_applies(), 'ec_doc single is an article' );

$set_post( array( 'ID' => 54, 'post_parent' => 0 ) );
$check( ! extrachill_docs_feedback_applies(), 'section landing page is skipped' );

$set_post( array( 'ID' => 111, 'post_parent' => 54, 'post_status' => 'draft' ) );
$check( ! extrachill_docs_feedback_applies(), 'draft preview is skipped' );

$set_post( array( 'ID' => 110, 'post_parent' => 54 ) );
$GLOBALS['ec_test']['front'] = true;
$check( ! extrachill_docs_feedback_applies(), 'front page is skipped' );
$GLOBALS['ec_test']['front'] = false;

// Markup carries the tracker contract extrachill-analytics reads.
$markup = extrachill_docs_feedback_markup();
$check( false !== strpos( $markup, 'data-ec-track-placement="doc-feedback"' ), 'placement attribute present' );
$check( false !== strpos( $markup, 'data-ec-track="doc-helpful-yes"' ), 'yes tracking id present' );
$check( false !== strpos( $markup, 'data-ec-track="doc-helpful-no"' ), 'no tracking id present' );
$check( 2 === substr_count( $markup, 'class="button-2 button-small"' ), 'both buttons use theme variant + size classes' );
$check( false !== strpos( $markup, 'community.extrachill.com/r/tech-support' ), 'no reply points to Tech Support' );

// Renders once, only for the queried post, from either theme hook.
$check( isset( $GLOBALS['ec_test']['actions']['extrachill_after_page_content'] ), 'hooked after page content' );
$check( isset( $GLOBALS['ec_test']['actions']['extrachill_after_post_content'] ), 'hooked after post content' );

$GLOBALS['ec_test']['the_id'] = 999;
ob_start();
extrachill_docs_render_feedback();
$check( '' === ob_get_clean(), 'archive card for another post renders nothing' );

$GLOBALS['ec_test']['the_id'] = 110;
ob_start();
extrachill_docs_render_feedback();
extrachill_docs_render_feedback();
$check( 1 === substr_count( (string) ob_get_clean(), 'class="docs-feedback"' ), 'renders exactly once' );

extrachill_docs_enqueue_feedback();
$check( in_array( 'extrachill-docs-feedback', $GLOBALS['ec_test']['enqueued'], true ), 'script enqueued on articles' );

if ( $failures ) {
	fwrite( STDERR, "Feedback smoke tests failed:\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}

echo "Feedback smoke tests passed.\n";
