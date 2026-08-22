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

/**
 * Validate the object shape needed for route projection.
 *
 * @param array $schema Ability input schema.
 * @return bool Whether the schema can be projected.
 */
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

/**
 * Normalize REST method declarations for strict comparison.
 *
 * @param mixed $methods REST method declaration.
 * @return string[] Normalized method names.
 */
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

/**
 * Keep only validation semantics shared by ability and REST schemas.
 *
 * @param array $schema Projected schema fragment.
 * @return array Canonical validation contract.
 */
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

/**
 * Canonicalize unordered schema sets and associative maps.
 *
 * @param mixed  $value Schema value.
 * @param string $key   Parent schema keyword.
 * @return mixed Canonical value.
 */
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

/**
 * Return dotted paths for every schema constraint difference.
 *
 * @param mixed  $expected Expected schema value.
 * @param mixed  $actual   Actual schema value.
 * @param string $prefix   Current dotted path.
 * @return string[] Difference paths.
 */
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

/**
 * Build a stable finding payload.
 *
 * @param string $code    Finding classification.
 * @param string $route   Registered REST route.
 * @param string $ability Backing ability name.
 * @param string $path    Schema field or metadata path.
 * @return array<string, string> Finding payload.
 */
function extrachill_api_rest_ability_schema_finding( $code, $route, $ability, $path ) {
	return array(
		'code'    => (string) $code,
		'route'   => (string) $route,
		'ability' => (string) $ability,
		'path'    => (string) $path,
	);
}

/**
 * Resolve a route through the same owner contract used by affinity dispatch.
 *
 * @param string $route Registered REST route.
 * @return string Site key.
 */
function extrachill_api_rest_ability_route_site( $route ) {
	if ( '/extrachill/v1/artists' === $route ) {
		return 'artist';
	}

	$site = function_exists( 'ec_get_route_site_affinity' ) ? ec_get_route_site_affinity( $route ) : null;
	return $site ? $site : 'main';
}

/**
 * Document deliberate field translations at API transport boundaries.
 *
 * @param array $contract Adapter contract.
 * @return array Adapter contract with field-level evidence.
 */
function extrachill_api_rest_ability_contract_exceptions( array $contract ) {
	$key = $contract['route'] . '|' . $contract['ability'];
	$map = array(
		'/extrachill/v1/event-submissions|extrachill/submit-event' => array(
			'ability_only'   => array( 'flyer' => 'Multipart flyer data is read from get_file_params(), not the scalar REST argument map.' ),
			'transport_only' => array( 'turnstile_response' => 'Turnstile is consumed by public-write admission before ability execution.' ),
		),
		'/extrachill/v1/network-media|extrachill/network-media-upload' => array(
			'ability_only' => array(
				'tmp_name' => 'Derived from the admitted multipart file payload.',
				'name'     => 'Derived from the admitted multipart file payload.',
				'type'     => 'Derived from the admitted multipart file payload.',
				'size'     => 'Derived from the admitted multipart file payload.',
			),
		),
		'/extrachill/v1/community/notifications|extrachill/get-notifications' => array(
			'ability_only'   => array(
				'user_id'  => 'Derived from the authenticated WordPress actor.',
				'per_page' => 'The public limit alias is translated to the canonical ability field.',
			),
			'transport_only' => array( 'limit' => 'Preserved public alias translated to the ability per_page field.' ),
		),
		'/extrachill/v1/community/notifications/mark-read|extrachill/mark-notifications-read' => array(
			'ability_only' => array(
				'user_id'         => 'Derived from the authenticated WordPress actor.',
				'notification_id' => 'Deliberately omitted to preserve the route\'s mark-all behavior.',
			),
		),
		'/extrachill/v1/users/me/profile|extrachill/get-user-profile' => array(
			'ability_only' => array( 'user_id' => 'Derived from the authenticated WordPress actor.' ),
		),
		'/extrachill/v1/users/onboarding|extrachill/get-onboarding-status' => array(
			'ability_only' => array( 'user_id' => 'Derived from the authenticated WordPress actor.' ),
		),
		'/extrachill/v1/users/onboarding|extrachill/complete-onboarding' => array(
			'ability_only' => array( 'user_id' => 'Derived from the authenticated WordPress actor.' ),
		),
		'/extrachill/v1/users/(?P<id>\d+)|extrachill/get-user-profile' => array(
			'ability_only'   => array( 'user_id' => 'The public path ID is translated to the canonical ability field.' ),
			'transport_only' => array( 'id' => 'Public path alias translated to user_id.' ),
		),
		'/extrachill/v1/newsletter/subscribe|extrachill/subscribe' => array(
			'transport_only' => array(
				'turnstile_response' => 'Turnstile is consumed before ability execution.',
				'emails'             => 'Legacy batch transport input is normalized before individual ability execution.',
				'source'             => 'Public attribution metadata is normalized into the subscription context.',
			),
		),
		'/extrachill/v1/admin/artist-access/(?P<user_id>\d+)/approve|extrachill/approve-artist-access' => array(
			'transport_only' => array( 'token' => 'Approval token is verified by the REST admission layer before ability execution.' ),
		),
		'/extrachill/v1/venues/(?P<venue>\d+)/booking-inquiries/follow-through/status|' . EXTRACHILL_API_BOOKING_STATUS_ABILITY => array(
			'ability_only'   => array( 'venue_term_id' => 'Derived exclusively from the trusted venue path segment before ability execution.' ),
			'transport_only' => array( 'venue' => 'The venue path segment is the signed route-affinity boundary and is verified against the internal ability result.' ),
		),
		'/extrachill/v1/venues/(?P<venue>\d+)/booking-inquiries/follow-through/correction|' . EXTRACHILL_API_BOOKING_CORRECTION_ABILITY => array(
			'ability_only'   => array( 'venue_term_id' => 'Derived exclusively from the trusted venue path segment before ability execution.' ),
			'transport_only' => array(
				'venue'              => 'The venue path segment is the signed route-affinity boundary and is verified against the internal ability result.',
				'turnstile_response' => 'Turnstile is consumed before ability execution and is never forwarded to Events.',
			),
		),
		'/extrachill/v1/venues/(?P<venue>\d+)/booking-inquiries/follow-through/withdrawal|' . EXTRACHILL_API_BOOKING_WITHDRAWAL_ABILITY => array(
			'ability_only'   => array( 'venue_term_id' => 'Derived exclusively from the trusted venue path segment before ability execution.' ),
			'transport_only' => array(
				'venue'              => 'The venue path segment is the signed route-affinity boundary and is verified against the internal ability result.',
				'turnstile_response' => 'Turnstile is consumed before ability execution and is never forwarded to Events.',
			),
		),
		'/extrachill/v1/venues/(?P<venue>\d+)/booking-inquiries/follow-through/receipt-recovery|' . EXTRACHILL_API_BOOKING_RECOVERY_ABILITY => array(
			'ability_only'   => array( 'venue_term_id' => 'Derived exclusively from the trusted venue path segment before ability execution.' ),
			'transport_only' => array(
				'venue'              => 'The venue path segment is the signed route-affinity boundary and is verified against the internal ability result.',
				'turnstile_response' => 'Turnstile and public-write admission are completed before ability execution.',
			),
		),
	);

	if ( isset( $map[ $key ] ) ) {
		$contract['exceptions'] = array_replace_recursive( $contract['exceptions'] ?? array(), $map[ $key ] );
	}

	return $contract;
}
add_filter( 'extrachill_api_rest_ability_adapter_contract', 'extrachill_api_rest_ability_contract_exceptions' );

/**
 * Resolve the configured site key for the active runtime without switching blogs.
 *
 * @param array $manifest Adapter manifest for the audit matrix.
 * @return string|null Site key when the runtime is represented.
 */
function extrachill_api_rest_ability_current_site( array $manifest = array() ) {
	if ( function_exists( 'extrachill_get_current_site_key' ) ) {
		return extrachill_get_current_site_key();
	}

	if ( ! function_exists( 'ec_get_blog_id' ) ) {
		return null;
	}

	$sites = array( 'main' );
	foreach ( $manifest as $adapter ) {
		$site = $adapter['contract']['site'] ?? null;
		if ( is_string( $site ) && '' !== $site ) {
			$sites[] = $site;
		}
	}

	$current_blog_id = get_current_blog_id();
	foreach ( array_unique( $sites ) as $site ) {
		if ( (int) ec_get_blog_id( $site ) === (int) $current_blog_id ) {
			return $site;
		}
	}

	return null;
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
					'site'                 => extrachill_api_rest_ability_route_site( $route ),
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

/**
 * Audit every discovered API-owned adapter against the active ability registry.
 *
 * @param array|null  $routes Optional registered route map.
 * @param string|null $site   Site key expected to own the active runtime.
 * @return array<int, array<string, string>> Structured audit records.
 */
function extrachill_api_rest_ability_adapter_audit( $routes = null, $site = null ) {
	$report       = array();
	$manifest     = extrachill_api_rest_ability_adapter_manifest( $routes );
	$current_site = extrachill_api_rest_ability_current_site( $manifest );
	$audit_site   = null === $site ? $current_site : $site;

	if ( ! is_string( $audit_site ) || '' === $audit_site || $audit_site !== $current_site ) {
		return array(
			extrachill_api_rest_ability_audit_record( 'wrong_site_context', '', '', 'site', (string) $current_site, (string) $audit_site, 'The requested audit site does not match the bootstrapped WordPress runtime.' ),
		);
	}

	foreach ( $manifest as $adapter ) {
		$contract   = $adapter['contract'];
		$name       = $contract['ability'];
		$owner_site = $contract['site'] ?? null;
		$ability    = function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ? wp_get_ability( $name ) : null;

		if ( ! is_string( $owner_site ) || '' === $owner_site || ! function_exists( 'ec_get_blog_id' ) || ! ec_get_blog_id( $owner_site ) ) {
			$report[] = extrachill_api_rest_ability_audit_record( 'malformed_affinity', $contract['route'], $name, 'site', $audit_site, (string) $owner_site, 'Route affinity must name a configured network site.' );
			continue;
		}

		if ( $owner_site !== $audit_site ) {
			if ( ! $ability instanceof WP_Ability ) {
				$report[] = extrachill_api_rest_ability_audit_record( 'expected_absence', $contract['route'], $name, $name, $audit_site, $owner_site, sprintf( 'The route affinity contract assigns this binding to the %s runtime.', $owner_site ) );
			}
			continue;
		}

		foreach ( extrachill_api_rest_ability_schema_findings( $adapter['endpoint'], $ability, $contract ) as $finding ) {
			$report[] = extrachill_api_rest_ability_audit_record( $finding['code'], $finding['route'], $finding['ability'], $finding['path'], $audit_site, $owner_site, 'No documented field exception or owner remediation is registered.' );
		}

		foreach ( $contract['exceptions'] ?? array() as $group => $exceptions ) {
			if ( ! is_array( $exceptions ) ) {
				continue;
			}
			foreach ( $exceptions as $path => $reason ) {
				if ( is_string( $path ) && '' !== $path && is_string( $reason ) && '' !== trim( $reason ) ) {
					$report[] = extrachill_api_rest_ability_audit_record( 'documented_exception', $contract['route'], $name, $group . '.' . $path, $audit_site, $owner_site, $reason );
				}
			}
		}
	}

	return $report;
}

/**
 * Build one concrete affinity audit record.
 *
 * @param string $code       Finding classification.
 * @param string $route      Registered REST route.
 * @param string $ability    Backing ability name.
 * @param string $path       Schema field or metadata path.
 * @param string $site       Audited runtime site key.
 * @param string $owner_site Route owner site key.
 * @param string $reason     Concrete classification reason.
 * @return array<string, string> Audit record.
 */
function extrachill_api_rest_ability_audit_record( $code, $route, $ability, $path, $site, $owner_site, $reason ) {
	return array(
		'code'       => (string) $code,
		'route'      => (string) $route,
		'ability'    => (string) $ability,
		'path'       => (string) $path,
		'site'       => (string) $site,
		'owner_site' => (string) $owner_site,
		'reason'     => (string) $reason,
	);
}

/** Expose the active-runtime audit to Homeboy and CI through WP-CLI. */
function extrachill_api_register_schema_parity_command() {
	WP_CLI::add_command(
		'extrachill-api schema-parity',
		static function () {
			$report      = extrachill_api_rest_ability_adapter_audit();
			$unexplained = array_filter( $report, static fn( $finding ) => ! in_array( $finding['code'], array( 'expected_absence', 'documented_exception' ), true ) );
			WP_CLI::line( (string) wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			if ( $unexplained ) {
				WP_CLI::halt( 1 );
			}
		}
	);
}

if ( defined( 'WP_CLI' ) ) {
	extrachill_api_register_schema_parity_command();
}
