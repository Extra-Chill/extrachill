<?php
/**
 * Single owner for the `extrachill-mcp` ability category.
 *
 * @package ExtraChillMcp\Abilities
 */

declare( strict_types = 1 );

namespace ExtraChillMcp\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the `extrachill-mcp` ability category, exactly once.
 */
final class CategoryRegistration {

	public function register(): void {
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
	}

	public function register_category(): void {
		if ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( 'extrachill-mcp' ) ) {
			return;
		}

		wp_register_ability_category(
			'extrachill-mcp',
			array(
				'label'       => __( 'Extra Chill MCP', 'extrachill-mcp' ),
				'description' => __( 'Network-wide ability discovery and dispatch for the Extra Chill MCP endpoint.', 'extrachill-mcp' ),
			)
		);
	}
}
