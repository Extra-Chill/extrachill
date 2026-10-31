<?php
/**
 * Presentation enrichment for the account subscription inventory.
 *
 * @package ExtraChillCommunity
 */

defined( 'ABSPATH' ) || exit;

const EXTRACHILL_COMMUNITY_SUBSCRIPTION_PROJECTION_SCHEMA_VERSION = '1';
const EXTRACHILL_COMMUNITY_SUBSCRIPTION_PROJECTION_MAX_ITEMS      = 100;

add_action( 'wp_abilities_api_init', 'extrachill_community_register_subscription_inventory_ability' );

/** Return one exact canonical subscription identity schema. */
function extrachill_community_subscription_identity_schema(): array {
	$slug  = array(
		'type'      => 'string',
		'minLength' => 1,
		'maxLength' => 200,
		'pattern'   => '^[a-z0-9]+(?:-[a-z0-9]+)*$',
	);
	$pairs = array(
		array( 'artist', 'artist' ),
		array( 'festival', 'festival' ),
		array( 'venue', 'venue' ),
		array( 'location', 'location' ),
		array( 'local_scene_digest', 'location' ),
	);

	return array(
		'oneOf' => array_map(
			static function ( array $pair ) use ( $slug ): array {
				return array(
					'type'                 => 'object',
					'required'             => array( 'entity_type', 'taxonomy', 'slug' ),
					'properties'           => array(
						'entity_type' => array(
							'type' => 'string',
							'enum' => array( $pair[0] ),
						),
						'taxonomy'    => array(
							'type' => 'string',
							'enum' => array( $pair[1] ),
						),
						'slug'        => $slug,
					),
					'additionalProperties' => false,
				);
			},
			$pairs
		),
	);
}

/** Return the exact version-one output row schema. */
function extrachill_community_subscription_projection_schema(): array {
	$identity = extrachill_community_subscription_identity_schema();
	$statuses = array( 'resolved', 'not_found', 'provider_unavailable', 'provider_error', 'malformed_response' );
	$variants = array();

	foreach ( $identity['oneOf'] as $identity_variant ) {
		foreach ( $statuses as $status ) {
			$properties             = $identity_variant['properties'];
			$resolved               = 'resolved' === $status;
			$properties['status']   = array(
				'type' => 'string',
				'enum' => array( $status ),
			);
			$properties['name']     = $resolved
				? array(
					'type'      => 'string',
					'minLength' => 1,
				)
				: array(
					'type'      => 'string',
					'maxLength' => 0,
				);
			$properties['url']      = $resolved
				? array(
					'type'   => 'string',
					'format' => 'uri',
				)
				: array(
					'type'      => 'string',
					'maxLength' => 0,
				);
			$properties['resolved'] = array(
				'type' => 'boolean',
				'enum' => array( $resolved ),
			);
			$variants[]             = array(
				'type'                 => 'object',
				'required'             => array( 'entity_type', 'taxonomy', 'slug', 'status', 'name', 'url', 'resolved' ),
				'properties'           => $properties,
				'additionalProperties' => false,
			);
		}
	}

	return array( 'oneOf' => $variants );
}

/** Register the self-only subscription presentation resolver. */
function extrachill_community_register_subscription_inventory_ability(): void {
	wp_register_ability(
		'extrachill/community-resolve-subscription-entities',
		array(
			'label'               => __( 'Resolve Subscription Entities', 'extra-chill-community' ),
			'description'         => __( 'Resolve canonical names and URLs for account subscription identities.', 'extra-chill-community' ),
			'category'            => 'extrachill-community',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'schema_version', 'identities' ),
				'properties'           => array(
					'schema_version' => array(
						'type' => 'string',
						'enum' => array( EXTRACHILL_COMMUNITY_SUBSCRIPTION_PROJECTION_SCHEMA_VERSION ),
					),
					'identities'     => array(
						'type'        => 'array',
						'minItems'    => 1,
						'maxItems'    => EXTRACHILL_COMMUNITY_SUBSCRIPTION_PROJECTION_MAX_ITEMS,
						'uniqueItems' => true,
						'items'       => extrachill_community_subscription_identity_schema(),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'schema_version', 'entities' ),
				'properties'           => array(
					'schema_version' => array(
						'type' => 'string',
						'enum' => array( EXTRACHILL_COMMUNITY_SUBSCRIPTION_PROJECTION_SCHEMA_VERSION ),
					),
					'entities'       => array(
						'type'     => 'array',
						'minItems' => 1,
						'maxItems' => EXTRACHILL_COMMUNITY_SUBSCRIPTION_PROJECTION_MAX_ITEMS,
						'items'    => extrachill_community_subscription_projection_schema(),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => 'extrachill_community_resolve_subscription_entities',
			'permission_callback' => 'is_user_logged_in',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => true,
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		)
	);
}

/** Return one unresolved presentation row without discarding its stored identity. */
function extrachill_community_unresolved_subscription_entity( array $identity, string $status ): array {
	return array(
		'entity_type' => $identity['entity_type'],
		'taxonomy'    => $identity['taxonomy'],
		'slug'        => $identity['slug'],
		'status'      => $status,
		'name'        => '',
		'url'         => '',
		'resolved'    => false,
	);
}

/** Validate an owner envelope and its exact ordered projection rows. */
function extrachill_community_validate_owner_projection( $response, array $expected, bool $artist = false ): bool {
	if ( ! is_array( $response ) || 2 !== count( $response ) || ! isset( $response['schema_version'], $response['items'] ) || '1' !== $response['schema_version'] || ! is_array( $response['items'] ) || ! array_is_list( $response['items'] ) || count( $expected ) !== count( $response['items'] ) ) {
		return false;
	}

	foreach ( $response['items'] as $index => $item ) {
		$keys = $artist ? array( 'slug', 'status', 'name', 'url' ) : array( 'entity_type', 'slug', 'status', 'name', 'url' );
		if ( ! is_array( $item ) || count( $keys ) !== count( $item ) || array() !== array_diff( $keys, array_keys( $item ) ) || $item['slug'] !== $expected[ $index ]['slug'] ) {
			return false;
		}
		if ( ! $artist && $item['entity_type'] !== $expected[ $index ]['entity_type'] ) {
			return false;
		}
		if ( ! in_array( $item['status'], array( 'resolved', 'not_found' ), true ) || ! is_string( $item['name'] ) || ! is_string( $item['url'] ) ) {
			return false;
		}
		if ( 'resolved' === $item['status'] ) {
			if ( '' === $item['name'] || ! wp_http_validate_url( $item['url'] ) ) {
				return false;
			}
		} elseif ( '' !== $item['name'] || '' !== $item['url'] ) {
			return false;
		}
	}

	return true;
}

/** Execute one owner ability and return an explicit Community projection status. */
function extrachill_community_execute_subscription_owner( string $ability_name, array $input, array $expected, bool $artist = false ): array {
	$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( $ability_name ) : null;
	if ( ! $ability ) {
		return array( 'status' => 'provider_unavailable' );
	}

	$response = $ability->execute( $input );
	if ( is_wp_error( $response ) ) {
		return array( 'status' => 'provider_error' );
	}
	if ( ! extrachill_community_validate_owner_projection( $response, $expected, $artist ) ) {
		return array( 'status' => 'malformed_response' );
	}

	return array(
		'status' => 'ok',
		'items'  => $response['items'],
	);
}

/** Apply one validated owner batch to the ordered Community result. */
function extrachill_community_apply_subscription_owner_result( array &$resolved, array $indexes, array $owner_result ): void {
	if ( 'ok' !== $owner_result['status'] ) {
		foreach ( $indexes as $index ) {
			$resolved[ $index ] = extrachill_community_unresolved_subscription_entity( $resolved[ $index ], $owner_result['status'] );
		}
		return;
	}

	foreach ( $indexes as $offset => $index ) {
		$owner_item         = $owner_result['items'][ $offset ];
		$resolved[ $index ] = array_merge(
			$resolved[ $index ],
			array(
				'status'   => $owner_item['status'],
				'name'     => $owner_item['name'],
				'url'      => $owner_item['url'],
				'resolved' => 'resolved' === $owner_item['status'],
			)
		);
	}
}

/**
 * Resolve stored subscription identities through their domain owners.
 *
 * @param array $input Validated ability input.
 * @return array
 */
function extrachill_community_resolve_subscription_entities( array $input ): array {
	$identities     = $input['identities'];
	$resolved       = $identities;
	$artist_slugs   = array();
	$artist_indexes = array();
	$event_items    = array();
	$event_indexes  = array();
	$blog_items     = array();
	$blog_indexes   = array();

	foreach ( $identities as $index => $identity ) {
		if ( 'artist' === $identity['entity_type'] ) {
			$artist_slugs[]   = $identity['slug'];
			$artist_indexes[] = $index;
		} elseif ( 'festival' === $identity['entity_type'] ) {
			$blog_items[]   = array(
				'entity_type' => $identity['entity_type'],
				'slug'        => $identity['slug'],
			);
			$blog_indexes[] = $index;
		} else {
			$event_items[]   = array(
				'entity_type' => $identity['entity_type'],
				'slug'        => $identity['slug'],
			);
			$event_indexes[] = $index;
		}
	}

	if ( $artist_slugs ) {
		$expected = array_map( static fn ( string $slug ): array => array( 'slug' => $slug ), $artist_slugs );
		$result   = extrachill_community_execute_subscription_owner(
			'extrachill/artist-public-projections',
			array(
				'schema_version' => '1',
				'slugs'          => $artist_slugs,
			),
			$expected,
			true
		);
		extrachill_community_apply_subscription_owner_result( $resolved, $artist_indexes, $result );
	}

	if ( $event_items ) {
		$result = extrachill_community_execute_subscription_owner(
			'extrachill/events-public-entity-projections',
			array(
				'schema_version' => '1',
				'items'          => $event_items,
			),
			$event_items
		);
		extrachill_community_apply_subscription_owner_result( $resolved, $event_indexes, $result );
	}

	if ( $blog_items ) {
		$result = extrachill_community_execute_subscription_owner(
			'extrachill/blog-public-entity-projections',
			array(
				'schema_version' => '1',
				'items'          => $blog_items,
			),
			$blog_items
		);
		extrachill_community_apply_subscription_owner_result( $resolved, $blog_indexes, $result );
	}

	ksort( $resolved );

	return array(
		'schema_version' => EXTRACHILL_COMMUNITY_SUBSCRIPTION_PROJECTION_SCHEMA_VERSION,
		'entities'       => array_values( $resolved ),
	);
}
