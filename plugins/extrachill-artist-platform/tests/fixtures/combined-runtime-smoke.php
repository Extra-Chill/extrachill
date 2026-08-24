<?php

$artist_root = dirname( __DIR__, 2 );
$standalone  = getenv( 'LINK_PAGES_WORKTREE' ) ?: '/var/lib/datamachine/workspace/extrachill-link-pages@feat-3-public-runtime';

define( 'EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR', $artist_root . '/' );
define( 'EXTRACHILL_ARTIST_PLATFORM_PLUGIN_URL', 'https://artist.example/plugins/artist/' );

function ec_get_blog_id( $type ) { return 'artist' === $type ? 4 : 1; }
function ec_get_site_url( $type ) { return 'artist' === $type ? 'https://artist.extrachill.com' : 'https://extrachill.com'; }
function get_the_title( $id ) { $post = get_post( $id ); return $post ? $post->post_title : ''; }
function get_permalink( $id ) { return 'https://artist.extrachill.com/artists/' . get_post_field( 'post_name', $id ) . '/'; }
function get_the_post_thumbnail_url( $id ) { $thumb = get_post_thumbnail_id( $id ); return $thumb ? wp_get_attachment_url( $thumb ) : false; }
function get_post_thumbnail_id( $id ) { return (int) get_post_meta( $id, '_thumbnail_id', true ); }
function set_post_thumbnail( $id, $thumb ) { return update_post_meta( $id, '_thumbnail_id', (int) $thumb ); }
function delete_post_thumbnail( $id ) { return delete_post_meta( $id, '_thumbnail_id' ); }
function wp_get_attachment_image_url( $id ) { return wp_get_attachment_url( $id ); }
function get_post_status( $id ) { $post = get_post( $id ); return $post ? $post->post_status : false; }
function maybe_unserialize( $value ) { return $value; }
function get_current_user_id() { return 7; }
function ec_user_can() { return true; }
function ec_render_template( $template, $args = array() ) {
	if ( 'link-section' === $template ) {
		$output = '';
		foreach ( $args['links'] ?? array() as $link ) { $output .= '<span class="extrch-link-page-link-text">' . esc_attr( $link['link_text'] ) . '</span>'; }
		return $output;
	}
	return '<div data-template="' . esc_attr( $template ) . '"></div>';
}
function wp_add_inline_style() { return true; }
function wp_enqueue_script() { return true; }
function wp_enqueue_style() { return true; }
function language_attributes() { echo 'lang="en-US"'; }
function get_bloginfo() { return 'UTF-8'; }
function get_site_icon_url() { return ''; }
function wp_print_styles() {}
function wp_print_footer_scripts() {}
function untrailingslashit( $value ) { return rtrim( $value, '/' ); }
function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, is_array( $args ) ? $args : array() ); }
function wp_get_ability() { return null; }
function ec_combined_cache_purge_post( $post_id ) {
	$urls = apply_filters( 'extrachill_cache_post_change_urls', null, $post_id, get_post_type( $post_id ) );
	$GLOBALS['ec_test']['cache_action_blogs'][] = get_current_blog_id();
	foreach ( is_array( $urls ) ? $urls : array() as $url ) { $GLOBALS['ec_test']['cache_deleted_urls'][] = $url; }
}

class EcCombinedSocials {
	public function save( $artist_id, $socials ) {
		if ( ! empty( $GLOBALS['probe_cross_context'] ) ) {
			global $wpdb;
			$scope = $GLOBALS['ec_link_page_lock_scope'] ?? array();
			$GLOBALS['cross_lock_blog'] = $scope['blog_id'] ?? 0;
			$GLOBALS['cross_lock_page'] = $scope['link_page_id'] ?? 0;
			$GLOBALS['cross_mutation_blog'] = get_current_blog_id();
			$GLOBALS['cross_contention_result'] = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', 'ec_link_page_ids:4:' . ( $scope['link_page_id'] ?? 0 ) ) );
		}
		if ( ! empty( $GLOBALS['record_owner_lock'] ) ) {
			$GLOBALS['owner_writes_locked'] = ! empty( $GLOBALS['ec_link_page_lock_scope'] ) && ( $GLOBALS['owner_writes_locked'] ?? true );
		}
		if ( ! empty( $GLOBALS['fail_social_once'] ) ) {
			global $wpdb;
			$GLOBALS['failure_contention_result'] = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', 'ec_link_page_ids:4:101' ) );
			unset( $GLOBALS['fail_social_once'] );
			return new WP_Error( 'social_failure', 'Social save failed.' );
		}
		update_post_meta( $artist_id, '_artist_profile_social_links', $socials );
		return true;
	}
	public function get( $artist_id ) { $socials = get_post_meta( $artist_id, '_artist_profile_social_links', true ); return is_array( $socials ) ? $socials : array(); }
	public function get_icon_class( $type ) { return 'icon-' . $type; }
}
class EcCombinedQuery {
	public $queried_object;
	public function get_queried_object() { return $this->queried_object; }
}
function extrachill_artist_platform_social_links() { static $manager; if ( ! $manager ) { $manager = new EcCombinedSocials(); } return $manager; }

require $standalone . '/tests/bootstrap.php';

$GLOBALS['ec_test']['options']['active_plugins'] = array( 'extrachill-link-pages/extrachill-link-pages.php' );
add_filter( 'ec_get_artist_id', static function ( $value, $input = null ) {
	$id = is_numeric( $input ) ? (int) $input : ( is_numeric( $value ) ? (int) $value : 0 );
	return EC_LINK_PAGE_POST_TYPE === get_post_type( $id ) ? (int) get_post_meta( $id, '_associated_artist_profile_id', true ) : $value;
}, 10, 2 );

require_once $artist_root . '/inc/link-pages/runtime-handoff.php';
require_once $artist_root . '/inc/core/filters/data.php';
require_once $artist_root . '/inc/core/filters/create.php';
require_once $artist_root . '/inc/core/actions/save.php';
require_once $artist_root . '/inc/abilities/helpers.php';
require_once $artist_root . '/inc/abilities/handlers/save-link-page-links.php';
require_once $artist_root . '/inc/abilities/handlers/save-link-page-styles.php';
require_once $artist_root . '/inc/abilities/handlers/save-link-page-settings.php';
require_once $artist_root . '/inc/abilities/handlers/save-social-links.php';
require_once $artist_root . '/inc/abilities/handlers/update-artist.php';
$registered = extrachill_artist_platform_register_link_page_adapters();
if ( is_wp_error( $registered ) ) { echo wp_json_encode( array( 'bootstrap_error' => $registered->get_error_code() ) ); exit( 1 ); }
add_action( 'extrachill_cache_purge_post', 'ec_combined_cache_purge_post' );
$GLOBALS['ec_test']['execute_actions'] = true;

$GLOBALS['ec_test']['blogs'][4]['posts'][20] = (object) array(
	'ID'           => 20,
	'post_type'    => 'artist_profile',
	'post_status'  => 'publish',
	'post_title'   => 'Combined Artist',
	'post_name'    => 'combined-artist',
	'post_excerpt' => 'Combined artist excerpt.',
	'post_content' => 'Combined artist biography.',
);
$GLOBALS['ec_test']['blogs'][4]['posts'][21] = (object) array( 'ID' => 21, 'post_type' => 'artist_profile', 'post_status' => 'publish', 'post_title' => 'Existing Artist', 'post_name' => 'existing-artist', 'post_excerpt' => '', 'post_content' => '' );
$GLOBALS['ec_test']['blogs'][4]['posts'][40] = (object) array( 'ID' => 40, 'post_type' => EC_LINK_PAGE_POST_TYPE, 'post_status' => 'publish', 'post_title' => 'Existing Artist', 'post_name' => 'existing-artist' );
$GLOBALS['ec_test']['blogs'][4]['post_meta'][40] = array( EC_LINK_PAGE_OWNER_META_KEY => 'post:4:artist_profile:21', '_associated_artist_profile_id' => 21 );

$created = ec_create_link_page( 20 );
$creation_hooks = array_values( array_map( static function ( $action ) { return $action[0]; }, array_filter( $GLOBALS['ec_test']['fired_actions'], static function ( $action ) { return in_array( $action[0], array( 'ec_link_page_created', 'ec_owned_link_page_created' ), true ); } ) ) );
$cache_actions_before_save = count( array_filter( $GLOBALS['ec_test']['fired_actions'], static function ( $action ) { return 'extrachill_cache_purge_post' === $action[0]; } ) );
$GLOBALS['ec_test']['blogs'][1] = array( 'posts' => array(), 'post_meta' => array(), 'terms' => array() );
$cross_blog_resolution = array();
foreach ( array( 1, 7 ) as $caller_blog_id ) {
	switch_to_blog( $caller_blog_id );
	$reference = ec_artist_link_page_owner_reference( 20 );
	$cross_blog_resolution[ $caller_blog_id ] = array(
		'id'   => ec_get_link_page_id_for_owner( $reference ),
		'read' => ec_read_link_page_persistence( $created )['link_page_id'] ?? 0,
		'urls' => ec_link_page_public_urls( $created ),
		'caller' => get_current_blog_id(),
		'existing' => ec_get_link_page_id_for_owner( ec_artist_link_page_owner_reference( 21 ) ),
	);
	restore_current_blog();
}
$read    = is_wp_error( $created ) ? $created : ec_read_link_page( $created );
$mode = $argv[1] ?? '';
if ( in_array( $mode, array( 'owner-save-failure', 'interleaving-failure' ), true ) ) {
	$GLOBALS['fail_social_once'] = true;
}
$GLOBALS['record_owner_lock'] = true;
$save_hook_offset = count( $GLOBALS['ec_test']['fired_actions'] );
$saved   = is_wp_error( $created ) ? $created : ec_save_link_page(
	$created,
	array(
		'bio'                    => 'Updated bio',
		'links'                  => array( array( 'id' => 'new-section', 'section_title' => 'Listen', 'links' => array( array( 'id' => 'new-link', 'link_text' => 'Song', 'link_url' => 'https://example.com/song' ) ) ) ),
		'css_vars'               => array( '--link-page-text-color' => '#abcdef' ),
		'subscribe_display_mode' => 'inline_form',
		'social_icons'           => array( array( 'type' => 'instagram', 'url' => 'https://instagram.com/example' ) ),
		'profile_image_id'       => 55,
	)
);
$save_actions = array_slice( $GLOBALS['ec_test']['fired_actions'], $save_hook_offset );
$save_hooks = array_values( array_map( static function ( $action ) { return $action[0]; }, array_filter( $save_actions, static function ( $action ) { return in_array( $action[0], array( 'ec_link_page_save', 'ec_link_page_persistence_saved' ), true ); } ) ) );
$combined_owner_writes_locked = $GLOBALS['owner_writes_locked'] ?? false;
unset( $GLOBALS['record_owner_lock'] );
$contention = array();
if ( 'contention' === ( $argv[1] ?? '' ) && ! is_wp_error( $created ) ) {
	$contention['first'] = ec_with_link_page_lock_scope(
		$created,
		static function () use ( $created, &$contention ) {
			global $wpdb;
			$first = ec_save_link_page( $created, array( 'bio' => 'First authorized save' ) );
			$contention['blocked'] = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', 'ec_link_page_ids:4:' . $created ) );
			return $first;
		}
	);
	$contention['second'] = ec_save_link_page( $created, array( 'bio' => 'Second authorized save' ) );
	$contention['final']  = get_post_meta( $created, '_link_page_bio_text', true );
}
$cache_actions_after_save = count( array_filter( $GLOBALS['ec_test']['fired_actions'], static function ( $action ) { return 'extrachill_cache_purge_post' === $action[0]; } ) );
$persisted_after_failed_save = is_wp_error( $created ) ? '' : get_post_meta( $created, '_link_page_bio_text', true );
$post_failure_save = null;
if ( 'interleaving-failure' === $mode ) {
	$post_failure_save = extrachill_artist_platform_ability_save_social_links( array( 'artist_id' => 20, 'social_links' => array( array( 'type' => 'bandcamp', 'url' => 'https://bandcamp.com/authoritative' ) ) ) );
}
$ability_results = array();
$cache_actions_before_abilities = count( array_filter( $GLOBALS['ec_test']['fired_actions'], static function ( $action ) { return 'extrachill_cache_purge_post' === $action[0]; } ) );
if ( ! in_array( $mode, array( 'owner-save-failure', 'interleaving-failure', 'separate-contention' ), true ) ) {
	$ability_results['links'] = extrachill_artist_platform_ability_save_link_page_links(
		array(
			'artist_id' => 20,
			'links'     => array( array( 'id' => '101-section-1', 'section_title' => 'Listen', 'links' => array( array( 'id' => '101-link-1', 'link_text' => 'Song updated', 'link_url' => 'https://example.com/song' ) ) ) ),
		)
	);
	$ability_results['styles'] = extrachill_artist_platform_ability_save_link_page_styles( array( 'artist_id' => 20, 'css_vars' => array( '--link-page-text-color' => '#fedcba' ) ) );
	$ability_results['settings'] = extrachill_artist_platform_ability_save_link_page_settings( array( 'artist_id' => 20, 'bio' => 'Ability bio', 'profile_image_id' => 55 ) );
	$ability_results['socials'] = extrachill_artist_platform_ability_save_social_links( array( 'artist_id' => 20, 'social_links' => array( array( 'type' => 'instagram', 'url' => 'https://instagram.com/example' ) ) ) );
}
$separate_contention = array();
if ( 'separate-contention' === $mode ) {
	$separate_contention['during_combined'] = ec_with_link_page_lock_scope(
		$created,
		static function () {
			return array(
				'social'  => extrachill_artist_platform_ability_save_social_links( array( 'artist_id' => 20, 'social_links' => array( array( 'type' => 'blocked', 'url' => 'https://example.com/blocked' ) ) ) ),
				'profile' => extrachill_artist_platform_ability_update_artist( array( 'artist_id' => 20, 'name' => 'Blocked profile mutation' ) ),
			);
		},
		'combined'
	);
	$separate_contention['during_separate'] = ec_artist_with_link_page_lock(
		20,
		static function () use ( $created ) {
			return ec_save_link_page( $created, array( 'bio' => 'Blocked combined save' ) );
		}
	);
	$separate_contention['social_after'] = extrachill_artist_platform_ability_save_social_links( array( 'artist_id' => 20, 'social_links' => array( array( 'type' => 'after', 'url' => 'https://example.com/after' ) ) ) );
	$separate_contention['profile_after'] = extrachill_artist_platform_ability_update_artist( array( 'artist_id' => 20, 'name' => 'Profile after release' ) );
	$separate_contention['combined_after'] = ec_save_link_page( $created, array( 'bio' => 'Combined after separate release' ) );
}
$cache_actions_after_abilities = count( array_filter( $GLOBALS['ec_test']['fired_actions'], static function ( $action ) { return 'extrachill_cache_purge_post' === $action[0]; } ) );
$cross_blog_mutation = array();
if ( in_array( $mode, array( 'cross-blog-1', 'cross-blog-7', 'cross-blog-failure' ), true ) ) {
	$caller_blog_id = 'cross-blog-1' === $mode ? 1 : 7;
	if ( 'cross-blog-failure' === $mode ) {
		$GLOBALS['fail_social_once'] = true;
	}
	$GLOBALS['probe_cross_context'] = true;
	$GLOBALS['probe_profile_context'] = true;
	$before_cache = count( array_filter( $GLOBALS['ec_test']['fired_actions'], static function ( $action ) { return 'extrachill_cache_purge_post' === $action[0]; } ) );
	switch_to_blog( $caller_blog_id );
	$entry_stack = $GLOBALS['_wp_switched_stack'];
	$cross_result = extrachill_artist_platform_ability_save_social_links( array( 'artist_id' => 20, 'social_links' => array( array( 'type' => 'cross', 'url' => 'https://example.com/cross' ) ) ) );
	$profile_result = null;
	if ( 'cross-blog-failure' !== $mode ) {
		$profile_result = extrachill_artist_platform_ability_update_artist( array( 'artist_id' => 20, 'name' => 'Cross-blog profile' ) );
	}
	$after_cache = count( array_filter( $GLOBALS['ec_test']['fired_actions'], static function ( $action ) { return 'extrachill_cache_purge_post' === $action[0]; } ) );
	$cross_blog_mutation = array(
		'result'          => is_wp_error( $cross_result ) ? $cross_result->get_error_code() : true,
		'profile_result'  => null === $profile_result ? null : ! is_wp_error( $profile_result ),
		'lock_blog'       => $GLOBALS['cross_lock_blog'] ?? 0,
		'lock_page'       => $GLOBALS['cross_lock_page'] ?? 0,
		'mutation_blog'   => $GLOBALS['cross_mutation_blog'] ?? 0,
		'profile_lock_blog' => $GLOBALS['profile_lock_blog'] ?? 0,
		'profile_lock_page' => $GLOBALS['profile_lock_page'] ?? 0,
		'profile_mutation_blog' => $GLOBALS['profile_mutation_blog'] ?? 0,
		'cache_blog'      => end( $GLOBALS['ec_test']['cache_action_blogs'] ) ?: 0,
		'cache_delta'     => $after_cache - $before_cache,
		'contender'       => $GLOBALS['cross_contention_result'] ?? null,
		'caller_after'    => get_current_blog_id(),
		'stack_restored'  => $entry_stack === $GLOBALS['_wp_switched_stack'],
	);
	restore_current_blog();
	unset( $GLOBALS['probe_cross_context'] );
	unset( $GLOBALS['probe_profile_context'] );
}
update_post_meta( 55, '_wp_attachment_image_alt', 'Combined Artist portrait' );
if ( 'orphan-owner' === ( $argv[1] ?? '' ) ) {
	$GLOBALS['ec_test']['blogs'][4]['posts'][20]->post_status = 'draft';
} elseif ( 'deleted-owner' === ( $argv[1] ?? '' ) ) {
	unset( $GLOBALS['ec_test']['blogs'][4]['posts'][20] );
}
$projection = is_wp_error( $created ) ? $created : ec_get_link_page_public_projection( $created );
$rendered = '';
$fallback_rendered = '';
if ( ! is_wp_error( $created ) ) {
	$GLOBALS['wp_query'] = new EcCombinedQuery();
	$GLOBALS['wp_query']->queried_object = get_post( $created );
	ob_start();
	require $standalone . '/templates/single-link-page.php';
	$rendered = ob_get_clean();
	if ( 'deleted-owner' !== ( $argv[1] ?? '' ) ) {
		ob_start();
		require $artist_root . '/inc/link-pages/live/templates/single-artist_link_page.php';
		$fallback_rendered = ob_get_clean();
	}
}
$GLOBALS['wp_query'] = (object) array( 'posts' => array(), 'query_vars' => array(), 'is_404' => true );
$_SERVER['HTTP_HOST'] = 'extrachill.link';
$_SERVER['REQUEST_URI'] = '/join/';
ec_resolve_link_page_public_query();
$join_redirect = $GLOBALS['ec_test']['redirect'] ?? array();
$force_failure = 'force-failure' === ( $argv[1] ?? '' );
if ( $force_failure ) {
	$GLOBALS['ec_test']['fail_meta_write_calls'] = array( $GLOBALS['ec_test']['meta_write_calls'] + 5 );
}
$force_hook_offset = count( $GLOBALS['ec_test']['fired_actions'] );
$forced     = is_wp_error( $created ) ? $created : ec_create_link_page( 20, true );
$force_actions = array_slice( $GLOBALS['ec_test']['fired_actions'], $force_hook_offset );
$force_hooks = array_values( array_map( static function ( $action ) { return $action[0]; }, array_filter( $force_actions, static function ( $action ) { return in_array( $action[0], array( 'ec_link_page_created', 'ec_owned_link_page_created' ), true ); } ) ) );
$purges_before_delete = count( array_filter( $GLOBALS['ec_test']['fired_actions'], static function ( $action ) { return 'extrachill_cache_purge_post' === $action[0]; } ) );
if ( ! is_wp_error( $forced ) ) {
	ec_purge_link_page_before_delete( $forced );
	wp_delete_post( $forced, true );
}
$purges_after_delete = count( array_filter( $GLOBALS['ec_test']['fired_actions'], static function ( $action ) { return 'extrachill_cache_purge_post' === $action[0]; } ) );
$final_hooks = array_values( array_filter( $GLOBALS['ec_test']['fired_actions'], static function ( $action ) { return 'ec_link_page_save' === $action[0]; } ) );

echo wp_json_encode(
	array(
		'created'             => is_wp_error( $created ) ? $created->get_error_code() : $created,
		'creation_hooks'      => $creation_hooks,
		'read_shape'          => is_array( $read ) && isset( $read['artist_id'], $read['settings'], $read['links'] ),
		'saved'               => is_wp_error( $saved ) ? $saved->get_error_code() : true,
		'save_hooks'          => $save_hooks,
		'saved_bio'           => is_array( $saved ) ? $saved['bio'] : '',
		'persisted_bio'       => is_wp_error( $created ) ? '' : get_post_meta( $created, '_link_page_bio_text', true ),
		'persisted_after_failed_save' => $persisted_after_failed_save,
		'post_failure_save'   => null === $post_failure_save ? null : ! is_wp_error( $post_failure_save ),
		'post_failure_social_url' => ( get_post_meta( 20, '_artist_profile_social_links', true )[0]['url'] ?? '' ),
		'ability_results'     => array_map( static function ( $result ) { return is_wp_error( $result ) ? $result->get_error_code() : is_array( $result ); }, $ability_results ),
		'owner_writes_locked' => $combined_owner_writes_locked,
		'failure_contention'  => $GLOBALS['failure_contention_result'] ?? null,
		'contention'          => array(
			'first'   => isset( $contention['first'] ) && ! is_wp_error( $contention['first'] ),
			'blocked' => $contention['blocked'] ?? null,
			'second'  => isset( $contention['second'] ) && ! is_wp_error( $contention['second'] ),
			'final'   => $contention['final'] ?? '',
		),
		'separate_contention' => array(
			'during_combined_social' => isset( $separate_contention['during_combined']['social'] ) && is_wp_error( $separate_contention['during_combined']['social'] ) ? $separate_contention['during_combined']['social']->get_error_code() : null,
			'during_combined_profile' => isset( $separate_contention['during_combined']['profile'] ) && is_wp_error( $separate_contention['during_combined']['profile'] ) ? $separate_contention['during_combined']['profile']->get_error_code() : null,
			'during_separate' => isset( $separate_contention['during_separate'] ) && is_wp_error( $separate_contention['during_separate'] ) ? $separate_contention['during_separate']->get_error_code() : null,
			'social_after'    => isset( $separate_contention['social_after'] ) && ! is_wp_error( $separate_contention['social_after'] ),
			'profile_after'   => isset( $separate_contention['profile_after'] ) && ! is_wp_error( $separate_contention['profile_after'] ),
			'combined_after'  => isset( $separate_contention['combined_after'] ) && ! is_wp_error( $separate_contention['combined_after'] ),
			'final_bio'       => get_post_meta( $created, '_link_page_bio_text', true ),
			'final_social_url'=> ( get_post_meta( 20, '_artist_profile_social_links', true )[0]['url'] ?? '' ),
			'final_profile_title' => get_the_title( 20 ),
		),
		'cross_blog_mutation' => $cross_blog_mutation,
		'social_count'        => count( get_post_meta( 20, '_artist_profile_social_links', true ) ?: array() ),
		'thumbnail'           => get_post_thumbnail_id( 20 ),
		'projection_title'    => is_wp_error( $projection ) ? $projection->get_error_code() : $projection['display_title'],
		'projection_schema'   => ! is_wp_error( $projection ) && 2 === count( $projection['seo']['schema'] ),
		'projection_alt'      => is_wp_error( $projection ) ? '' : $projection['seo']['image_alt'],
		'projection_canonical'=> is_wp_error( $projection ) ? '' : $projection['seo']['canonical'],
		'public_status'       => $GLOBALS['ec_test']['status'] ?? 200,
		'rendered_body'       => false !== strpos( $rendered, 'data-extrch-artist-id="20"' ) && false !== strpos( $rendered, 'extrch-link-page' ),
		'rendered_subscribe'  => false !== strpos( $rendered, 'data-template="subscribe-inline-form"' ),
		'snapshot_parity'     => false !== strpos( $rendered, 'extrch-link-page-title">Combined Artist</h1>' )
			&& false !== strpos( $fallback_rendered, 'extrch-link-page-title">Combined Artist</h1>' )
			&& false !== strpos( $rendered, 'extrch-link-page-link-text">Song updated</span>' )
			&& false !== strpos( $fallback_rendered, 'extrch-link-page-link-text">Song updated</span>' )
			&& false !== strpos( $rendered, 'data-extrch-artist-id="20"' )
			&& false !== strpos( $fallback_rendered, 'data-extrch-artist-id="20"' ),
		'head_hook'           => 1 <= count( array_filter( $GLOBALS['ec_test']['fired_actions'], static function ( $action ) { return 'extrachill_artist_link_page_minimal_head' === $action[0] && 20 === $action[1][1]; } ) ),
		'join_redirect'       => $join_redirect[0] ?? '',
		'forced'              => is_wp_error( $forced ) ? $forced->get_error_code() : $forced,
		'force_hooks'         => $force_hooks,
		'force_restored_id'   => (int) get_post_meta( 20, '_extrch_link_page_id', true ),
		'force_restored_slug' => get_post_field( 'post_name', $created ),
		'force_new_count'     => count( get_posts( array( 'post_type' => EC_LINK_PAGE_POST_TYPE, 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1, 'meta_key' => '_associated_artist_profile_id', 'meta_value' => '20' ) ) ),
		'legacy_detached'     => is_wp_error( $created ) ? false : '' === get_post_meta( $created, '_associated_artist_profile_id', true ),
		'final_hook_count'    => count( $final_hooks ),
		'delete_purge_delta'  => $purges_after_delete - $purges_before_delete,
		'cache_deleted_urls'  => array_values( array_unique( $GLOBALS['ec_test']['cache_deleted_urls'] ?? array() ) ),
		'cache_action_count'  => count( array_filter( $GLOBALS['ec_test']['fired_actions'], static function ( $action ) { return 'extrachill_cache_purge_post' === $action[0]; } ) ),
		'save_cache_delta'    => $cache_actions_after_save - $cache_actions_before_save,
		'ability_cache_delta' => $cache_actions_after_abilities - $cache_actions_before_abilities,
		'caller_blog'         => get_current_blog_id(),
		'cross_blog_resolution'=> $cross_blog_resolution,
	)
);
