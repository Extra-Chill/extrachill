<?php
/**
 * Shop route → ability adapter.
 *
 * Every `extrachill/v1/shop/*` seller route is a thin adapter over an
 * extrachill-shop ability: it maps the HTTP request to ability input, lets
 * the ability's own permission callback decide access (one rule, owned by
 * Shop: `extrachill_shop_can_manage_seller()`), and returns the ability
 * result. No seller or permission logic lives in this plugin.
 *
 * Sellers travel as `seller_type` (artist|venue|promoter) + `seller_id`.
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST args describing a seller reference.
 *
 * @param bool $required Whether the seller is required.
 * @return array
 */
function extrachill_api_shop_seller_args( $required = true ) {
	return array(
		'seller_type' => array(
			'required'          => $required,
			'type'              => 'string',
			'enum'              => array( 'artist', 'venue', 'promoter' ),
			'sanitize_callback' => 'sanitize_key',
		),
		'seller_id'   => array(
			'required'          => $required,
			'type'              => 'integer',
			'minimum'           => 1,
			'sanitize_callback' => 'absint',
		),
	);
}

/**
 * Ability input `seller` from a request, or null when the request names none.
 *
 * @param WP_REST_Request $request Request.
 * @return array{type: string, id: int}|null
 */
function extrachill_api_shop_request_seller( WP_REST_Request $request ) {
	if ( null === $request->get_param( 'seller_type' ) && null === $request->get_param( 'seller_id' ) ) {
		return null;
	}
	return array(
		'type' => sanitize_key( (string) $request->get_param( 'seller_type' ) ),
		'id'   => absint( $request->get_param( 'seller_id' ) ),
	);
}

/**
 * Build ability input from a request: the seller plus the listed params that were supplied.
 *
 * @param WP_REST_Request $request Request.
 * @param string[]        $params  Request params to forward when present.
 * @return array
 */
function extrachill_api_shop_ability_input( WP_REST_Request $request, array $params = array() ) {
	$input  = array();
	$seller = extrachill_api_shop_request_seller( $request );
	if ( null !== $seller ) {
		$input['seller'] = $seller;
	}
	foreach ( $params as $param ) {
		$value = $request->get_param( $param );
		if ( null !== $value ) {
			$input[ $param ] = $value;
		}
	}
	return $input;
}

/**
 * Resolve a Shop ability.
 *
 * @param string $name Ability name.
 * @return WP_Ability|WP_Error
 */
function extrachill_api_shop_get_ability( $name ) {
	$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( $name ) : null;
	return $ability ? $ability : new WP_Error( 'ability_not_found', 'extrachill-shop plugin is required.', array( 'status' => 500 ) );
}

/**
 * REST permission callback that defers to the ability's permission callback.
 *
 * Calling `check_permissions()` directly keeps the ability's real error
 * (401/403/404) instead of the generic one `execute()` returns.
 *
 * @param string $name  Ability name.
 * @param array  $input Ability input.
 * @return true|WP_Error
 */
function extrachill_api_shop_check_ability( $name, array $input ) {
	$ability = extrachill_api_shop_get_ability( $name );
	if ( is_wp_error( $ability ) ) {
		return $ability;
	}
	$allowed = $ability->check_permissions( $input );
	if ( is_wp_error( $allowed ) ) {
		return $allowed;
	}
	return true === $allowed ? true : new WP_Error( 'rest_forbidden', 'You do not have permission to do that.', array( 'status' => 403 ) );
}

/**
 * Execute a Shop ability and wrap the result as a REST response.
 *
 * @param string $name  Ability name.
 * @param array  $input Ability input.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_shop_run_ability( $name, array $input ) {
	$ability = extrachill_api_shop_get_ability( $name );
	if ( is_wp_error( $ability ) ) {
		return $ability;
	}
	$result = $ability->execute( $input );
	return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
}

/**
 * Register a thin seller route: one ability per method.
 *
 * @param string $route     Route under `extrachill/v1`.
 * @param array  $endpoints List of `{ methods, ability, args?, params? }`. `params`
 *                          names the request params forwarded as ability input
 *                          (the seller is always forwarded when present).
 */
function extrachill_api_shop_register_ability_route( $route, array $endpoints ) {
	$handlers = array();
	foreach ( $endpoints as $endpoint ) {
		$ability    = $endpoint['ability'];
		$params     = $endpoint['params'] ?? array();
		$handlers[] = array(
			'methods'             => $endpoint['methods'],
			'args'                => $endpoint['args'] ?? array(),
			'permission_callback' => static function ( WP_REST_Request $request ) use ( $ability, $params ) {
				return extrachill_api_shop_check_ability( $ability, extrachill_api_shop_ability_input( $request, $params ) );
			},
			'callback'            => static function ( WP_REST_Request $request ) use ( $ability, $params ) {
				return extrachill_api_shop_run_ability( $ability, extrachill_api_shop_ability_input( $request, $params ) );
			},
		);
	}
	register_rest_route( 'extrachill/v1', $route, $handlers );
}
