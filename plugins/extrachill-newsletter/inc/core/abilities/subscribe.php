<?php
/**
 * Subscribe Ability
 *
 * Core primitive for newsletter subscriptions via Sendy.
 * Replaces extrachill_network_subscribe() and extrachill_subscribe_to_list().
 *
 * @package ExtraChillNewsletter
 * @since 0.3.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_newsletter_register_subscribe_ability' );

/**
 * Register the subscribe ability.
 */
function extrachill_newsletter_register_subscribe_ability() {
	wp_register_ability(
		'extrachill/subscribe',
		array(
			'label'               => __( 'Subscribe', 'extrachill-newsletter' ),
			'description'         => __( 'Subscribe an email address to a Sendy newsletter list. Resolves list ID from integration context or accepts a direct list ID.', 'extrachill-newsletter' ),
			'category'            => 'extrachill-newsletter',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'email'      => array(
						'type'        => 'string',
						'description' => __( 'Email address to subscribe.', 'extrachill-newsletter' ),
					),
					'context'    => array(
						'type'        => 'string',
						'description' => __( 'Integration context (e.g. homepage, navigation, content, archive, contact).', 'extrachill-newsletter' ),
					),
					'list_id'    => array(
						'type'        => 'string',
						'description' => __( 'Direct Sendy list ID. If provided, context lookup is skipped.', 'extrachill-newsletter' ),
					),
					'name'       => array(
						'type'        => 'string',
						'description' => __( 'Optional subscriber name.', 'extrachill-newsletter' ),
					),
					'source_url' => array(
						'type'        => 'string',
						'description' => __( 'URL of the page where the subscription originated.', 'extrachill-newsletter' ),
					),
				),
				'required'   => array( 'email' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'message' => array( 'type' => 'string' ),
					'status'  => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => 'extrachill_newsletter_ability_subscribe',
			'permission_callback' => '__return_true',
			'meta'                => array(
				'show_in_rest' => false,
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => false,
					'destructive' => false,
				),
			),
		)
	);
}

/**
 * Determine whether a raw email address is a plausible real signup.
 *
 * `is_email()` alone cannot catch a hostile-input class where
 * `sanitize_email()` strips the dangerous characters out of an attack
 * payload and what remains is a structurally valid address. Sanitize-then-
 * validate cannot fail by construction: sanitize_email()'s entire job is to
 * produce a string is_email() accepts. A 2025-08-01 automated scanner probe
 * demonstrated this on this exact form -- an SQLi payload appended to a
 * real-looking local part survived sanitization and was stored as a
 * subscriber byte-for-byte. See
 * https://github.com/Extra-Chill/extrachill-newsletter/issues/46.
 *
 * This checks the *raw* input against two signals a genuine signup never
 * trips:
 *
 * 1. Sanitization drift. If sanitizing the trimmed raw string changes it,
 *    something in the input was invalid and got silently discarded. A real
 *    address submitted through a standard `type="email"` field round-trips
 *    through sanitize_email() unchanged (`+` tags, dots, hyphens, and
 *    apostrophes in the local part are all in sanitize_email()'s allowed
 *    character set, so none of those trip this check).
 * 2. Reserved placeholder domains. RFC 2606 reserves example.com/net/org/edu
 *    for documentation, and test.com/net/org is the de-facto scanner/QA
 *    fixture convention. These are structurally valid and sanitize
 *    untouched, so (1) does not catch them, but no real subscriber has a
 *    mailbox on a reserved documentation domain.
 *
 * Deliberately not doing DNS/MX lookups here: that adds a network
 * dependency and latency to every subscribe request and risks rejecting
 * legitimate addresses on domains with transient DNS issues, a larger
 * blast radius than the bug this function fixes.
 *
 * This is a data-integrity check, not a security control. No injection
 * occurred in the incident that prompted this fix; sanitize_email() had
 * already neutralized the payload before this function runs.
 *
 * @param string $raw_email Raw, already-trimmed email address as submitted.
 * @return bool True when the address is a plausible real signup.
 */
function extrachill_newsletter_is_plausible_email( $raw_email ) {
	$raw_email = (string) $raw_email;

	if ( '' === $raw_email ) {
		return false;
	}

	$sanitized = sanitize_email( $raw_email );

	if ( $sanitized !== $raw_email ) {
		return false;
	}

	if ( ! is_email( $sanitized ) ) {
		return false;
	}

	$at_pos = strrpos( $sanitized, '@' );
	$domain = false !== $at_pos ? strtolower( substr( $sanitized, $at_pos + 1 ) ) : '';

	$reserved_domains = array(
		'example.com',
		'example.net',
		'example.org',
		'example.edu',
		'test.com',
		'test.net',
		'test.org',
	);

	if ( in_array( $domain, $reserved_domains, true ) ) {
		return false;
	}

	return true;
}

/**
 * Subscribe an email address to a Sendy list.
 *
 * Resolves the list ID from context if not directly provided, validates the
 * email, sends the subscription request to Sendy, fires hooks, and tracks analytics.
 *
 * @param array $input {email, context, list_id, name, source_url}.
 * @return array|WP_Error Result with success, message, status keys, or WP_Error on failure.
 */
function extrachill_newsletter_ability_subscribe( $input ) {
	$email      = isset( $input['email'] ) ? trim( (string) $input['email'] ) : '';
	$context    = isset( $input['context'] ) ? $input['context'] : '';
	$list_id    = isset( $input['list_id'] ) ? $input['list_id'] : '';
	$name       = isset( $input['name'] ) ? $input['name'] : '';
	$source_url = isset( $input['source_url'] ) ? $input['source_url'] : '';

	if ( empty( $email ) ) {
		return new WP_Error( 'missing_email', 'Email address is required.' );
	}

	// Resolve list_id from context if not directly provided.
	if ( empty( $list_id ) ) {
		if ( empty( $context ) ) {
			return new WP_Error(
				'missing_list_id',
				'Either list_id or context is required to determine the subscription list.'
			);
		}

		$integrations = get_newsletter_integrations();

		if ( ! isset( $integrations[ $context ] ) ) {
			return new WP_Error(
				'invalid_context',
				__( 'Newsletter integration not found', 'extrachill-newsletter' )
			);
		}

		$integration = $integrations[ $context ];
		$settings    = get_site_option( 'extrachill_newsletter_settings', array() );
		$list_id     = isset( $settings[ $integration['list_id_key'] ] ) ? $settings[ $integration['list_id_key'] ] : '';

		if ( empty( $list_id ) ) {
			return new WP_Error(
				'list_not_configured',
				__( 'Newsletter list not configured for this integration', 'extrachill-newsletter' )
			);
		}
	}

	// Validate email. Rejects malformed addresses and also the class of
	// scanner-residue payload that sanitize_email() launders into something
	// is_email() alone accepts -- see extrachill_newsletter_is_plausible_email()
	// for why the two checks must run together.
	if ( ! extrachill_newsletter_is_plausible_email( $email ) ) {
		return array(
			'success' => false,
			'message' => __( 'Invalid email address', 'extrachill-newsletter' ),
			'status'  => 'invalid',
		);
	}

	$source = ! empty( $context ) ? $context : 'direct';

	// Delegate the Sendy API mechanics to the generic data-machine-business
	// Sendy primitive. This plugin owns the policy (list resolution, validation,
	// hooks, analytics); the mechanism (the raw API call) lives one layer down.
	$result = extrachill_newsletter_sendy_subscribe( $list_id, $email, $name );

	if ( is_wp_error( $result ) ) {
		return array(
			'success' => false,
			'message' => __( 'Subscription service unavailable', 'extrachill-newsletter' ),
			'status'  => 'error',
		);
	}

	$status = isset( $result['status'] ) ? $result['status'] : 'failed';

	if ( ! empty( $result['success'] ) || 'subscribed' === $status ) {
		/** This action is documented in inc/core/sendy-api.php */
		do_action( 'extrachill_newsletter_subscribed', $source, $list_id, $source_url );

		// Track analytics (skip auto-subscriptions during registration).
		if ( 'registration' !== $source ) {
			$analytics_ability = wp_get_ability( 'extrachill/track-analytics-event' );
			if ( $analytics_ability ) {
				$event_data = array(
					'context' => $source,
					'list_id' => $list_id,
				);

				// The analytics write-boundary classifier treats any anonymous
				// REST-origin request as a bot, which stamps every organic form
				// signup is_bot:true — public submissions reach this ability
				// through the extrachill/v1/newsletter/subscribe REST route
				// after passing Cloudflare Turnstile, and this row is only
				// written once Sendy returned "subscribed". A real integration
				// context in an HTTP request therefore means a verified human,
				// so supply the verdict explicitly. CLI operators
				// (--integration=...) and cron flows carry the same context
				// values, so the verdict is left unset there and the generic
				// classifier rules apply. Mirrors the pageview beacon override
				// in extrachill-analytics track-page-view.php. See
				// extrachill-analytics#275 for the classifier-side fix.
				if ( 'direct' !== $source && ! ( defined( 'WP_CLI' ) && WP_CLI ) && ! wp_doing_cron() ) {
					$event_data['is_bot'] = false;
				}

				$analytics_ability->execute(
					array(
						'event_type' => 'newsletter_signup',
						'event_data' => $event_data,
						'source_url' => $source_url,
					)
				);
			}
		}

		return array(
			'success' => true,
			'message' => __( 'Successfully subscribed to newsletter', 'extrachill-newsletter' ),
			'status'  => 'subscribed',
		);
	}

	if ( 'already_subscribed' === $status ) {
		return array(
			'success' => false,
			'message' => __( 'Email already subscribed', 'extrachill-newsletter' ),
			'status'  => 'already_subscribed',
		);
	}

	if ( 'invalid' === $status ) {
		return array(
			'success' => false,
			'message' => __( 'Invalid email address', 'extrachill-newsletter' ),
			'status'  => 'invalid',
		);
	}

	return array(
		'success' => false,
		'message' => __( 'Subscription failed, please try again', 'extrachill-newsletter' ),
		'status'  => 'failed',
	);
}

/**
 * Subscribe an email to a Sendy list via the generic DMB Sendy primitive.
 *
 * Delegates the raw Sendy API call to `datamachine/sendy-subscribe`, the single
 * canonical Sendy client (config-injected, EC-agnostic) provided by
 * data-machine-business. This plugin owns the policy (list resolution,
 * validation, hooks, analytics); the mechanism lives one layer down. The Data
 * Machine suite is a hard runtime dependency, so there is no in-plugin fallback
 * Sendy client — keeping one would re-introduce the duplicate this consolidation
 * removed.
 *
 * @param string $list_id Sendy list ID.
 * @param string $email   Email address.
 * @param string $name    Optional subscriber name.
 * @return array|WP_Error Normalised result {success, status, message, raw}.
 */
function extrachill_newsletter_sendy_subscribe( $list_id, $email, $name = '' ) {
	$ability = extrachill_newsletter_get_sendy_ability( 'datamachine/sendy-subscribe' );

	if ( ! $ability ) {
		return new WP_Error(
			'sendy_primitive_unavailable',
			__( 'Newsletter subscriptions require the Data Machine Business Sendy integration to be active.', 'extrachill-newsletter' )
		);
	}

	return $ability->execute(
		array(
			'config'  => extrachill_newsletter_sendy_dmb_config(),
			'list_id' => $list_id,
			'email'   => $email,
			'name'    => $name,
		)
	);
}
