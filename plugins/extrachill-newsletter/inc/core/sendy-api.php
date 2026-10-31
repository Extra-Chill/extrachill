<?php
/**
 * Sendy API Integration
 *
 * Network-wide subscription functions and Sendy API integration.
 * Provides centralized configuration and subscription bridge.
 *
 * @package ExtraChillNewsletter
 * @since 0.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Canonical default value for the Sendy installation URL.
 *
 * Single source of truth — every place that needs the default Sendy URL
 * must read it from here rather than re-hardcoding the literal.
 */
/**
 * Default newsletter settings.
 *
 * Single source of truth for newsletter setting defaults (Sendy URL, sender
 * identity, brand). Integration list-ID defaults are added by callers that
 * need them via get_newsletter_integrations().
 *
 * @return array Default settings.
 */
function extrachill_newsletter_default_settings() {
	return array(
		'from_name'  => 'Extra Chill',
		'from_email' => 'newsletter@extrachill.com',
		'reply_to'   => 'chubes@extrachill.com',
		'brand_id'   => '1',
	);
}

/**
 * Get the newsletter's sender identity.
 *
 * Sendy credentials (API key, URL, database) are owned by Data Machine
 * Business in its canonical configuration; this plugin only owns who the
 * newsletter is from.
 *
 * @return array {from_name, from_email, reply_to, brand_id}.
 */
function get_sendy_config() {
	$settings = get_site_option( 'extrachill_newsletter_settings', array() );
	$settings = wp_parse_args( is_array( $settings ) ? $settings : array(), extrachill_newsletter_default_settings() );

	return array(
		'from_name'  => $settings['from_name'],
		'from_email' => $settings['from_email'],
		'reply_to'   => $settings['reply_to'],
		'brand_id'   => $settings['brand_id'],
	);
}

/**
 * Fully-qualified name of the Data Machine Business Sendy abilities class.
 */
const EXTRACHILL_NEWSLETTER_DMB_SENDY_CLASS = 'DataMachineBusiness\\Abilities\\Sendy\\SendyAbilities';

/**
 * Report whether Data Machine Business has a usable Sendy API connection.
 *
 * Read-only status for settings screens. Credentials are owned and managed by
 * Data Machine Business (`datamachine_sendy_config`); this plugin never stores
 * or edits them.
 *
 * @return array {configured: bool, sendy_url: string}.
 */
function extrachill_newsletter_get_sendy_connection() {
	$class  = EXTRACHILL_NEWSLETTER_DMB_SENDY_CLASS;
	$config = class_exists( $class ) && method_exists( $class, 'get_campaign_config' ) ? $class::get_campaign_config() : array();

	return array(
		'configured' => ! empty( $config['api_key'] ) && ! empty( $config['sendy_url'] ),
		'sendy_url'  => isset( $config['sendy_url'] ) ? (string) $config['sendy_url'] : '',
	);
}

add_action( 'init', 'extrachill_newsletter_remove_legacy_sendy_credentials' );

/**
 * Remove the legacy duplicate of the Sendy credentials.
 *
 * Older versions stored sendy_api_key and sendy_url in this plugin's
 * settings. Once Data Machine Business has a working connection, the copy is
 * dead weight and a second place for the key to drift, so delete it. It is
 * left alone while DMB is unconfigured so nothing is lost.
 */
function extrachill_newsletter_remove_legacy_sendy_credentials() {
	$settings = get_site_option( 'extrachill_newsletter_settings', array() );
	if ( ! is_array( $settings ) || ( ! array_key_exists( 'sendy_api_key', $settings ) && ! array_key_exists( 'sendy_url', $settings ) ) ) {
		return;
	}

	if ( ! extrachill_newsletter_get_sendy_connection()['configured'] ) {
		return;
	}

	unset( $settings['sendy_api_key'], $settings['sendy_url'] );
	update_site_option( 'extrachill_newsletter_settings', $settings );
}

/**
 * Resolve the generic DMB Sendy ability, if available.
 *
 * Returns the WP_Ability instance for the given generic Sendy ability, or null
 * when data-machine-business is not active. Callers fall back to their own
 * legacy path when null so newsletter behavior never breaks.
 *
 * @param string $ability Generic ability slug (e.g. datamachine/sendy-subscribe).
 * @return \WP_Ability|null
 */
function extrachill_newsletter_get_sendy_ability( $ability ) {
	if ( ! function_exists( 'wp_get_ability' ) ) {
		return null;
	}
	if ( function_exists( 'wp_has_ability' ) && ! wp_has_ability( $ability ) ) {
		return null;
	}

	$instance = wp_get_ability( $ability );
	return $instance ? $instance : null;
}

/**
 * Subscribe email address via Sendy API using integration context
 *
 * Validates integration context exists and list ID is configured,
 * then delegates to extrachill_subscribe_to_list().
 *
 * @param string $email      Email address to subscribe.
 * @param string $context    Form context (homepage, navigation, content, archive).
 * @param string $source_url Optional URL of the page where the form was submitted.
 * @param string $name       Optional subscriber name.
 * @return array Success status and message.
 */
function extrachill_network_subscribe( $email, $context, $source_url = '', $name = '' ) {
	$ability = wp_get_ability( 'extrachill/subscribe' );

	if ( ! $ability ) {
		return array(
			'success' => false,
			'message' => __( 'Subscription service unavailable', 'extrachill-newsletter' ),
		);
	}

	$result = $ability->execute(
		array(
			'email'      => $email,
			'context'    => $context,
			'source_url' => $source_url,
			'name'       => $name,
		)
	);

	if ( is_wp_error( $result ) ) {
		return array(
			'success' => false,
			'message' => $result->get_error_message(),
		);
	}

	return $result;
}

/**
 * Subscribe email address directly to a Sendy list
 *
 * Thin delegate to the extrachill/subscribe ability (the single Sendy
 * subscription implementation). Used by admin bulk operations and callers
 * that already know the target list ID.
 *
 * @param string $list_id    Sendy list ID.
 * @param string $email      Email address to subscribe.
 * @param string $name       Optional subscriber name.
 * @param string $source     Optional source identifier for tracking (e.g., 'homepage', 'bandcamp-scraper').
 * @param string $source_url Optional URL of the page where the form was submitted.
 * @return array Success status, message, and status code for bulk processing.
 */
function extrachill_subscribe_to_list( $list_id, $email, $name = '', $source = '', $source_url = '' ) {
	$ability = wp_get_ability( 'extrachill/subscribe' );

	if ( ! $ability ) {
		return array(
			'success' => false,
			'message' => __( 'Subscription service unavailable', 'extrachill-newsletter' ),
			'status'  => 'error',
		);
	}

	$result = $ability->execute(
		array(
			'email'      => $email,
			'list_id'    => $list_id,
			'name'       => $name,
			'context'    => $source,
			'source_url' => $source_url,
		)
	);

	if ( is_wp_error( $result ) ) {
		return array(
			'success' => false,
			'message' => $result->get_error_message(),
			'status'  => 'error',
		);
	}

	return $result;
}

/**
 * Send or update Sendy campaign
 *
 * Thin delegate to the extrachill/push-campaign ability (the single Sendy
 * campaign implementation), which builds the email content from the post and
 * creates or updates the campaign. The $email_data parameter is retained for
 * backward compatibility but is no longer used — the ability derives content
 * from $post_id directly.
 *
 * @param int   $post_id    Newsletter post ID.
 * @param array $email_data Deprecated, unused. Kept for signature compatibility.
 * @return true|WP_Error True on success, WP_Error on failure.
 */
function send_newsletter_campaign_to_sendy( $post_id, $email_data = array() ) {
	unset( $email_data );
	$ability = wp_get_ability( 'extrachill/push-campaign' );

	if ( ! $ability ) {
		return new WP_Error( 'push_campaign_unavailable', __( 'Campaign push ability unavailable', 'extrachill-newsletter' ) );
	}

	$result = $ability->execute( array( 'post_id' => $post_id ) );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	if ( empty( $result['success'] ) ) {
		$message = ! empty( $result['message'] ) ? $result['message'] : __( 'Failed to send campaign to Sendy', 'extrachill-newsletter' );
		return new WP_Error( 'sendy_campaign_failed', $message );
	}

	return true;
}
