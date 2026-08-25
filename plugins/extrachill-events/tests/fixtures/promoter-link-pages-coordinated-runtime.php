<?php

$runtime = getenv( 'LINK_PAGES_WORKTREE' );
if ( ! $runtime || ! is_file( $runtime . '/tests/bootstrap.php' ) ) {
	fwrite( STDERR, "LINK_PAGES_WORKTREE must resolve the standalone Link Pages checkout.\n" );
	exit( 2 );
}
require_once $runtime . '/tests/bootstrap.php';
require_once __DIR__ . '/venue-link-pages-authorization.php';
require_once __DIR__ . '/promoter-link-pages-authorization.php';
require_once __DIR__ . '/promoter-link-pages-principal.php';

$GLOBALS['promoter_link_page_abilities'] = array();
function wp_register_ability( $name, $definition ) {
	$GLOBALS['promoter_link_page_abilities'][ $name ] = $definition; }

function promoter_link_page_fixture_schema_valid( $value, array $schema ): bool {
	if ( isset( $schema['oneOf'] ) ) {
		$matches = array_filter( $schema['oneOf'], static function ( $candidate ) use ( $value ) { return promoter_link_page_fixture_schema_valid( $value, $candidate ); } );
		return 1 === count( $matches );
	}
	$type = $schema['type'] ?? null;
	if ( 'object' === $type ) {
		if ( ! is_array( $value ) ) {
			return false;
		}
		if ( array_diff( $schema['required'] ?? array(), array_keys( $value ) ) ) {
			return false;
		}
		if ( false === ( $schema['additionalProperties'] ?? true ) && array_diff( array_keys( $value ), array_keys( $schema['properties'] ?? array() ) ) ) {
			return false;
		}
		foreach ( $value as $key => $child ) {
			if ( isset( $schema['properties'][ $key ] ) && ! promoter_link_page_fixture_schema_valid( $child, $schema['properties'][ $key ] ) ) {
				return false;
			}
		}
	} elseif ( 'array' === $type ) {
		if ( ! is_array( $value ) ) {
			return false;
		}
		foreach ( $value as $child ) {
			if ( ! promoter_link_page_fixture_schema_valid( $child, $schema['items'] ) ) {
				return false;
			}
		}
	} elseif ( 'string' === $type && ! is_string( $value ) ) {
		return false;
	} elseif ( 'integer' === $type && ! is_int( $value ) ) {
		return false;
	} elseif ( 'boolean' === $type && ! is_bool( $value ) ) {
		return false;
	}
	return ! isset( $schema['enum'] ) || in_array( $value, $schema['enum'], true );
}

final class PromoterLinkPagesFixtureWpdb {
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
			if ( false !== strpos( $query, 'ec_link_page_ids' ) && is_callable( $GLOBALS['promoter_link_page_fixture']['revoke_on_page_lock'] ?? null ) ) {
				$callback = $GLOBALS['promoter_link_page_fixture']['revoke_on_page_lock'];
				unset( $GLOBALS['promoter_link_page_fixture']['revoke_on_page_lock'] );
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
$GLOBALS['wpdb'] = new PromoterLinkPagesFixtureWpdb();

function ec_get_blog_id( $site ) {
	return 'events' === $site ? 7 : 0; }
function get_current_user_id() {
	return (int) $GLOBALS['promoter_link_page_fixture']['user_id']; }
function get_term_meta( $term_id, $key, $single = false ) {
	return $GLOBALS['promoter_link_page_fixture']['term_meta'][ $term_id ][ $key ] ?? ''; }
function get_term_link( $term ) {
	return 'https://events.extrachill.com/' . $term->taxonomy . '/' . $term->slug . '/'; }
function get_home_url( $blog_id, $path = '/' ) {
	return ( 7 === (int) $blog_id ? 'https://events.extrachill.com' : 'https://artist.extrachill.com' ) . $path; }
function add_query_arg( $key, $value, $url ) {
	return $url . '?' . rawurlencode( $key ) . '=' . rawurlencode( (string) $value ); }
function trailingslashit( $value ) {
	return rtrim( $value, '/' ) . '/'; }
function data_machine_events_get_venue_profile( $term_id ) {
	$term = get_term( $term_id, 'venue' );
	return array(
		'term_id'     => $term_id,
		'name'        => $term->name,
		'description' => $term->description,
		'city'        => 'Charleston',
		'state'       => 'SC',
		'revision'    => 'venue-v1',
	);
}
function data_machine_events_get_promoter_data( $term_id ) {
	$term = get_term( $term_id, 'promoter' );
	return array(
		'term_id'     => $term_id,
		'name'        => $term->name,
		'slug'        => $term->slug,
		'description' => $term->description,
		'url'         => get_term_meta( $term_id, '_promoter_url', true ),
	);
}

require_once dirname( __DIR__, 2 ) . '/inc/Core/VenueLinkPages.php';
require_once dirname( __DIR__, 2 ) . '/inc/Core/PromoterLinkPages.php';
require_once dirname( __DIR__, 2 ) . '/inc/Abilities/PromoterLinkPageAbilities.php';

use ExtraChillEvents\Core\PromoterAuthorityRepository;
use ExtraChillEvents\Core\PromoterLinkPages;
use ExtraChillEvents\Core\VenueLinkPages;

$organization                          = static function ( $term_id, $status = PromoterAuthorityRepository::STATUS_ACTIVE, $version = 1 ) {
	return array(
		'promoter_term_id' => $term_id,
		'status'           => $status,
		'version'          => $version,
		'updated_at'       => '2026-08-24 12:00:00',
	);
};
$GLOBALS['promoter_link_page_fixture'] = array(
	'user_id'       => 10,
	'organizations' => array(
		30 => $organization( 30 ),
		31 => $organization( 31 ),
	),
	'memberships'   => array(
		'10:30' => true,
		'12:31' => true,
	),
	'feature'       => array(
		10 => true,
		12 => true,
		1  => true,
		20 => true,
		21 => true,
		22 => true,
	),
	'term_meta'     => array(
		30 => array( '_promoter_url' => 'https://extrachill.com' ),
		31 => array( '_promoter_url' => 'https://other.example' ),
	),
);
$GLOBALS['venue_link_page_fixture']    = array(
	'user_id'     => 20,
	'memberships' => array( '20:50' => true ),
	'revision'    => 'venue-v1',
	'term_meta'   => array(),
);

foreach ( array(
	30 => array( 'promoter', 'Extra Chill', 'extra-chill', 'Independent music promoter.' ),
	31 => array( 'promoter', 'Other Promoter', 'other-promoter', 'Other promoter.' ),
	32 => array( 'promoter', 'Imported Scraper', 'imported-scraper', 'Unverified.' ),
	33 => array( 'promoter', 'Failed Promoter', 'failed-promoter', 'Must not persist.' ),
	50 => array( 'venue', 'The Royal American', 'the-royal-american', 'Venue.' ),
	90 => array( 'place', 'Collision', 'extra-chill', '' ),
) as $term_id => $term_data ) {
	$GLOBALS['ec_test']['blogs'][7]['terms'][ $term_id ] = (object) array(
		'term_id'     => $term_id,
		'taxonomy'    => $term_data[0],
		'name'        => $term_data[1],
		'slug'        => $term_data[2],
		'description' => $term_data[3],
	);
}

ec_register_link_page_owner_compatibility_provider( 'events-venues', array( VenueLinkPages::class, 'compatibility_provider' ) );
ec_register_link_page_operation_provider( 'events-venues', array( VenueLinkPages::class, 'operation_provider' ) );
ec_register_link_page_public_projection_provider( 'events-venues', array( VenueLinkPages::class, 'public_projection_provider' ) );
ec_register_link_page_owner_compatibility_provider( 'events-promoters', array( PromoterLinkPages::class, 'compatibility_provider' ) );
ec_register_link_page_operation_provider( 'events-promoters', array( PromoterLinkPages::class, 'operation_provider' ) );
ec_register_link_page_public_projection_provider( 'events-promoters', array( PromoterLinkPages::class, 'public_projection_provider' ) );
PromoterLinkPages::register_hooks();
$ability_registrar = new ExtraChillEvents\Abilities\PromoterLinkPageAbilities();
$ability_registrar->register();
add_filter(
	'extrachill_get_link_page_analytics',
	static function ( $value, $link_page_id ) {
		$GLOBALS['promoter_link_page_fixture']['analytics_blog'] = get_current_blog_id();
		return array(
			'link_page_id' => $link_page_id,
			'summary'      => array( 'total_views' => 9 ),
		);
	},
	20,
	2
);
add_action(
	'ec_link_page_save',
	static function () {
		if ( ! empty( $GLOBALS['promoter_link_page_fixture']['throw_final_hook'] ) ) {
			throw new RuntimeException( 'Simulated final hook failure.' );
		}
	},
	99
);
$GLOBALS['ec_test']['execute_actions'] = true;

$success_hook_counts = static function (): array {
	$hooks = array_column( $GLOBALS['ec_test']['fired_actions'] ?? array(), 0 );
	return array(
		'legacy_save' => count( array_keys( $hooks, 'ec_link_page_save', true ) ),
		'generic_save' => count( array_keys( $hooks, 'ec_link_page_persistence_saved', true ) ),
		'generic_create' => count( array_keys( $hooks, 'ec_owned_link_page_created', true ) ),
	);
};
$hook_delta = static function ( array $before, array $after ): array {
	$delta = array();
	foreach ( $after as $key => $count ) {
		$delta[ $key ] = $count - $before[ $key ];
	}
	return $delta;
};

switch_to_blog( 7 );
$hooks_before_created = $success_hook_counts();
$created      = PromoterLinkPages::provision( 30 );
$created_hooks = $hook_delta( $hooks_before_created, $success_hook_counts() );
$page_id      = is_wp_error( $created ) ? 0 : (int) $created['link_page']['link_page_id'];
$hooks_before_winner = $success_hook_counts();
$winner       = PromoterLinkPages::provision( 30 );
$winner_hooks = $hook_delta( $hooks_before_winner, $success_hook_counts() );
$read         = $page_id ? ec_read_link_page( PromoterLinkPages::owner_reference( 30 ) ) : $created;
$hooks_before_saved = $success_hook_counts();
$saved        = $page_id ? ec_save_link_page( PromoterLinkPages::owner_reference( 30 ), array( 'bio' => 'Promoter managed bio.' ) ) : $created;
$saved_hooks  = $hook_delta( $hooks_before_saved, $success_hook_counts() );
$flat_saved   = $page_id ? ec_save_link_page(
	PromoterLinkPages::owner_reference( 30 ),
	array(
		'links' => array(
			array(
				'link_text' => 'Extra Chill',
				'link_url'  => 'https://extrachill.com',
			),
		),
	)
) : $created;
$flat_output_valid = ! is_wp_error( $flat_saved ) && promoter_link_page_fixture_schema_valid( $flat_saved, $GLOBALS['promoter_link_page_abilities']['extrachill/get-promoter-link-page']['output_schema'] );
$analytics    = $page_id ? PromoterLinkPages::analytics( 30 ) : $created;
$caller_after = get_current_blog_id();
$discovery    = PromoterLinkPages::approved_promoters();
$unverified   = PromoterLinkPages::provision( 32 );

$denials = array();
foreach ( array( 1, 20, 21, 22, 12 ) as $user_id ) {
	$GLOBALS['promoter_link_page_fixture']['user_id'] = $user_id;
	$attempt             = $page_id ? ec_read_link_page( PromoterLinkPages::owner_reference( 30 ) ) : $created;
	$denials[ $user_id ] = is_wp_error( $attempt ) ? $attempt->get_error_code() : '';
}
$GLOBALS['promoter_link_page_fixture']['user_id'] = 10;

$GLOBALS['promoter_link_page_fixture']['principal_user_id'] = 10;
$GLOBALS['promoter_link_page_fixture']['user_id']            = 20;
$principal_allowed = ec_read_link_page( PromoterLinkPages::owner_reference( 30 ) );
$GLOBALS['promoter_link_page_fixture']['principal_user_id'] = 20;
$GLOBALS['promoter_link_page_fixture']['user_id']            = 10;
$principal_denied = ec_read_link_page( PromoterLinkPages::owner_reference( 30 ) );
$GLOBALS['promoter_link_page_fixture']['principal_user_id'] = 0;
$principal_anonymous = ec_read_link_page( PromoterLinkPages::owner_reference( 30 ) );
unset( $GLOBALS['promoter_link_page_fixture']['principal_user_id'] );
$principal_fallback = ec_read_link_page( PromoterLinkPages::owner_reference( 30 ) );

$GLOBALS['promoter_link_page_fixture']['throw_final_hook'] = true;
$failed_final_hook = ec_save_link_page( PromoterLinkPages::owner_reference( 30 ), array( 'bio' => 'Must compensate final hook.' ) );
unset( $GLOBALS['promoter_link_page_fixture']['throw_final_hook'] );
$bio_after_final_hook = ec_with_link_page_storage_blog(
	static function () use ( $page_id ) {
		return get_post_meta( $page_id, '_link_page_bio_text', true );
	}
);

$GLOBALS['promoter_link_page_fixture']['revoke_on_page_lock'] = static function () {
	unset( $GLOBALS['promoter_link_page_fixture']['memberships']['10:30'] );
};
$revoked_during_lock = PromoterLinkPages::refresh_snapshot( 30 );
$GLOBALS['promoter_link_page_fixture']['memberships']['10:30'] = true;

$GLOBALS['promoter_link_page_fixture']['revoke_on_authority_lock'] = static function () {
	$GLOBALS['promoter_link_page_fixture']['organizations'][30]['status'] = PromoterAuthorityRepository::STATUS_REVOKED;
};
$revoked_before_lock = ec_save_link_page( PromoterLinkPages::owner_reference( 30 ), array( 'bio' => 'Must not write after revocation.' ) );
$GLOBALS['promoter_link_page_fixture']['organizations'][30]['status'] = PromoterAuthorityRepository::STATUS_ACTIVE;

$GLOBALS['promoter_link_page_fixture']['organizations'][30]['version']    = 2;
$GLOBALS['promoter_link_page_fixture']['organizations'][30]['updated_at'] = '2026-08-24 12:30:00';
$before_meta                                 = $GLOBALS['ec_test']['meta_write_calls'];
$GLOBALS['ec_test']['fail_meta_write_calls'] = array( $before_meta + 2 );
$hooks_before_failed_save                    = $success_hook_counts();
$failed_save                                 = ec_save_link_page( PromoterLinkPages::owner_reference( 30 ), array( 'bio' => 'Must roll back.' ) );
$failed_save_hooks                           = $hook_delta( $hooks_before_failed_save, $success_hook_counts() );
$GLOBALS['ec_test']['fail_meta_write_calls'] = array();

$GLOBALS['promoter_link_page_fixture']['organizations'][33] = $organization( 33 );
$GLOBALS['promoter_link_page_fixture']['memberships']['10:33'] = true;
$before_meta = $GLOBALS['ec_test']['meta_write_calls'];
$GLOBALS['ec_test']['fail_meta_write_calls'] = array( $before_meta + 2 );
$hooks_before_failed_creation = $success_hook_counts();
$failed_creation = PromoterLinkPages::provision( 33 );
$failed_creation_hooks = $hook_delta( $hooks_before_failed_creation, $success_hook_counts() );
$GLOBALS['ec_test']['fail_meta_write_calls'] = array();
$failed_creation_reference = PromoterLinkPages::owner_reference( 33 );
$failed_creation_page = ec_get_link_page_id_for_owner( $failed_creation_reference );
$failed_meta_delta                           = $GLOBALS['ec_test']['meta_write_calls'] - $before_meta;
$bio_after_failure                           = ec_with_link_page_storage_blog(
	static function () use ( $page_id ) {
		return get_post_meta( $page_id, '_link_page_bio_text', true );
	}
);

$GLOBALS['promoter_link_page_fixture']['organizations'][30]['version']    = 3;
$GLOBALS['promoter_link_page_fixture']['organizations'][30]['updated_at'] = '2026-08-24 13:00:00';
$GLOBALS['promoter_link_page_fixture']['user_id']                         = 0;
switch_to_blog( 4 );
PromoterLinkPages::refresh_after_profile_update( 30 );
restore_current_blog();
$cross_blog_snapshot = ec_with_link_page_storage_blog(
	static function () use ( $page_id ) {
		return get_post_meta( $page_id, PromoterLinkPages::SNAPSHOT_META_KEY, true );
	}
);
PromoterLinkPages::refresh_after_profile_update( 30 );
$GLOBALS['promoter_link_page_fixture']['user_id'] = 10;
$trusted_snapshot                                 = ec_with_link_page_storage_blog(
	static function () use ( $page_id ) {
		return get_post_meta( $page_id, PromoterLinkPages::SNAPSHOT_META_KEY, true );
	}
);

$GLOBALS['promoter_link_page_fixture']['user_id']                         = 10;
$GLOBALS['promoter_link_page_fixture']['organizations'][30]['version']    = 4;
$GLOBALS['promoter_link_page_fixture']['organizations'][30]['updated_at'] = '2026-08-24 13:30:00';
$GLOBALS['ec_test']['blogs'][7]['terms'][30]->description                 = 'Changed public promoter description.';
$before_meta = $GLOBALS['ec_test']['meta_write_calls'];
$GLOBALS['ec_test']['fail_meta_write_calls'] = array( $before_meta + 2, $before_meta + 3 );
$refresh_compensation = PromoterLinkPages::refresh_snapshot( 30 );
$GLOBALS['ec_test']['fail_meta_write_calls'] = array();

$GLOBALS['promoter_link_page_fixture']['organizations'][30]['version']    = 5;
$GLOBALS['promoter_link_page_fixture']['organizations'][30]['updated_at'] = '2026-08-24 14:00:00';
$before_meta = $GLOBALS['ec_test']['meta_write_calls'];
$GLOBALS['ec_test']['fail_meta_write_calls'] = array( $before_meta + 2, $before_meta + 3 );
$provision_compensation = PromoterLinkPages::provision( 30 );
$GLOBALS['ec_test']['fail_meta_write_calls'] = array();

$GLOBALS['promoter_link_page_fixture']['memberships']['10:30'] = false;
do_action( 'extrachill_events_promoter_authority_changed', 30, 'membership_revoked' );
$membership_status = ec_with_link_page_storage_blog(
	static function () use ( $page_id ) {
		return get_post_field( 'post_status', $page_id );
	}
);
$GLOBALS['promoter_link_page_fixture']['memberships']['10:30'] = true;

restore_current_blog();
$projection_registry = ec_link_page_public_projection_registry();
$reflection          = new ReflectionObject( $projection_registry );
$property            = $reflection->getProperty( 'providers' );
$property->setAccessible( true );
$property->setValue( $projection_registry, array() );
$stored_projection = ec_get_link_page_public_projection( $page_id );

switch_to_blog( 7 );
$GLOBALS['promoter_link_page_fixture']['organizations'][30]['status'] = PromoterAuthorityRepository::STATUS_REVOKED;
$revocation_withdrawal = PromoterLinkPages::authority_precommit( true, 30, 'organization_revoked' );
PromoterLinkPages::authority_changed( 30, 'organization_revoked' );
$revoked_status = ec_with_link_page_storage_blog(
	static function () use ( $page_id ) {
		return get_post_field( 'post_status', $page_id );
	}
);
$GLOBALS['promoter_link_page_fixture']['organizations'][30]['status'] = PromoterAuthorityRepository::STATUS_ACTIVE;
do_action( 'extrachill_events_promoter_authority_changed', 30, 'organization_verified' );
$reverified_status = ec_with_link_page_storage_blog(
	static function () use ( $page_id ) {
		return get_post_field( 'post_status', $page_id );
	}
);

$GLOBALS['promoter_link_page_fixture']['user_id'] = 12;
$second         = PromoterLinkPages::provision( 31 );
$second_page_id = is_wp_error( $second ) ? 0 : (int) $second['link_page']['link_page_id'];
switch_to_blog( 4 );
$GLOBALS['ec_test']['blogs'][4]['terms'][31] = (object) array(
	'term_id'     => 31,
	'taxonomy'    => 'promoter',
	'name'        => 'Cross-blog Collision',
	'slug'        => 'cross-blog-collision',
	'description' => 'Must not affect Events.',
);
PromoterLinkPages::capture_before_term_deletion( 31, 'promoter' );
PromoterLinkPages::orphan_after_term_deletion( 31, 311, 'promoter', $GLOBALS['ec_test']['blogs'][4]['terms'][31] );
$cross_blog_deleted_status = ec_with_link_page_storage_blog(
	static function () use ( $second_page_id ) {
		return get_post_field( 'post_status', $second_page_id );
	}
);
$cross_blog_deleted_audit = ec_with_link_page_storage_blog(
	static function () use ( $second_page_id ) {
		return get_post_meta( $second_page_id, PromoterLinkPages::ORPHAN_META_KEY, true );
	}
);
restore_current_blog();
PromoterLinkPages::capture_before_term_deletion( 31, 'promoter' );
$deleted_term = $GLOBALS['ec_test']['blogs'][7]['terms'][31];
unset( $GLOBALS['ec_test']['blogs'][7]['terms'][31] );
PromoterLinkPages::orphan_after_term_deletion( 31, 310, 'promoter', $deleted_term );
$deleted_status = ec_with_link_page_storage_blog(
	static function () use ( $second_page_id ) {
		return get_post_field( 'post_status', $second_page_id );
	}
);
$deleted_audit  = ec_with_link_page_storage_blog(
	static function () use ( $second_page_id ) {
		return get_post_meta( $second_page_id, PromoterLinkPages::ORPHAN_META_KEY, true );
	}
);
restore_current_blog();

$registries = array();
foreach ( array( ec_link_page_owner_compatibility_registry(), ec_link_page_operation_provider_registry() ) as $registry ) {
	$reflection = new ReflectionObject( $registry );
	$providers  = $reflection->getProperty( 'providers' );
	$providers->setAccessible( true );
	$registries[] = array_keys( $providers->getValue( $registry ) );
}
$management_url   = PromoterLinkPages::management_url( 30 );
$management_parts = wp_parse_url( $management_url );
parse_str( (string) ( $management_parts['query'] ?? '' ), $management_query );

echo wp_json_encode(
	array(
		'created'             => ! is_wp_error( $created ),
		'created_hooks'       => $created_hooks,
		'winner_created'      => is_wp_error( $winner ) ? null : $winner['created'],
		'winner_hooks'        => $winner_hooks,
		'owner'               => ec_get_stored_link_page_owner_references( $page_id ),
		'read'                => ! is_wp_error( $read ) && 30 === $read['promoter']['term_id'],
		'saved'               => ! is_wp_error( $saved ) && 'Promoter managed bio.' === $saved['link_page']['bio'],
		'saved_hooks'         => $saved_hooks,
		'flat_output_valid'   => $flat_output_valid,
		'analytics'           => ! is_wp_error( $analytics ) && 9 === $analytics['summary']['total_views'],
		'analytics_blog'      => $GLOBALS['promoter_link_page_fixture']['analytics_blog'] ?? 0,
		'caller_after'        => $caller_after,
		'denials'             => $denials,
		'principals'          => array(
			'allowed'   => ! is_wp_error( $principal_allowed ) && 30 === $principal_allowed['promoter']['term_id'],
			'denied'    => is_wp_error( $principal_denied ) ? $principal_denied->get_error_code() : '',
			'anonymous' => is_wp_error( $principal_anonymous ) ? $principal_anonymous->get_error_code() : '',
			'fallback'  => ! is_wp_error( $principal_fallback ) && 30 === $principal_fallback['promoter']['term_id'],
		),
		'final_hook'          => array(
			'error' => is_wp_error( $failed_final_hook ) ? $failed_final_hook->get_error_code() : '',
			'bio'   => $bio_after_final_hook,
		),
		'unverified'          => is_wp_error( $unverified ) ? $unverified->get_error_code() : '',
		'discovery'           => $discovery,
		'revoked_during_lock' => is_wp_error( $revoked_during_lock ) ? $revoked_during_lock->get_error_code() : '',
		'revoked_before_lock' => is_wp_error( $revoked_before_lock ) ? $revoked_before_lock->get_error_code() : '',
		'revocation_withdrawal' => true === $revocation_withdrawal,
		'rollback'            => array(
			'error'  => is_wp_error( $failed_save ) ? $failed_save->get_error_code() : '',
			'bio'    => $bio_after_failure,
			'writes' => $failed_meta_delta,
			'hooks'  => $failed_save_hooks,
		),
		'failed_creation'     => array(
			'error'   => is_wp_error( $failed_creation ) ? $failed_creation->get_error_code() : '',
			'page_id' => is_wp_error( $failed_creation_page ) ? -1 : (int) $failed_creation_page,
			'hooks'   => $failed_creation_hooks,
		),
		'trusted_version'     => $trusted_snapshot['source']['version'] ?? '',
		'refresh_compensation' => is_wp_error( $refresh_compensation ) ? $refresh_compensation->get_error_code() : '',
		'provision_compensation' => is_wp_error( $provision_compensation ) ? $provision_compensation->get_error_code() : '',
		'cross_blog_refresh_version' => $cross_blog_snapshot['source']['version'] ?? '',
		'principal_context'   => $GLOBALS['promoter_link_page_fixture']['principal_context'] ?? array(),
		'membership_status'   => $membership_status,
		'stored_projection'   => is_wp_error( $stored_projection ) ? array( 'error' => $stored_projection->get_error_code() ) : $stored_projection,
		'revoked_status'      => $revoked_status,
		'reverified_status'   => $reverified_status,
		'deleted_status'      => $deleted_status,
		'cross_blog_deleted_status' => $cross_blog_deleted_status,
		'cross_blog_deleted_audit' => $cross_blog_deleted_audit,
		'deleted_audit'       => $deleted_audit,
		'registries'          => $registries,
		'management_route'    => array(
			'path'     => $management_parts['path'] ?? '',
			'identity' => $management_query['identity'] ?? '',
			'fragment' => $management_parts['fragment'] ?? '',
		),
		'http_functions'      => array( function_exists( 'wp_remote_get' ), function_exists( 'wp_remote_post' ) ),
	)
);
