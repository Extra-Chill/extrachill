<?php
/** Standalone contract test for subscription inventory enrichment. */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

class WP_Error {}

class Subscription_Test_Ability {
	private $callback;

	public function __construct( $callback ) {
		$this->callback = $callback;
	}

	public function execute( $input ) {
		return call_user_func( $this->callback, $input );
	}
}

function subscription_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

function add_action() {}
function wp_register_ability( $name, $definition ) {
	$GLOBALS['_test_registered_abilities'][ $name ] = $definition;
}
function wp_get_ability( $name ) {
	return $GLOBALS['_test_owner_abilities'][ $name ] ?? null;
}
function __( $text ) {
	return $text;
}
function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}
function wp_http_validate_url( $url ) {
	return is_string( $url ) && 1 === preg_match( '#^https?://[^/]+/.+#', $url );
}

function install_owner( $name, $callback ) {
	$GLOBALS['_test_owner_abilities'][ $name ] = new Subscription_Test_Ability( $callback );
}

function owner_items( $input ) {
	$items = $input['items'];
	return array(
		'schema_version' => '1',
		'items'          => array_map(
			static function ( $item ) {
				$missing = 'missing' === $item['slug'];
				return array(
					'entity_type' => $item['entity_type'],
					'slug'        => $item['slug'],
					'status'      => $missing ? 'not_found' : 'resolved',
					'name'        => $missing ? '' : ucwords( str_replace( '-', ' ', $item['slug'] ) ),
					'url'         => $missing ? '' : 'https://example.com/' . $item['slug'] . '/',
				);
			},
			$items
		),
	);
}

require __DIR__ . '/../inc/core/subscription-inventory.php';

extrachill_community_register_subscription_inventory_ability();
$ability = $GLOBALS['_test_registered_abilities']['extrachill/community-resolve-subscription-entities'] ?? null;

subscription_assert( is_array( $ability ), 'Community ability must register.' );
subscription_assert( 'is_user_logged_in' === $ability['permission_callback'], 'Inventory must remain self-only.' );
subscription_assert( false === $ability['input_schema']['additionalProperties'], 'Input envelope must be exact.' );
subscription_assert( array( 'schema_version', 'identities' ) === $ability['input_schema']['required'], 'Input fields must be required.' );
subscription_assert( 1 === $ability['input_schema']['properties']['identities']['minItems'], 'Input must reject empty batches.' );
subscription_assert( 100 === $ability['input_schema']['properties']['identities']['maxItems'], 'Input must enforce the owner batch bound.' );
subscription_assert( true === $ability['input_schema']['properties']['identities']['uniqueItems'], 'Input must reject duplicate stored identities.' );
subscription_assert( 5 === count( $ability['input_schema']['properties']['identities']['items']['oneOf'] ), 'Every supported identity pair must be enumerated.' );
subscription_assert( false === $ability['output_schema']['additionalProperties'], 'Output envelope must be exact.' );
subscription_assert( 25 === count( $ability['output_schema']['properties']['entities']['items']['oneOf'] ), 'Every identity and failure status must have an exact output variant.' );

install_owner(
	'extrachill/artist-public-projections',
	static function ( $input ) {
		return array(
			'schema_version' => '1',
			'items'          => array_map(
				static function ( $slug ) {
					return array(
						'slug'   => $slug,
						'status' => 'resolved',
						'name'   => ucwords( str_replace( '-', ' ', $slug ) ),
						'url'    => 'https://artist.example.com/' . $slug . '/',
					);
				},
				$input['slugs']
			),
		);
	}
);
install_owner( 'extrachill/events-public-entity-projections', 'owner_items' );
install_owner( 'extrachill/blog-public-entity-projections', 'owner_items' );

$identities = array(
	array( 'entity_type' => 'venue', 'taxonomy' => 'venue', 'slug' => 'music-farm' ),
	array( 'entity_type' => 'artist', 'taxonomy' => 'artist', 'slug' => 'kid-lake' ),
	array( 'entity_type' => 'location', 'taxonomy' => 'location', 'slug' => 'missing' ),
	array( 'entity_type' => 'local_scene_digest', 'taxonomy' => 'location', 'slug' => 'charleston-sc' ),
	array( 'entity_type' => 'festival', 'taxonomy' => 'festival', 'slug' => 'high-water' ),
);
$result = extrachill_community_resolve_subscription_entities(
	array(
		'schema_version' => '1',
		'identities'     => $identities,
	)
);

subscription_assert( '1' === $result['schema_version'], 'Output must declare schema version one.' );
subscription_assert( array_column( $identities, 'slug' ) === array_column( $result['entities'], 'slug' ), 'Mixed owner batches must preserve input order.' );
subscription_assert( 'resolved' === $result['entities'][0]['status'] && true === $result['entities'][0]['resolved'], 'Venue must resolve through Events.' );
subscription_assert( 'https://artist.example.com/kid-lake/' === $result['entities'][1]['url'], 'Artist must preserve owner presentation.' );
subscription_assert( 'not_found' === $result['entities'][2]['status'] && false === $result['entities'][2]['resolved'], 'Stale entities must preserve owner not_found semantics.' );
subscription_assert( 'resolved' === $result['entities'][3]['status'], 'Local Scene digest must resolve through Events.' );
subscription_assert( 'resolved' === $result['entities'][4]['status'], 'Festival must resolve through Blog.' );

unset( $GLOBALS['_test_owner_abilities']['extrachill/artist-public-projections'] );
$unavailable = extrachill_community_resolve_subscription_entities(
	array(
		'schema_version' => '1',
		'identities'     => array( $identities[1] ),
	)
);
subscription_assert( 'provider_unavailable' === $unavailable['entities'][0]['status'], 'Missing owner ability must be explicit.' );

install_owner( 'extrachill/artist-public-projections', static fn () => new WP_Error() );
$failed = extrachill_community_resolve_subscription_entities(
	array(
		'schema_version' => '1',
		'identities'     => array( $identities[1] ),
	)
);
subscription_assert( 'provider_error' === $failed['entities'][0]['status'], 'Owner WP_Error must be explicit.' );

install_owner(
	'extrachill/artist-public-projections',
	static fn () => array(
		'schema_version' => '2',
		'items'          => array(),
	)
);
$malformed = extrachill_community_resolve_subscription_entities(
	array(
		'schema_version' => '1',
		'identities'     => array( $identities[1] ),
	)
);
subscription_assert( 'malformed_response' === $malformed['entities'][0]['status'], 'Malformed owner envelopes must be explicit.' );

install_owner(
	'extrachill/events-public-entity-projections',
	static fn ( $input ) => array(
		'schema_version' => '1',
		'items'          => array(
			array(
				'entity_type' => $input['items'][0]['entity_type'],
				'slug'        => 'wrong-order',
				'status'      => 'resolved',
				'name'        => 'Wrong',
				'url'         => 'https://example.com/wrong/',
			),
		),
	)
);
$malformed = extrachill_community_resolve_subscription_entities(
	array(
		'schema_version' => '1',
		'identities'     => array( $identities[0] ),
	)
);
subscription_assert( 'malformed_response' === $malformed['entities'][0]['status'], 'Owner rows must match requested identity and order.' );

$source = file_get_contents( __DIR__ . '/../inc/core/subscription-inventory.php' );
foreach ( array( 'switch_to_blog', 'get_term_by', 'get_term_link', 'get_permalink', '_artist_profile_id', "ec_get_blog_id" ) as $forbidden ) {
	subscription_assert( false === strpos( $source, $forbidden ), "Community must not contain foreign routing/storage literal {$forbidden}." );
}

fwrite( STDOUT, "PASS: Subscription inventory delegates exact ordered projections to domain owners.\n" );
