<?php
/** Standalone review access regression gate: php tests/review-tab-access.php. */
define( 'ABSPATH', __DIR__ );
$blog = 12;
$stack = array();
$caps = array();
function add_action( ...$args ) {}
function __( $text, $domain = '' ) { return $text; }
function ec_get_blog_id( $slug ) { return 1; }
function switch_to_blog( $id ) { global $blog, $stack; $stack[] = $blog; $blog = $id; }
function restore_current_blog() { global $blog, $stack; $blog = array_pop( $stack ); }
function current_user_can( $cap ) { global $blog, $caps; return ! empty( $caps[$blog][$cap] ); }
class WP_Error { public function __construct( public $code, ...$args ) {} }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
class WP_Post {
	public $post_type = 'post';
	public $post_modified_gmt = '2026-11-15 12:00:00';
	public $post_content = 'Article body';
	public function __construct( public $ID, public $post_author, public $post_status ) {}
}
$posts = array( 1 => new WP_Post( 1, 10, 'pending' ), 2 => new WP_Post( 2, 10, 'publish' ), 3 => new WP_Post( 3, 20, 'pending' ) );
function get_users( $args ) { return array( 10 ); }
class WP_Query {
	public $posts;
	public function __construct( $args ) {
		global $posts;
		$this->posts = array_filter( $posts, fn( $p ) => $p->post_type === $args['post_type'] && $p->post_status === $args['post_status'] && in_array( $p->post_author, $args['author__in'], true ) );
	}
}
function get_post( $id ) { global $posts; return $posts[$id] ?? null; }
function get_post_meta( ...$args ) { return array( 'submitted_at' => '2026-11-15T12:00:00Z' ); }
function get_the_author_meta( ...$args ) { return 'Writer'; }
function get_the_title( $post ) { return 'Submission ' . $post->ID; }
function get_edit_post_link( $id, $context ) { return 'https://main/wp-admin/post.php?post=' . $id; }
function get_preview_post_link( $post ) { return 'https://main/?p=' . $post->ID; }
function setup_postdata( $post ) {}
function wp_kses_post( $html ) { return $html; }
function apply_filters( $hook, $value ) { return $value; }
function get_the_post_thumbnail_url( ...$args ) { return 'https://main/image.jpg'; }
function wp_get_post_categories( ...$args ) { return array( 'Music' ); }
function wp_get_post_tags( ...$args ) { return array( 'Live' ); }
require __DIR__ . '/../../extrachill-users/inc/review-submissions-permissions.php';
require __DIR__ . '/../inc/review-queue.php';
require __DIR__ . '/../inc/abilities/review-submissions.php';
$failed = 0;
function check( $condition, $label ) { global $failed; echo ( $condition ? 'PASS' : 'FAIL' ) . ': ' . $label . PHP_EOL; $failed += $condition ? 0 : 1; }
$caps = array( 1 => array( 'review_submissions' => true ) );
check( count( ec_studio_list_review_submissions() ) === 1, 'granted non-editor can list' );
$article = ec_studio_read_review_submission( array( 'id' => 1 ) );
check( ! is_wp_error( $article ) && $article['content'] === 'Article body' && $article['categories'] === array( 'Music' ), 'granted non-editor can read rendered article metadata' );
check( ! isset( $article['edit_url'] ), 'non-editor receives no edit link' );
check( is_wp_error( ec_studio_read_review_submission( array( 'id' => 2 ) ) ), 'non-pending denied' );
check( is_wp_error( ec_studio_read_review_submission( array( 'id' => 3 ) ) ), 'non-team denied' );
$caps = array();
check( ! ec_studio_can_review_submissions() && is_wp_error( ec_studio_list_review_submissions() ) && is_wp_error( ec_studio_read_review_submission( array( 'id' => 1 ) ) ), 'ungranted team member denied list and read' );
$caps = array( 12 => array( 'edit_others_posts' => true ) );
check( ! ec_studio_can_review_submissions(), 'Studio-only editor is denied' );
$caps = array( 1 => array( 'edit_others_posts' => true ) );
check( count( ec_studio_list_review_submissions() ) === 1 && ! empty( ec_studio_read_review_submission( array( 'id' => 1 ) )['edit_url'] ), 'main editor can list, read and edit' );
check( $blog === 12 && empty( $stack ), 'main context restored' );

// Render the real block with the minimum WordPress presentation stubs.
function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, $args ); }
function get_block_wrapper_attributes( $args ) { return ''; }
$is_team_member = true;
function ec_is_team_member() { global $is_team_member; return $is_team_member; }
function is_user_logged_in() { return true; }
function wp_get_current_user() { return (object) array( 'display_name' => 'Reviewer' ); }
function get_bloginfo( $key ) { return 'Studio'; }
function home_url( $path ) { return 'https://studio/'; }
function wp_create_nonce( $action ) { return 'nonce'; }
function rest_url( $path ) { return 'https://studio/wp-json/' . $path; }
function is_multisite() { return false; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function esc_attr( $value ) { return htmlspecialchars( $value, ENT_QUOTES ); }
function esc_url( $value ) { return $value; }
$caps = array();
ob_start();
require __DIR__ . '/../src/blocks/studio/render.php';
$output = ob_get_clean();
check( str_contains( $output, 'data-can-review-submissions="false"' ) && ! str_contains( $output, 'data-can-review-submissions="true"' ), 'tab hidden without capability: render emits false' );
check( str_contains( $output, 'data-can-build-fonts="true"' ), 'team members can build fonts' );
$is_team_member = false;
$caps = array( 1 => array( 'review_submissions' => true ) );
ob_start();
require __DIR__ . '/../src/blocks/studio/render.php';
$output = ob_get_clean();
check( str_contains( $output, 'data-can-review-submissions="true"' ) && str_contains( $output, 'data-can-build-fonts="false"' ), 'review-only users cannot build fonts' );
$tabs = file_get_contents( __DIR__ . '/../src/blocks/studio/app/tabs.ts' );
check( str_contains( $tabs, 'canReviewSubmissions = false' ) && str_contains( $tabs, 'if ( canReviewSubmissions )' ), 'tab registration defaults closed and is conditional' );
// Exercise the explicit, network-wide grant/revoke helpers too.
$is_team_member = true;
$caps = array();
function ec_users_get_network_site_ids() { return array( 1, 12 ); }
function is_super_admin( $id ) { return false; }
class WP_User {
	public $caps;
	public function __construct( $id ) { global $caps, $blog; $this->caps = $caps[$blog] ?? array(); }
	public function exists() { return true; }
	public function add_cap( $cap ) { global $caps, $blog; $caps[$blog][$cap] = true; }
	public function remove_cap( $cap ) { global $caps, $blog; unset( $caps[$blog][$cap] ); }
}
check( ec_grant_review_submissions( 7 ) === array( 1, 12 ) && ec_can_review_submissions(), 'grant writes explicit access network-wide' );
check( ec_grant_review_submissions( 7 ) === array(), 'grant is idempotent' );
check( ec_revoke_review_submissions( 7 ) === array( 1, 12 ) && ! ec_can_review_submissions(), 'revoke removes access network-wide' );
check( ec_revoke_review_submissions( 7 ) === array() && $blog === 12, 'revoke is idempotent and restores context' );
exit( $failed ? 1 : 0 );
