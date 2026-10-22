<?php
/**
 * Mail Abilities
 *
 * Exposes EC mail helpers as Abilities API primitives so they are
 * discoverable via WP-CLI, REST, MCP, and chat tooling.
 *
 * Currently provides:
 *   - `extrachill/mail-site-id` — resolve the SMTP-configured blog ID to
 *     use for outgoing mail in the current context.
 *
 * @package ExtraChillNetwork\Abilities
 */

namespace ExtraChillNetwork\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MailAbilities {

	private static bool $registered = false;

	public function __construct() {
		if ( ! self::$registered ) {
			$this->registerAbilities();
			self::$registered = true;
		}
	}

	private function registerAbilities(): void {
		// Category is registered once via inc/Abilities/CategoryRegistration.php.
		add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
	}

	public function register(): void {
		wp_register_ability(
			'extrachill/mail-site-id',
			array(
				'label'               => __( 'Resolve Mail Site ID', 'extrachill-network' ),
				'description'         => __(
					'Resolve the blog ID of the closest SMTP-configured site for the current context. Used as the `mail_site_id` input to the datamachine/send-email ability so subsites without local SMTP credentials do not silently fail.',
					'extrachill-network'
				),
				'category'            => 'extrachill-network',
				'input_schema'        => array(
					'type'       => 'object',
					'default'    => array(),
					'properties' => new \stdClass(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'blog_id'          => array( 'type' => 'integer' ),
						'configured_sites' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'integer' ),
						),
						'current_blog_id'  => array( 'type' => 'integer' ),
					),
				),
				'execute_callback'    => array( $this, 'executeMailSiteId' ),
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
	 * Execute mail-site-id ability.
	 *
	 * @param array $input Unused — ability takes no inputs.
	 * @return array Structured result.
	 */
	public function executeMailSiteId( array $input ): array {
		unset( $input );

		return array(
			'blog_id'          => function_exists( 'extrachill_mail_site_id' ) ? (int) extrachill_mail_site_id() : 0,
			'configured_sites' => function_exists( 'extrachill_smtp_configured_sites' ) ? extrachill_smtp_configured_sites() : array(),
			'current_blog_id'  => (int) get_current_blog_id(),
		);
	}
}
