<?php

$runtime = getenv( 'LINK_PAGES_WORKTREE' );
$ci_path = dirname( __DIR__, 2 ) . '/.ci/extrachill-link-pages';
if ( ! $runtime && is_file( $ci_path . '/tests/bootstrap.php' ) ) {
	$runtime = $ci_path;
} elseif ( ! $runtime && getenv( 'WP_PLUGIN_DIR' ) ) {
	$runtime = rtrim( getenv( 'WP_PLUGIN_DIR' ), '/' ) . '/extrachill-link-pages';
}
if ( ! $runtime || ! is_file( $runtime . '/tests/bootstrap.php' ) ) {
	fwrite( STDERR, "LINK_PAGES_WORKTREE or WP_PLUGIN_DIR must resolve the standalone Link Pages checkout.\n" );
	exit( 2 );
}
require_once $runtime . '/tests/bootstrap.php';
require_once __DIR__ . '/venue-link-pages-authorization.php';

final class VenueLinkPagesFixtureWpdb {
	private $locks = array();
	public function prepare( $query, ...$args ) {
		return vsprintf( str_replace( '%s', "'%s'", $query ), $args ); }
	public function get_blog_prefix( $blog_id ) {
		return 'wp_' . (int) $blog_id . '_'; }
	public function get_row( $query ) {
		if ( preg_match( '/FROM wp_(\d+)_posts WHERE ID = (\d+)/', $query, $matches ) ) {
			$post = $GLOBALS['ec_test']['blogs'][ (int) $matches[1] ]['posts'][ (int) $matches[2] ] ?? null;
			return $post ? (object) array(
				'ID'          => $post->ID,
				'post_type'   => $post->post_type,
				'post_status' => $post->post_status ?? 'publish',
			) : null;
		}
		if ( preg_match( "/FROM wp_(\d+)_term_taxonomy WHERE term_id = (\d+) AND taxonomy = '([^']+)'/", $query, $matches ) ) {
			$term = $GLOBALS['ec_test']['blogs'][ (int) $matches[1] ]['terms'][ (int) $matches[2] ] ?? null;
			return $term && $term->taxonomy === $matches[3] ? (object) array(
				'term_id'  => $term->term_id,
				'taxonomy' => $term->taxonomy,
			) : null;
		}
		if ( preg_match( '/FROM wp_(\d+)_term_taxonomy WHERE term_id = (\d+)/', $query, $matches ) ) {
			$term = $GLOBALS['ec_test']['blogs'][ (int) $matches[1] ]['terms'][ (int) $matches[2] ] ?? null;
			return $term ? (object) array(
				'term_id'  => $term->term_id,
				'taxonomy' => $term->taxonomy,
			) : null;
		}
		return null;
	}
	public function get_var( $query ) {
		if ( false !== strpos( $query, 'GET_LOCK' ) ) {
			if ( false !== strpos( $query, 'ec_link_page_ids' ) && ! empty( $GLOBALS['venue_link_page_fixture']['revoke_on_page_lock'] ) ) {
				$callback = $GLOBALS['venue_link_page_fixture']['revoke_on_page_lock'];
				unset( $GLOBALS['venue_link_page_fixture']['revoke_on_page_lock'] );
				$callback();
			}
			preg_match( "/GET_LOCK\\('([^']+)'/", $query, $matches );
			$key = $matches[1] ?? $query;
			if ( ! empty( $this->locks[ $key ] ) ) {
				return '0'; }
			$this->locks[ $key ]                      = true;
			$GLOBALS['ec_test']['advisory_lock_held'] = true;
			return '1';
		}
		if ( false !== strpos( $query, 'RELEASE_LOCK' ) ) {
			preg_match( "/RELEASE_LOCK\\('([^']+)'/", $query, $matches );
			unset( $this->locks[ $matches[1] ?? $query ] );
			$GLOBALS['ec_test']['advisory_lock_held'] = ! empty( $this->locks );
		}
		return '1';
	}
}
$GLOBALS['wpdb'] = new VenueLinkPagesFixtureWpdb();

function ec_get_blog_id( $site ) {
	return 'events' === $site ? 7 : 0; }
function get_current_user_id() {
	return (int) $GLOBALS['venue_link_page_fixture']['user_id']; }
function get_term_meta( $term_id, $key, $single = false ) {
	return $GLOBALS['venue_link_page_fixture']['term_meta'][ $term_id ][ $key ] ?? ''; }
function get_term_link( $term ) {
	return 'https://events.extrachill.com/venue/' . $term->slug . '/'; }
function get_home_url( $blog_id, $path = '/' ) {
	return ( 7 === (int) $blog_id ? 'https://events.extrachill.com' : 'https://artist.extrachill.com' ) . $path; }
function add_query_arg( $key, $value, $url ) {
	return $url . '?' . rawurlencode( $key ) . '=' . rawurlencode( (string) $value ); }
function trailingslashit( $value ) {
	return rtrim( $value, '/' ) . '/'; }
function data_machine_events_get_venue_profile( $term_id ) {
	$term = $GLOBALS['ec_test']['blogs'][7]['terms'][ $term_id ] ?? null;
	if ( ! $term ) {
		return new WP_Error( 'not_found', 'Not found.' ); }
	return array(
		'term_id'     => $term_id,
		'name'        => $term->name,
		'description' => $term->description,
		'address'     => '42 King Street',
		'city'        => 'Charleston',
		'state'       => 'SC',
		'zip'         => '29403',
		'country'     => 'US',
		'website'     => 'https://venue.example',
		'logo'        => array(
			'url' => 'https://venue.example/logo.jpg',
			'alt' => 'Venue logo',
		),
		'revision'    => $GLOBALS['venue_link_page_fixture']['revision'],
	);
}

require_once dirname( __DIR__, 2 ) . '/inc/Core/VenueLinkPages.php';

use ExtraChillEvents\Core\VenueLinkPages;

$GLOBALS['venue_link_page_fixture']          = array(
	'user_id'     => 10,
	'memberships' => array(
		'10:30' => true,
		'11:31' => true,
	),
	'revision'    => str_repeat( 'a', 64 ),
	'term_meta'   => array( 30 => array( '_venue_instagram' => 'https://instagram.com/venue' ) ),
);
$GLOBALS['ec_test']['blogs'][7]['terms'][30] = (object) array(
	'term_id'     => 30,
	'taxonomy'    => 'venue',
	'name'        => 'The Royal American',
	'slug'        => 'the-royal-american',
	'description' => 'Independent live music venue.',
);
$GLOBALS['ec_test']['blogs'][7]['terms'][31] = (object) array(
	'term_id'     => 31,
	'taxonomy'    => 'venue',
	'name'        => 'Other Venue',
	'slug'        => 'other-venue',
	'description' => '',
);
$GLOBALS['ec_test']['blogs'][7]['terms'][90] = (object) array(
	'term_id'  => 90,
	'taxonomy' => 'place',
);
$GLOBALS['ec_test']['blogs'][7]['terms'][91] = (object) array(
	'term_id'  => 91,
	'taxonomy' => 'place',
);

ec_register_link_page_owner_compatibility_provider( 'events-venues', array( VenueLinkPages::class, 'compatibility_provider' ) );
ec_register_link_page_operation_provider( 'events-venues', array( VenueLinkPages::class, 'operation_provider' ) );
ec_register_link_page_public_projection_provider( 'events-venues', array( VenueLinkPages::class, 'public_projection_provider' ) );
add_filter(
	'extrachill_get_link_page_analytics',
	static function ( $value, $link_page_id ) {
		$GLOBALS['venue_link_page_fixture']['analytics_blog'] = get_current_blog_id();
		return array(
			'link_page_id' => $link_page_id,
			'summary'      => array( 'total_views' => 12 ),
		);
	},
	20,
	2
);
$GLOBALS['ec_test']['execute_actions'] = true;

switch_to_blog( 7 );
$created      = VenueLinkPages::provision( 30 );
$page_id      = is_wp_error( $created ) ? 0 : (int) $created['link_page']['link_page_id'];
$read         = $page_id ? ec_read_link_page( VenueLinkPages::owner_reference( 30 ) ) : $created;
$saved        = $page_id ? ec_save_link_page(
	VenueLinkPages::owner_reference( 30 ),
	array(
		'links' => array(
			array(
				'id'            => 'new',
				'section_title' => 'Tickets',
				'links'         => array(
					array(
						'id'        => 'new',
						'link_text' => 'Calendar',
						'link_url'  => 'https://venue.example/events',
					),
				),
			),
		),
	)
) : $created;
$analytics    = $page_id ? VenueLinkPages::analytics( 30 ) : $created;
$caller_after = get_current_blog_id();

restore_current_blog();
ec_create_owned_link_page( 'term:7:place:90', 'Collision One', 'other-venue' );
ec_create_owned_link_page( 'term:7:place:91', 'Collision Two', 'other-venue-charleston-sc' );
switch_to_blog( 7 );
$GLOBALS['venue_link_page_fixture']['user_id'] = 11;
$collision                                     = VenueLinkPages::provision( 31 );
$GLOBALS['venue_link_page_fixture']['user_id'] = 10;

$final_before_failure                           = count(
	array_filter(
		$GLOBALS['ec_test']['fired_actions'],
		static function ( $action ) {
			return 'ec_link_page_save' === $action[0];
		}
	)
);
$cache_before_failure                           = count(
	array_filter(
		$GLOBALS['ec_test']['fired_actions'],
		static function ( $action ) {
			return 'extrachill_cache_purge_post' === $action[0];
		}
	)
);
$meta_calls_before                              = $GLOBALS['ec_test']['meta_write_calls'];
$GLOBALS['venue_link_page_fixture']['revision'] = str_repeat( 'b', 64 );
$GLOBALS['ec_test']['fail_meta_write_calls']    = array( $meta_calls_before + 2 );
$failed_save                                    = $page_id ? ec_save_link_page( VenueLinkPages::owner_reference( 30 ), array( 'bio' => 'Must roll back' ) ) : $created;
$GLOBALS['ec_test']['fail_meta_write_calls']    = array();
$bio_after_failure                              = $page_id ? get_post_meta( $page_id, '_link_page_bio_text', true ) : '';
$final_after_failure                            = count(
	array_filter(
		$GLOBALS['ec_test']['fired_actions'],
		static function ( $action ) {
			return 'ec_link_page_save' === $action[0];
		}
	)
);
$cache_after_failure                            = count(
	array_filter(
		$GLOBALS['ec_test']['fired_actions'],
		static function ( $action ) {
			return 'extrachill_cache_purge_post' === $action[0];
		}
	)
);

$GLOBALS['venue_link_page_fixture']['revision'] = str_repeat( 'c', 64 );
$refreshed                                      = VenueLinkPages::refresh_snapshot( 30 );
$refresh_caller                                 = get_current_blog_id();
$GLOBALS['venue_link_page_fixture']['revoke_on_page_lock'] = static function () {
	unset( $GLOBALS['venue_link_page_fixture']['memberships']['10:30'] );
};
$revoked_refresh = VenueLinkPages::refresh_snapshot( 30 );
$GLOBALS['venue_link_page_fixture']['memberships']['10:30'] = true;
$GLOBALS['venue_link_page_fixture']['revision']             = str_repeat( 'd', 64 );
$GLOBALS['venue_link_page_fixture']['user_id']              = 0;
VenueLinkPages::refresh_after_profile_update( 30 );
$GLOBALS['venue_link_page_fixture']['user_id'] = 10;
$trusted_snapshot                              = ec_with_link_page_storage_blog(
	static function () use ( $page_id ) {
		return get_post_meta( $page_id, VenueLinkPages::SNAPSHOT_META_KEY, true );
	}
);

$GLOBALS['venue_link_page_fixture']['user_id'] = 11;
$denied                                        = $page_id ? ec_read_link_page(
	array(
		'link_page_id'    => $page_id,
		'owner_reference' => VenueLinkPages::owner_reference( 30 ),
	)
) : $created;
$GLOBALS['venue_link_page_fixture']['user_id'] = 10;

restore_current_blog();
$registry   = ec_link_page_public_projection_registry();
$reflection = new ReflectionObject( $registry );
$providers  = $reflection->getProperty( 'providers' );
$providers->setAccessible( true );
$providers->setValue( $registry, array() );
$projection = $page_id ? ec_get_link_page_public_projection( $page_id ) : $created;
$html       = ! is_wp_error( $projection ) && is_callable( $projection['social_renderer'] ) ? call_user_func( $projection['social_renderer'], $projection['social_links'] ) : '';
$snapshot   = $page_id ? get_post_meta( $page_id, VenueLinkPages::SNAPSHOT_META_KEY, true ) : array();

if ( $page_id ) {
	$public_record                   = get_post_meta( $page_id, EC_LINK_PAGE_PUBLIC_SNAPSHOT_META_KEY, true );
	$public_record['owner_checksum'] = str_repeat( '0', 64 );
	update_post_meta( $page_id, EC_LINK_PAGE_PUBLIC_SNAPSHOT_META_KEY, $public_record );
}
$corrupt = $page_id ? ec_get_link_page_public_projection( $page_id ) : $created;
if ( $page_id ) {
	$public_record['owner_checksum'] = hash( 'sha256', $public_record['owner_reference'] );
	update_post_meta( $page_id, EC_LINK_PAGE_PUBLIC_SNAPSHOT_META_KEY, $public_record );
}
$cache_before_delete = count(
	array_filter(
		$GLOBALS['ec_test']['fired_actions'],
		static function ( $action ) {
			return 'extrachill_cache_purge_post' === $action[0];
		}
	)
);
switch_to_blog( 7 );
VenueLinkPages::capture_before_venue_deletion( 30, 'venue' );
$deleted_term = $GLOBALS['ec_test']['blogs'][7]['terms'][30];
unset( $GLOBALS['ec_test']['blogs'][7]['terms'][30] );
VenueLinkPages::orphan_after_venue_deletion( 30, 300, 'venue', $deleted_term );
restore_current_blog();
$deleted_owner      = $page_id ? ec_get_link_page_public_projection( $page_id ) : $created;
$cache_after_delete = count(
	array_filter(
		$GLOBALS['ec_test']['fired_actions'],
		static function ( $action ) {
			return 'extrachill_cache_purge_post' === $action[0];
		}
	)
);

echo wp_json_encode(
	array(
		'created'         => $page_id > 0,
		'owner'           => $page_id ? ec_get_stored_link_page_owner_references( $page_id ) : array(),
		'slug'            => $page_id ? get_post_field( 'post_name', $page_id ) : '',
		'read'            => ! is_wp_error( $read ) && 30 === $read['venue']['term_id'],
		'saved'           => ! is_wp_error( $saved ) && 'Calendar' === $saved['link_page']['links'][0]['links'][0]['link_text'],
		'analytics'       => ! is_wp_error( $analytics ) && 12 === $analytics['summary']['total_views'],
		'analytics_blog'  => $GLOBALS['venue_link_page_fixture']['analytics_blog'] ?? 0,
		'denied'          => is_wp_error( $denied ) ? $denied->get_error_code() : '',
		'caller_after'    => $caller_after,
		'collision_slug'  => is_wp_error( $collision ) ? $collision->get_error_code() : $collision['link_page']['public_url'],
		'rollback'        => array(
			'error'       => is_wp_error( $failed_save ) ? $failed_save->get_error_code() : '',
			'bio'         => $bio_after_failure,
			'final_delta' => $final_after_failure - $final_before_failure,
			'cache_delta' => $cache_after_failure - $cache_before_failure,
		),
		'refresh'         => array(
			'version' => is_wp_error( $refreshed ) ? $refreshed->get_error_code() : $refreshed['venue']['snapshot']['source']['version'],
			'caller'  => $refresh_caller,
			'revoked' => is_wp_error( $revoked_refresh ) ? $revoked_refresh->get_error_code() : '',
		),
		'trusted_refresh' => $trusted_snapshot['source']['version'] ?? '',
		'projection'      => ! is_wp_error( $projection ) ? array(
			'title'         => $projection['display_title'],
			'owner_type'    => $projection['body_attributes']['data-extrch-owner-type'],
			'schema_type'   => $projection['seo']['schema'][0]['@type'],
			'has_artist_id' => isset( $projection['body_attributes']['data-extrch-artist-id'] ),
			'components'    => $projection['components'],
		) : array( 'error' => $projection->get_error_code() ),
		'social_html'     => $html,
		'snapshot_source' => $snapshot['source'] ?? array(),
		'corrupt'         => is_wp_error( $corrupt ) ? $corrupt->get_error_code() : '',
		'deleted_owner'   => is_wp_error( $deleted_owner ) ? array( $deleted_owner->get_error_code(), $deleted_owner->get_error_data()['status'] ?? 0 ) : array(),
		'deletion'        => array(
			'status'      => $page_id ? get_post_field( 'post_status', $page_id ) : '',
			'audit'       => $page_id ? get_post_meta( $page_id, VenueLinkPages::ORPHAN_META_KEY, true ) : array(),
			'cache_delta' => $cache_after_delete - $cache_before_delete,
		),
		'http_functions'  => array( function_exists( 'wp_remote_get' ), function_exists( 'wp_remote_post' ) ),
		'final_hooks'     => count(
			array_filter(
				$GLOBALS['ec_test']['fired_actions'],
				static function ( $action ) {
					return 'ec_link_page_save' === $action[0]; }
			)
		),
		'cache_hooks'     => count(
			array_filter(
				$GLOBALS['ec_test']['fired_actions'],
				static function ( $action ) {
					return 'extrachill_cache_purge_post' === $action[0]; }
			)
		),
	)
);
