<?php
/**
 * Current-account newsletter preferences. No caller-supplied identity allowed.
 *
 * @package ExtraChillNewsletter
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_newsletter_register_member_abilities' );

/** Register member-only REST abilities. */
function extrachill_newsletter_register_member_abilities(): void {
	foreach ( array( 'get', 'set' ) as $operation ) {
		$is_set = 'set' === $operation;
		wp_register_ability(
			$is_set ? 'extrachill/newsletter-set-my-subscription' : 'extrachill/newsletter-get-my-subscriptions',
			array(
				'label'               => $is_set ? __( 'Set My Newsletter Subscription', 'extrachill-newsletter' ) : __( 'Get My Newsletter Subscriptions', 'extrachill-newsletter' ),
				'description'         => __( 'Manage newsletter preferences using only the current account email.', 'extrachill-newsletter' ),
				'category'            => 'extrachill-newsletter',
				'input_schema'        => array(
					'type'                 => 'object',
					'default'              => array(),
					'properties'           => $is_set ? array(
						'list'       => array( 'type' => 'string' ),
						'subscribed' => array( 'type' => 'boolean' ),
					) : new stdClass(),
					'required'             => $is_set ? array( 'list', 'subscribed' ) : array(),
					'additionalProperties' => false,
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => $is_set ? 'extrachill_newsletter_set_my_subscription' : 'extrachill_newsletter_get_my_subscriptions',
				'permission_callback' => 'is_user_logged_in',
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array( 'readonly' => ! $is_set ),
				),
			)
		);
	}
}

/**
 * Validate input even for direct PHP callers; never trust an identity parameter.
 *
 * @param mixed $input Ability input.
 * @param bool  $is_set Whether this is a mutation.
 * @return true|WP_Error
 */
function extrachill_newsletter_validate_member_input( $input, bool $is_set ) {
	if ( ! is_user_logged_in() ) {
		return new WP_Error( 'newsletter_login_required', 'Login required.', array( 'status' => 401 ) );
	}
	if ( ! is_array( $input ) || array_diff( array_keys( $input ), $is_set ? array( 'list', 'subscribed' ) : array() ) || ( $is_set && ( ! isset( $input['list'], $input['subscribed'] ) || ! is_string( $input['list'] ) || ! is_bool( $input['subscribed'] ) ) ) ) {
		return new WP_Error( 'newsletter_invalid_input', 'Invalid newsletter preference input.', array( 'status' => 400 ) );
	}
	return true;
}

/**
 * Report whether the current account receives the Extra Chill newsletter.
 *
 * Campaigns go to every network list, so each signup form's list is a source of
 * the same newsletter. Members see one preference: subscribed on any list.
 *
 * @param array $input Empty input object.
 * @return array|WP_Error
 */
function extrachill_newsletter_get_my_subscriptions( $input = array() ) {
	$valid = extrachill_newsletter_validate_member_input( $input, false );
	if ( is_wp_error( $valid ) ) {
		return $valid;
	}
	$class = 'DataMachineBusiness\\Sendy\\SendyClient';
	if ( ! class_exists( $class ) ) {
		return new WP_Error( 'sendy_unavailable', 'Sendy integration unavailable.' );
	}
	$lists = extrachill_newsletter_subscription_lists();
	if ( empty( $lists ) ) {
		return array( 'lists' => array() );
	}
	$client     = new $class( extrachill_newsletter_subscription_config() );
	$email      = wp_get_current_user()->user_email;
	$subscribed = false;
	foreach ( $lists as $list ) {
		$status = $client->subscriber_status( $list['list_id'], $email );
		if ( is_wp_error( $status ) ) {
			return $status;
		}
		if ( ! in_array( $status, array( 'Subscribed', 'Unsubscribed', 'Unconfirmed', 'Bounced', 'Soft bounced', 'Complained', 'Suppressed', 'Email does not exist in list' ), true ) ) {
			return new WP_Error( 'sendy_status_failed', 'Sendy returned an unknown subscription status.' );
		}
		if ( 'Subscribed' === $status ) {
			$subscribed = true;
			break;
		}
	}
	return array(
		'lists' => array(
			array(
				'key'        => EXTRACHILL_NEWSLETTER_MEMBER_KEY,
				'name'       => __( 'Extra Chill newsletter', 'extrachill-newsletter' ),
				'subscribed' => $subscribed,
			),
		),
	);
}

/**
 * Turn the current account's newsletter on or off.
 *
 * On subscribes the account email to the member signup list. Off unsubscribes
 * it from every network list, so no campaign reaches it.
 *
 * @param array $input List key and strict boolean subscribed flag.
 * @return array|WP_Error
 */
function extrachill_newsletter_set_my_subscription( $input ) {
	$valid = extrachill_newsletter_validate_member_input( $input, true );
	if ( is_wp_error( $valid ) ) {
		return $valid;
	}
	if ( EXTRACHILL_NEWSLETTER_MEMBER_KEY !== $input['list'] ) {
		return new WP_Error( 'newsletter_invalid_list', 'Unknown member newsletter list.', array( 'status' => 400 ) );
	}
	$email = wp_get_current_user()->user_email;
	if ( $input['subscribed'] ) {
		$list_id = extrachill_newsletter_member_signup_list_id();
		if ( '' === $list_id ) {
			return new WP_Error( 'newsletter_unconfigured', 'No newsletter list is configured.' );
		}
		$result = extrachill_newsletter_sendy_subscribe( $list_id, $email );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( empty( $result['success'] ) && ! in_array( $result['status'] ?? '', array( 'subscribed', 'already_subscribed' ), true ) ) {
			return new WP_Error( 'sendy_subscribe_failed', 'Sendy did not confirm subscription.' );
		}
	} else {
		foreach ( extrachill_newsletter_subscription_lists() as $list ) {
			$result = extrachill_newsletter_sendy_unsubscribe( $list['list_id'], $email );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
	}
	return array(
		'list'       => $input['list'],
		'subscribed' => $input['subscribed'],
	);
}
