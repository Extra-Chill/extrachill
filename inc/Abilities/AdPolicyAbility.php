<?php
/**
 * Ad policy read ability.
 *
 * @package ExtraChillNetwork\Abilities
 */

namespace ExtraChillNetwork\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes the canonical network ad policy to API and automation consumers.
 */
class AdPolicyAbility {

	/**
	 * Register the ability when the Abilities API initializes.
	 */
	public function __construct() {
		add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
	}

	/**
	 * Register the read-only policy ability.
	 */
	public function register(): void {
		wp_register_ability(
			'extrachill/get-ad-policy',
			array(
				'label'               => __( 'Get Ad Policy', 'extrachill-network' ),
				'description'         => __( 'Resolve whether ads should be served for an Extra Chill site and request context.', 'extrachill-network' ),
				'category'            => 'extrachill-network',
				'input_schema'        => array(
					'type'       => 'object',
					'default'    => array(),
					'properties' => array(
						'blog_id'              => array( 'type' => 'integer' ),
						'post_type'            => array( 'type' => 'string' ),
						'is_front_page'        => array( 'type' => 'boolean' ),
						'is_home'              => array( 'type' => 'boolean' ),
						'is_page'              => array( 'type' => 'boolean' ),
						'is_search'            => array( 'type' => 'boolean' ),
						'is_archive'           => array( 'type' => 'boolean' ),
						'is_singular'          => array( 'type' => 'boolean' ),
						'is_post_type_archive' => array( 'type' => 'boolean' ),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'required'   => array( 'blog_id', 'site_enabled', 'serve_ads', 'reason', 'integration_available', 'delivery_detected', 'drift' ),
					'properties' => array(
						'blog_id'               => array( 'type' => 'integer' ),
						'site_enabled'          => array( 'type' => 'boolean' ),
						'serve_ads'             => array( 'type' => 'boolean' ),
						'reason'                => array(
							'type' => 'string',
							'enum' => array( 'enabled', 'site_disabled', 'route_blocked', 'member_benefit', 'integration_unavailable' ),
						),
						'integration_available' => array( 'type' => 'boolean' ),
						'delivery_detected'     => array( 'type' => 'boolean' ),
						'drift'                 => array(
							'type' => 'string',
							'enum' => array( 'none', 'enabled_without_delivery', 'disabled_with_delivery' ),
						),
					),
				),
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => '__return_true',
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'   => true,
						'idempotent' => true,
					),
				),
			)
		);
	}

	/**
	 * Resolve the policy from ability input.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public function execute( array $input ): array {
		return extrachill_get_ad_policy( $input );
	}
}
