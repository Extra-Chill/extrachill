<?php
/**
 * Focused Artist Dispatch product and policy tests.
 *
 * Run: php tests/artist-dispatch.php
 */

define( 'ABSPATH', __DIR__ );
define( 'OBJECT', 'OBJECT' );
define( 'EXTRACHILL_BLOG_VERSION', 'test' );
define( 'EXTRACHILL_BLOG_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'EXTRACHILL_BLOG_PLUGIN_URL', 'https://example.com/plugin/' );
eval( 'namespace Automattic\\Blocks_Everywhere; class Blocks_Everywhere { const VERSION = "3.6.0"; }' );

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code, $message, $data = array() ) {
		$this->code = $code;
		$this->message = $message;
		$this->data = $data;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
class WP_Post {}
class WP_Query {
	public $found_posts = 0;
	public $posts = array();
	public function __construct() {
		$this->found_posts = $GLOBALS['dispatch_query_count'];
	}
}

$GLOBALS['dispatch_pages'] = array();
$GLOBALS['dispatch_query_count'] = 0;
$GLOBALS['dispatch_logged_in'] = true;
$GLOBALS['dispatch_ability'] = null;
$GLOBALS['dispatch_caps'] = array( 'edit_posts' => true, 'submit_for_review' => true );
$GLOBALS['dispatch_options'] = array();

function add_action() {}
function add_filter() {}
function apply_filters($hook, $value) { return $value; }
function register_post_meta() {}
function get_option($key, $default = false) { return array_key_exists($key, $GLOBALS['dispatch_options']) ? $GLOBALS['dispatch_options'][$key] : $default; }
function update_option($key, $value) { $GLOBALS['dispatch_options'][$key] = $value; return true; }
function add_option($key, $value) { if (array_key_exists($key, $GLOBALS['dispatch_options'])) return false; $GLOBALS['dispatch_options'][$key] = $value; return true; }
function delete_option($key) { unset($GLOBALS['dispatch_options'][$key]); return true; }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($value)); }
function wp_generate_uuid4() { static $uuid = 0; return '00000000-0000-4000-8000-' . str_pad((string) ++$uuid, 12, '0', STR_PAD_LEFT); }
function maybe_serialize($value) { return serialize($value); }
function __($text) { return $text; }
function esc_html__($text) { return $text; }
function esc_html($text) { return htmlspecialchars($text, ENT_QUOTES); }
function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
function esc_url($text) { return htmlspecialchars($text, ENT_QUOTES); }
function wp_kses_post($text) { return $text; }
function home_url($path = '') { return 'https://extrachill.com' . $path; }
function wp_login_url($url) { return 'https://extrachill.com/login?to=' . rawurlencode($url); }
function ec_get_site_url() { return 'https://community.extrachill.com'; }
function ec_get_blog_id($key) { return 'artist' === $key ? 4 : 1; }
function get_current_blog_id() { return 1; }
function switch_to_blog() {}
function restore_current_blog() {}
function get_post($post_id) {
	$post = new WP_Post();
	$post->ID = $post_id;
	$post->post_type = 'artist_profile';
	$post->post_status = 'publish';
	return $post;
}
function get_the_title($post) { return 'Test Artist'; }
function get_permalink($post) { return 'https://artist.extrachill.com/test-artist/'; }
function trailingslashit($url) { return rtrim($url, '/') . '/'; }
function is_user_logged_in() { return $GLOBALS['dispatch_logged_in']; }
function get_current_user_id() { return 7; }
function current_user_can($cap) { return ! empty($GLOBALS['dispatch_caps'][$cap]); }
function absint($value) { return abs((int) $value); }
function sanitize_text_field($value) { return trim((string) $value); }
function esc_url_raw($value) { return filter_var($value, FILTER_SANITIZE_URL); }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function is_wp_error($value) { return $value instanceof WP_Error; }
function get_page_by_path($path) {
	$key = basename($path);
	return isset($GLOBALS['dispatch_pages'][$key]) ? $GLOBALS['dispatch_pages'][$key] : null;
}
function wp_insert_post($data) {
	$post = (object) array('ID' => count($GLOBALS['dispatch_pages']) + 1, 'post_content' => $data['post_content'], 'post_status' => $data['post_status'], 'post_parent' => $data['post_parent']);
	$GLOBALS['dispatch_pages'][$data['post_name']] = $post;
	return $post->ID;
}
function wp_get_ability() {
	if ( null === $GLOBALS['dispatch_ability'] ) {
		return null;
	}
	return new class {
		public function execute() { return $GLOBALS['dispatch_ability']; }
	};
}

require dirname( __DIR__ ) . '/inc/submit/artist-dispatch.php';

$failures = 0;
function check($label, $condition) {
	global $failures;
	if ($condition) {
		echo "PASS: $label\n";
		return;
	}
	echo "FAIL: $label\n";
	++$failures;
}

extrachill_blog_provision_submit_pages();
check('provisions all three native page shells', 3 === count($GLOBALS['dispatch_pages']));
check('provisions feature pages as drafts', 'draft' === $GLOBALS['dispatch_pages']['submit']->post_status && 'draft' === $GLOBALS['dispatch_pages']['write']->post_status);
$first_pages = $GLOBALS['dispatch_pages'];
extrachill_blog_provision_submit_pages();
check('provisioning is idempotent and preserves existing pages', $first_pages === $GLOBALS['dispatch_pages']);
$GLOBALS['dispatch_pages']['submit']->post_content = 'Human content';
check('human-edited page is no longer claimed or overwritten', 0 === extrachill_blog_provision_submit_page('submit', 'Artist Dispatch', EXTRACHILL_BLOG_DISPATCH_PAGES['submit']['sentinel']));
$GLOBALS['dispatch_pages']['submit']->post_content = EXTRACHILL_BLOG_DISPATCH_PAGES['submit']['sentinel'];

$GLOBALS['dispatch_logged_in'] = false;
check('logged-out cohort has login and community actions', false !== strpos(extrachill_blog_dispatch_state_html(), 'Join the community'));
$GLOBALS['dispatch_logged_in'] = true;
$GLOBALS['dispatch_ability'] = null;
check('missing Users ability fails closed', false !== strpos(extrachill_blog_dispatch_state_html(), 'not accepting requests'));

$base = array('eligibility' => array('eligible' => false, 'criteria' => array(), 'reasons' => array(), 'policy' => array('pilot_enabled' => true)));
foreach (array('pending' => 'under review', 'rejected' => 'not approved', 'revoked' => 'access is unavailable', 'moderated' => 'access is unavailable') as $status => $copy) {
	$GLOBALS['dispatch_ability'] = array_merge($base, array('status' => $status));
	check("$status cohort renders its bounded state", false !== strpos(extrachill_blog_dispatch_state_html(), $copy));
}
$GLOBALS['dispatch_ability'] = array_merge($base, array('status' => 'ineligible'));
check('ineligible cohort renders progress path', false !== strpos(extrachill_blog_dispatch_state_html(), 'Keep building trust'));
$eligible = $base;
$eligible['status'] = 'none';
$eligible['eligibility']['eligible'] = true;
$eligible['eligibility']['criteria']['claimed_artist'] = array('passed' => true, 'artist_ids' => array(22));
$GLOBALS['dispatch_ability'] = $eligible;
check('eligible cohort consumes canonical artist IDs from owner contract', false !== strpos(extrachill_blog_dispatch_state_html(), 'value="22"'));
$GLOBALS['dispatch_ability'] = array_merge($eligible, array('status' => 'approved', 'artist_id' => 22, 'terms_acknowledged' => true, 'terms_version' => EXTRACHILL_BLOG_DISPATCH_TERMS_VERSION));
check('approved cohort renders native post dashboard', false !== strpos(extrachill_blog_dispatch_state_html(), 'New Artist Dispatch'));
$GLOBALS['dispatch_ability']['eligibility']['policy']['pilot_enabled'] = false;
check('disabled pilot fails closed for approved state', false !== strpos(extrachill_blog_dispatch_state_html(), 'not accepting requests'));

$valid_blocks = array(
	array('blockName' => 'core/paragraph', 'attrs' => array(), 'innerBlocks' => array()),
	array('blockName' => 'core/embed', 'attrs' => array('url' => 'https://www.youtube.com/watch?v=1'), 'innerBlocks' => array()),
);
check('text and approved embed policy passes', true === extrachill_blog_dispatch_validate_blocks($valid_blocks));
check('media block is rejected server-side', 'artist_dispatch_disallowed_block' === extrachill_blog_dispatch_validate_blocks(array(array('blockName' => 'core/image')))->get_error_code());
check('unsupported embed host is rejected server-side', 'artist_dispatch_disallowed_embed' === extrachill_blog_dispatch_validate_blocks(array(array('blockName' => 'core/embed', 'attrs' => array('url' => 'https://example.org/video'))))->get_error_code());
check('unstructured classic HTML is rejected server-side', 'artist_dispatch_unstructured_content' === extrachill_blog_dispatch_validate_blocks(array(array('blockName' => null, 'innerHTML' => '<p>raw</p>')))->get_error_code());
check('normalizes string raw content', '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' === extrachill_blog_dispatch_raw_value('<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->'));
check('normalizes canonical core raw envelope', 'wrapped' === extrachill_blog_dispatch_raw_value(array('raw' => 'wrapped')));
check('provenance mutation is denied outside trusted writer', false === extrachill_blog_dispatch_guard_provenance_meta(null, 12, EXTRACHILL_BLOG_DISPATCH_SUBMITTER_META));
$GLOBALS['extrachill_blog_dispatch_meta_write'] = 12;
check('trusted scoped provenance writer is admitted', null === extrachill_blog_dispatch_guard_provenance_meta(null, 12, EXTRACHILL_BLOG_DISPATCH_SUBMITTER_META));
unset($GLOBALS['extrachill_blog_dispatch_meta_write']);
$lock = extrachill_blog_dispatch_acquire_lock('create', 7);
check('first atomic operation lock succeeds', is_array($lock) && ! empty($lock['token']));
check('parallel operation lock fails closed', is_wp_error(extrachill_blog_dispatch_acquire_lock('create', 7)));
extrachill_blog_dispatch_release_lock($lock);

if ($failures) {
	exit(1);
}
echo "All Artist Dispatch PHP tests passed.\n";
