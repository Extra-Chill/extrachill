<?php
/**
 * Virtual Local Scene member archives.
 *
 * @package ExtraChillCommunity
 */

defined( 'ABSPATH' ) || exit;

const EXTRACHILL_COMMUNITY_LOCAL_SCENE_REWRITE_VERSION = '1';

/**
 * Limit the route to the Community site.
 */
function extrachill_community_is_community_site(): bool {
	$community_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'community' ) : 2;
	return (int) get_current_blog_id() === (int) $community_blog_id;
}

/**
 * Register Local Scene archive rewrites.
 */
function extrachill_community_register_local_scene_rewrites(): void {
	if ( ! extrachill_community_is_community_site() ) {
		return;
	}

	add_rewrite_rule( '^local-scene/([^/]+)/?$', 'index.php?ec_local_scene=$matches[1]', 'top' );
	add_rewrite_rule( '^local-scene/([^/]+)/page/([0-9]+)/?$', 'index.php?ec_local_scene=$matches[1]&paged=$matches[2]', 'top' );

	if ( EXTRACHILL_COMMUNITY_LOCAL_SCENE_REWRITE_VERSION !== get_option( 'extrachill_community_local_scene_rewrite_version' ) ) {
		flush_rewrite_rules( false );
		update_option( 'extrachill_community_local_scene_rewrite_version', EXTRACHILL_COMMUNITY_LOCAL_SCENE_REWRITE_VERSION );
	}
}
add_action( 'init', 'extrachill_community_register_local_scene_rewrites' );

/**
 * Register the route query variable.
 *
 * @param string[] $vars Public query variables.
 * @return string[]
 */
function extrachill_community_local_scene_query_vars( array $vars ): array {
	$vars[] = 'ec_local_scene';
	return $vars;
}
add_filter( 'query_vars', 'extrachill_community_local_scene_query_vars' );

function extrachill_community_is_local_scene_archive(): bool {
	return extrachill_community_is_community_site() && '' !== (string) get_query_var( 'ec_local_scene', '' );
}

/**
 * Make the virtual route a successful archive request.
 *
 * @param WP_Query $query Main query.
 */
function extrachill_community_local_scene_query( $query ): void {
	if ( is_admin() || ! $query->is_main_query() || ! $query->get( 'ec_local_scene' ) ) {
		return;
	}

	$query->is_404     = false;
	$query->is_home    = false;
	$query->is_archive = true;
	status_header( 200 );
}
add_action( 'pre_get_posts', 'extrachill_community_local_scene_query' );

/**
 * Prevent core from turning a valid virtual archive into a 404.
 *
 * Invalid canonical scene slugs retain normal 404 handling.
 *
 * @param bool|null $preempt Existing preemption result.
 * @param WP_Query  $query   Main query.
 * @return bool|null
 */
function extrachill_community_local_scene_pre_handle_404( $preempt, $query ) {
	if ( ! $query->get( 'ec_local_scene' ) ) {
		return $preempt;
	}

	$data = extrachill_community_get_local_scene_archive_data();
	if ( is_wp_error( $data ) ) {
		return $preempt;
	}

	status_header( 200 );
	return true;
}
add_filter( 'pre_handle_404', 'extrachill_community_local_scene_pre_handle_404', 10, 2 );

/**
 * Execute the Users-owned public member query once per request.
 *
 * @return array|WP_Error
 */
function extrachill_community_get_local_scene_archive_data() {
	static $data;
	static $loaded = false;

	if ( $loaded ) {
		return $data;
	}
	$loaded = true;

	if ( ! function_exists( 'wp_get_ability' ) ) {
		$data = new WP_Error( 'ability_unavailable', __( 'Local Scene members are temporarily unavailable.', 'extra-chill-community' ) );
		return $data;
	}

	$ability = wp_get_ability( 'extrachill/local-scene-members' );
	if ( ! $ability ) {
		$data = new WP_Error( 'ability_unavailable', __( 'Local Scene members are temporarily unavailable.', 'extra-chill-community' ) );
		return $data;
	}

	$data = $ability->execute(
		array(
			'slug'     => sanitize_title( get_query_var( 'ec_local_scene' ) ),
			'page'     => max( 1, absint( get_query_var( 'paged', 1 ) ) ),
			'per_page' => 24,
		)
	);

	return $data;
}

function extrachill_community_get_local_scene_url( string $slug, int $page = 1 ): string {
	$base = function_exists( 'ec_get_site_url' ) ? ec_get_site_url( 'community' ) : home_url( '/' );
	$url  = trailingslashit( $base ) . 'local-scene/' . sanitize_title( $slug ) . '/';
	return $page > 1 ? $url . 'page/' . $page . '/' : $url;
}

/**
 * Load the Community-owned presentation template.
 */
function extrachill_community_local_scene_template( string $template ): string {
	return extrachill_community_is_local_scene_archive()
		? EXTRACHILL_COMMUNITY_PLUGIN_DIR . 'page-templates/local-scene-archive.php'
		: $template;
}
add_filter( 'extrachill_template_archive', 'extrachill_community_local_scene_template' );

/**
 * Use the canonical hierarchy label in the document title.
 *
 * @param array $parts Document title parts.
 * @return array
 */
function extrachill_community_local_scene_title( array $parts ): array {
	if ( ! extrachill_community_is_local_scene_archive() ) {
		return $parts;
	}

	$data  = extrachill_community_get_local_scene_archive_data();
	$scene = is_array( $data ) && is_array( $data['scene'] ?? null ) ? $data['scene'] : array();
	$label = sanitize_text_field( $scene['hierarchy']['label'] ?? $scene['name'] ?? '' );
	if ( '' !== $label ) {
		/* translators: %s: canonical Local Scene hierarchy label. */
		$parts['title'] = sprintf( __( 'People in %s', 'extra-chill-community' ), $label );
	}

	return $parts;
}
add_filter( 'document_title_parts', 'extrachill_community_local_scene_title', 1000 );

function extrachill_community_local_scene_canonical( string $canonical ): string {
	if ( ! extrachill_community_is_local_scene_archive() ) {
		return $canonical;
	}

	return extrachill_community_get_local_scene_url(
		sanitize_title( get_query_var( 'ec_local_scene' ) ),
		max( 1, absint( get_query_var( 'paged', 1 ) ) )
	);
}
add_filter( 'extrachill_seo_canonical_url', 'extrachill_community_local_scene_canonical' );

function extrachill_community_local_scene_redirect_canonical( $redirect_url ) {
	return extrachill_community_is_local_scene_archive() ? false : $redirect_url;
}
add_filter( 'redirect_canonical', 'extrachill_community_local_scene_redirect_canonical' );
