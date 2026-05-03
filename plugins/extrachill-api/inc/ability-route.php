<?php
/**
 * Ability route helper.
 *
 * Returns a standard REST route argument array that delegates the
 * route's callback + permission_callback to a registered ability.
 * Used to keep extrachill-api's branded /extrachill/v1/* surface
 * thin while abilities own canonical implementation.
 *
 * Usage:
 *   register_rest_route( 'extrachill/v1', '/users/leaderboard', array_merge(
 *     extrachill_api_ability_route( 'extrachill/users-leaderboard' ),
 *     array(
 *       'methods' => 'GET',
 *       'args'    => array( ... ),  // optional REST-side arg validation
 *     )
 *   ) );
 *
 * The route URL stays. Permission semantics stay. Implementation moves
 * to the ability registered in the relevant feature plugin (e.g.
 * extrachill-users for users-* abilities).
 *
 * @package ExtraChill\Api
 * @since   0.x.0
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Build callback + permission_callback that delegate to a registered ability.
 *
 * If the ability is not registered (e.g. its feature plugin is inactive),
 * both callbacks return WP_Error so the route fails predictably with a
 * 500 Server Error rather than silently misbehaving.
 *
 * @param string $ability_name Fully-qualified ability name (e.g. 'extrachill/users-leaderboard').
 * @return array{callback: callable, permission_callback: callable}
 */
function extrachill_api_ability_route( string $ability_name ): array {
	return array(
		'callback'            => static function ( WP_REST_Request $request ) use ( $ability_name ) {
			$ability = wp_get_ability( $ability_name );
			if ( ! $ability ) {
				return new WP_Error(
					'extrachill_ability_missing',
					sprintf(
						/* translators: %s: ability name */
						__( 'Ability %s is not registered. The feature plugin that owns this ability may be inactive.', 'extrachill-api' ),
						$ability_name
					),
					array( 'status' => 500 )
				);
			}

			$result = $ability->execute( $request->get_params() );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return rest_ensure_response( $result );
		},
		'permission_callback' => static function ( WP_REST_Request $request ) use ( $ability_name ) {
			$ability = wp_get_ability( $ability_name );
			if ( ! $ability ) {
				return new WP_Error(
					'extrachill_ability_missing',
					sprintf(
						/* translators: %s: ability name */
						__( 'Ability %s is not registered.', 'extrachill-api' ),
						$ability_name
					),
					array( 'status' => 500 )
				);
			}

			return $ability->has_permission( $request->get_params() );
		},
	);
}
