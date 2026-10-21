<?php
/**
 * Guest (email-only) Local Scene digest signup ability (#989).
 *
 * Domain behavior lives in inc/core/local-scene-digest-guest.php. The public
 * write boundary (Turnstile + rate limit) is a dedicated extrachill-api route,
 * like guest RSVP, so this ability is hidden from the generic REST runner.
 *
 * @package ExtraChillEvents\Abilities
 */

namespace ExtraChillEvents\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LocalSceneDigestGuestAbilities {

	private static bool $registered = false;

	public function __construct() {
		if ( ! self::$registered ) {
			add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
			self::$registered = true;
		}
	}

	public function register(): void {
		wp_register_ability(
			'extrachill/guest-subscribe-local-scene-digest',
			array(
				'label'               => __( 'Guest Subscribe To Local Scene Digest', 'extrachill-events' ),
				'description'         => __( 'Ask for a city\'s weekly show email with only an email address. Resolves or creates a claimable account and emails a signed confirm link; the subscription is recorded only when the inbox owner confirms.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'location_slug' => array(
							'type'        => 'string',
							'description' => __( 'City location term slug.', 'extrachill-events' ),
						),
						'email'         => array(
							'type'        => 'string',
							'description' => __( 'Email address.', 'extrachill-events' ),
						),
						'referrer'      => array(
							'type'        => 'string',
							'description' => __( 'Optional external referrer URL for attribution.', 'extrachill-events' ),
						),
						'utm'           => array(
							'type'        => 'object',
							'description' => __( 'Optional UTM parameters for attribution.', 'extrachill-events' ),
						),
					),
					'required'   => array( 'location_slug', 'email' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'status' => array( 'type' => 'string' ),
					),
					'required'   => array( 'status' ),
				),
				'execute_callback'    => array( $this, 'guest_subscribe' ),
				// Public write boundary (Turnstile) is the dedicated extrachill-api
				// route; this ability is hidden from the generic REST runner.
				'permission_callback' => '__return_true',
				'meta'                => array(
					'show_in_rest' => false,
					'annotations'  => array(
						'readonly'     => false,
						'idempotent'   => true,
						'destructive'  => false,
						'instructions' => __( 'Always returns { status: check_inbox } on success. Never reveals whether the email had an account, was throttled, or was already subscribed.', 'extrachill-events' ),
					),
				),
			)
		);
	}

	/**
	 * Request a guest digest subscription. Response is deliberately opaque.
	 *
	 * @param array $input Validated ability input.
	 * @return array|\WP_Error
	 */
	public function guest_subscribe( array $input ) {
		if ( ! function_exists( 'extrachill_events_guest_subscribe_local_scene_digest' ) ) {
			return new \WP_Error( 'guest_digest_unavailable', __( 'Email signup is temporarily unavailable.', 'extrachill-events' ), array( 'status' => 503 ) );
		}

		return extrachill_events_guest_subscribe_local_scene_digest(
			(string) ( $input['location_slug'] ?? '' ),
			(string) ( $input['email'] ?? '' ),
			array(
				'referrer' => (string) ( $input['referrer'] ?? '' ),
				'utm'      => is_array( $input['utm'] ?? null ) ? $input['utm'] : array(),
			)
		);
	}
}
