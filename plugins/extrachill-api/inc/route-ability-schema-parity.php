<?php
/**
 * Developer checks for REST adapters backed by WordPress abilities.
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compare a registered REST endpoint with a backing ability input schema.
 *
 * WordPress core projects JSON Schema properties with
 * rest_get_endpoint_args_for_schema(), but it does not compare an existing
 * route with an ability or represent transport-only differences. This helper
 * keeps that missing check local to this plugin.
 *
 * Exceptions are maps of dotted paths to non-empty reasons. Supported groups:
 * `ability_only`, `transport_only`, and `constraints`.
 *
 * @param array           $endpoint   Registered endpoint definition.
 * @param WP_Ability|null $ability    Backing ability, or null when unavailable.
 * @param array           $contract   Adapter contract.
 * @return array<int, array<string, string>> Parity findings.
 */
function extrachill_api_rest_ability_schema_findings( array $endpoint, $ability, array $contract ) {
	$findings    = array();
	$ability_id  = isset( $contract['ability'] ) && is_string( $contract['ability'] ) ? $contract['ability'] : '';
	$route       = isset( $contract['route'] ) && is_string( $contract['route'] ) ? $contract['route'] : '';
	$exceptions  = isset( $contract['exceptions'] ) && is_array( $contract['exceptions'] ) ? $contract['exceptions'] : array();
	$actual_args = isset( $endpoint['args'] ) && is_array( $endpoint['args'] ) ? $endpoint['args'] : array();

	foreach ( array( 'ability_only', 'transport_only', 'constraints' ) as $group ) {
		if ( ! isset( $exceptions[ $group ] ) ) {
			$exceptions[ $group ] = array();
		}
		if ( ! is_array( $exceptions[ $group ] ) ) {
			$findings[]           = extrachill_api_rest_ability_schema_finding( 'malformed_exception', $route, $ability_id, $group );
			$exceptions[ $group ] = array();
			continue;
		}
		foreach ( $exceptions[ $group ] as $path => $reason ) {
			if ( ! is_string( $path ) || '' === $path || ! is_string( $reason ) || '' === trim( $reason ) ) {
				$findings[] = extrachill_api_rest_ability_schema_finding( 'malformed_exception', $route, $ability_id, $group . '.' . (string) $path );
			}
		}
	}

	if ( ! $ability instanceof WP_Ability ) {
		$findings[] = extrachill_api_rest_ability_schema_finding( 'absent_ability', $route, $ability_id, $ability_id );
		return $findings;
	}

	$schema = $ability->get_input_schema();
	if ( array() === $schema ) {
		$schema = array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		);
	}
	if ( ! extrachill_api_rest_ability_schema_is_valid( $schema ) ) {
		$findings[] = extrachill_api_rest_ability_schema_finding( 'malformed_schema', $route, $ability_id, 'input_schema' );
		return $findings;
	}

	$expected_methods = extrachill_api_rest_ability_schema_methods( $contract['methods'] ?? array() );
	$actual_methods   = extrachill_api_rest_ability_schema_methods( $endpoint['methods'] ?? array() );
	if ( $expected_methods !== $actual_methods ) {
		$findings[] = extrachill_api_rest_ability_schema_finding( 'method_mismatch', $route, $ability_id, implode( ',', $actual_methods ) );
	}

	$schema['properties'] = $schema['properties'] ?? array();
	foreach ( $schema['required'] ?? array() as $required ) {
		$schema['properties'][ $required ]['required'] = true;
	}

	$projected      = rest_get_endpoint_args_for_schema( $schema, WP_REST_Server::CREATABLE );
	$ability_only   = array_keys( $exceptions['ability_only'] );
	$transport_only = array_keys( $exceptions['transport_only'] );

	foreach ( array_diff( array_keys( $projected ), array_keys( $actual_args ), $ability_only ) as $name ) {
		$findings[] = extrachill_api_rest_ability_schema_finding( 'missing_route_arg', $route, $ability_id, $name );
	}
	foreach ( array_diff( array_keys( $actual_args ), array_keys( $projected ), $transport_only ) as $name ) {
		$findings[] = extrachill_api_rest_ability_schema_finding( 'extra_route_arg', $route, $ability_id, $name );
	}

	foreach ( array_intersect( array_keys( $projected ), array_keys( $actual_args ) ) as $name ) {
		$expected = extrachill_api_rest_ability_schema_contract( $projected[ $name ] );
		$actual   = extrachill_api_rest_ability_schema_contract( $actual_args[ $name ] );
		$paths    = extrachill_api_rest_ability_schema_diff_paths( $expected, $actual, $name );

		foreach ( array_diff( $paths, array_keys( $exceptions['constraints'] ) ) as $path ) {
			$findings[] = extrachill_api_rest_ability_schema_finding( 'constraint_mismatch', $route, $ability_id, $path );
		}
	}

	$expected_additional = $schema['additionalProperties'] ?? true;
	$actual_additional   = $contract['additionalProperties'] ?? null;
	if ( extrachill_api_rest_ability_schema_canonicalize( $expected_additional ) !== extrachill_api_rest_ability_schema_canonicalize( $actual_additional )
		&& ! isset( $exceptions['constraints']['additionalProperties'] ) ) {
		$findings[] = extrachill_api_rest_ability_schema_finding( 'additional_properties_mismatch', $route, $ability_id, 'additionalProperties' );
	}

	return $findings;
}

/** Validate the object shape needed for route projection. */
function extrachill_api_rest_ability_schema_is_valid( array $schema ) {
	if ( 'object' !== ( $schema['type'] ?? null ) ) {
		return false;
	}
	if ( isset( $schema['properties'] ) && ! is_array( $schema['properties'] ) ) {
		return false;
	}
	if ( isset( $schema['additionalProperties'] ) && ! is_bool( $schema['additionalProperties'] ) && ! is_array( $schema['additionalProperties'] ) ) {
		return false;
	}
	if ( isset( $schema['required'] ) && ( ! is_array( $schema['required'] ) || array_filter( $schema['required'], static fn( $value ) => ! is_string( $value ) ) ) ) {
		return false;
	}
	if ( array_diff( $schema['required'] ?? array(), array_keys( $schema['properties'] ?? array() ) ) ) {
		return false;
	}

	return true;
}

/** Normalize REST method declarations for strict comparison. */
function extrachill_api_rest_ability_schema_methods( $methods ) {
	if ( is_string( $methods ) ) {
		$methods = explode( ',', $methods );
	} elseif ( is_array( $methods ) && ! array_is_list( $methods ) ) {
		$methods = array_keys( array_filter( $methods ) );
	}
	if ( ! is_array( $methods ) ) {
		return array();
	}

	$methods = array_values( array_unique( array_map( static fn( $method ) => strtoupper( trim( (string) $method ) ), $methods ) ) );
	sort( $methods );
	return $methods;
}

/** Keep only validation semantics shared by ability and REST schemas. */
function extrachill_api_rest_ability_schema_contract( array $schema ) {
	$keywords = array(
		'type',
		'enum',
		'const',
		'format',
		'minimum',
		'maximum',
		'exclusiveMinimum',
		'exclusiveMaximum',
		'multipleOf',
		'minLength',
		'maxLength',
		'pattern',
		'minItems',
		'maxItems',
		'uniqueItems',
		'items',
		'properties',
		'required',
		'additionalProperties',
		'minProperties',
		'maxProperties',
		'default',
	);

	$contract = array_intersect_key( $schema, array_flip( $keywords ) );
	if ( isset( $contract['required'] ) && false === $contract['required'] ) {
		unset( $contract['required'] );
	}

	return extrachill_api_rest_ability_schema_canonicalize( $contract );
}

/** Canonicalize unordered schema sets and associative maps. */
function extrachill_api_rest_ability_schema_canonicalize( $value, $key = '' ) {
	if ( ! is_array( $value ) ) {
		return $value;
	}

	foreach ( $value as $child_key => $child ) {
		$value[ $child_key ] = extrachill_api_rest_ability_schema_canonicalize( $child, (string) $child_key );
	}
	if ( array_is_list( $value ) ) {
		if ( in_array( $key, array( 'enum', 'required', 'type' ), true ) ) {
			usort( $value, static fn( $left, $right ) => strcmp( (string) wp_json_encode( $left ), (string) wp_json_encode( $right ) ) );
		}
		return $value;
	}

	ksort( $value );
	return $value;
}

/** Return dotted paths for every schema constraint difference. */
function extrachill_api_rest_ability_schema_diff_paths( $expected, $actual, $prefix ) {
	if ( ! is_array( $expected ) || ! is_array( $actual ) ) {
		return $expected === $actual ? array() : array( $prefix );
	}

	$paths = array();
	foreach ( array_unique( array_merge( array_keys( $expected ), array_keys( $actual ) ) ) as $key ) {
		$path = '' === $prefix ? (string) $key : $prefix . '.' . $key;
		if ( ! array_key_exists( $key, $expected ) || ! array_key_exists( $key, $actual ) ) {
			$paths[] = $path;
			continue;
		}
		$paths = array_merge( $paths, extrachill_api_rest_ability_schema_diff_paths( $expected[ $key ], $actual[ $key ], $path ) );
	}

	return $paths;
}

/** Build a stable finding payload. */
function extrachill_api_rest_ability_schema_finding( $code, $route, $ability, $path ) {
	return array(
		'code'    => (string) $code,
		'route'   => (string) $route,
		'ability' => (string) $ability,
		'path'    => (string) $path,
	);
}

/**
 * Discover ability-backed callbacks from this repository's registered routes.
 *
 * The convention is a literal ability name in the callback that executes it.
 * Dynamic names must be declared with an `_extrachill_abilities` endpoint key.
 * This intentionally scans only callbacks owned by this plugin.
 *
 * @param array|null $routes Optional registered route map.
 * @return array<int, array<string, mixed>> Adapter contracts with endpoints.
 */
function extrachill_api_rest_ability_adapter_manifest( $routes = null ) {
	$routes    = is_array( $routes ) ? $routes : rest_get_server()->get_routes( 'extrachill/v1' );
	$manifest  = array();
	$route_dir = wp_normalize_path( EXTRACHILL_API_PATH . 'inc/routes/' );

	foreach ( $routes as $route => $endpoints ) {
		foreach ( $endpoints as $endpoint ) {
			$callback = $endpoint['callback'] ?? null;
			if ( ! is_string( $callback ) || ! function_exists( $callback ) ) {
				continue;
			}

			$reflection = new ReflectionFunction( $callback );
			$file       = wp_normalize_path( (string) $reflection->getFileName() );
			if ( ! str_starts_with( $file, $route_dir ) ) {
				continue;
			}

			$lines  = file( $file );
			$source = is_array( $lines )
				? implode( '', array_slice( $lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1 ) )
				: '';
			preg_match_all( '~extrachill/[a-z0-9-]+~', $source, $matches );
			$abilities = array_values( array_unique( array_merge( $matches[0], (array) ( $endpoint['_extrachill_abilities'] ?? array() ) ) ) );

			foreach ( $abilities as $ability_name ) {
				$contract = array(
					'route'                => $route,
					'callback'             => $callback,
					'ability'              => $ability_name,
					'methods'              => extrachill_api_rest_ability_schema_methods( $endpoint['methods'] ?? array() ),
					'additionalProperties' => str_contains( $source, '->get_params(' ),
					'exceptions'           => array(),
				);

				/**
				 * Filters one repository-local route/ability adapter contract.
				 *
				 * @param array  $contract Adapter contract and documented exceptions.
				 * @param array  $endpoint Registered REST endpoint.
				 * @param string $source   Callback source used for adapter discovery.
				 */
				$contract   = apply_filters( 'extrachill_api_rest_ability_adapter_contract', $contract, $endpoint, $source );
				$manifest[] = array(
					'contract' => $contract,
					'endpoint' => $endpoint,
				);
			}
		}
	}

	return $manifest;
}

/** Audit every discovered API-owned adapter against the active ability registry. */
function extrachill_api_rest_ability_adapter_audit( $routes = null ) {
	$findings = array();
	foreach ( extrachill_api_rest_ability_adapter_manifest( $routes ) as $adapter ) {
		$name     = $adapter['contract']['ability'];
		$ability  = function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ? wp_get_ability( $name ) : null;
		$findings = array_merge(
			$findings,
			extrachill_api_rest_ability_schema_findings( $adapter['endpoint'], $ability, $adapter['contract'] )
		);
	}

	return $findings;
}
