<?php
/**
 * Signed-link Local Scene digest abilities.
 *
 * - extrachill/guest-subscribe-local-scene-digest: guest (email-only) signup
 *   (#989). Domain behavior lives in inc/core/local-scene-digest-guest.php.
 *   The public write boundary (Turnstile + rate limit) is a dedicated
 *   extrachill-api route, like guest RSVP.
 * - extrachill/unsubscribe-local-scene-digest: the signed one-click
 *   unsubscribe page. Domain behavior lives in inc/core/local-scene-digest.php;
 *   extrachill-api serves it at extrachill/v1/local-scene-digest/unsubscribe.
 *
 * Both are hidden from the generic REST runner: the signature, not the
 * WordPress session, is the authorization.
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
							'description' => __( 'City location term slug, or venue term slug when scope is venue.', 'extrachill-events' ),
						),
						'scope'         => array(
							'type'        => 'string',
							'enum'        => array( 'location', 'venue' ),
							'description' => __( 'Digest scope: a city\'s weekly Local Scene picks (location, default) or one venue\'s weekly show updates (venue).', 'extrachill-events' ),
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

		wp_register_ability(
			'extrachill/unsubscribe-local-scene-digest',
			array(
				'label'               => __( 'Unsubscribe From Local Scene Digest', 'extrachill-events' ),
				'description'         => __( 'Resolve a signed Local Scene digest unsubscribe link. Without confirm it returns a scanner-safe confirmation page and never changes consent; with confirm it removes only that location\'s (or venue\'s) digest subscription.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'confirm'   => array(
							'type'        => 'boolean',
							'description' => __( 'Perform the unsubscribe (POST) instead of rendering the confirmation (GET).', 'extrachill-events' ),
						),
						'token'     => array(
							'type'        => 'string',
							'description' => __( 'Signed unsubscribe token.', 'extrachill-events' ),
						),
						'user'      => array(
							'type'        => 'integer',
							'description' => __( 'Legacy signed link user ID.', 'extrachill-events' ),
						),
						'location'  => array(
							'type'        => 'string',
							'description' => __( 'Legacy signed link location slug.', 'extrachill-events' ),
						),
						'expires'   => array(
							'type'        => 'integer',
							'description' => __( 'Legacy signed link expiry timestamp.', 'extrachill-events' ),
						),
						'signature' => array(
							'type'        => 'string',
							'description' => __( 'Legacy signed link HMAC.', 'extrachill-events' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'status'      => array(
							'type' => 'string',
							'enum' => array( 'confirm', 'unsubscribed', 'invalid', 'unavailable' ),
						),
						'title'       => array( 'type' => 'string' ),
						'message'     => array( 'type' => 'string' ),
						'action_html' => array( 'type' => 'string' ),
						'html'        => array( 'type' => 'string' ),
					),
					'required'   => array( 'status', 'title', 'message', 'action_html', 'html' ),
				),
				'execute_callback'    => array( $this, 'unsubscribe' ),
				// The signed link is the authorization; the public transport is
				// the dedicated extrachill-api route.
				'permission_callback' => '__return_true',
				'meta'                => array(
					'show_in_rest' => false,
					'annotations'  => array(
						'readonly'    => false,
						'idempotent'  => true,
						'destructive' => false,
					),
				),
			)
		);
	}

	/**
	 * Resolve a signed unsubscribe request into its standalone page.
	 *
	 * @param array $input Validated ability input.
	 * @return array|\WP_Error
	 */
	public function unsubscribe( array $input ) {
		if ( ! function_exists( 'extrachill_events_local_scene_digest_unsubscribe_page' ) ) {
			return new \WP_Error( 'local_scene_digest_unsubscribe_unavailable', __( 'Unsubscribe is temporarily unavailable.', 'extrachill-events' ), array( 'status' => 503 ) );
		}

		$params = array();
		foreach ( array( 'token', 'user', 'location', 'expires', 'signature' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$params[ $key ] = $input[ $key ];
			}
		}

		return extrachill_events_local_scene_digest_unsubscribe_page( $params, ! empty( $input['confirm'] ) );
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
			),
			'venue' === ( $input['scope'] ?? '' ) ? 'venue' : 'location'
		);
	}
}
