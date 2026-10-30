<?php
/**
 * Campaign attribution (UTM) normalization.
 *
 * Email clients, apps and many social surfaces send no referrer, so a click
 * from a newsletter lands as direct traffic. Campaign parameters on the landing
 * URL are the only provenance those visits carry. The browser beacon reads
 * `utm_source`, `utm_medium` and `utm_campaign` from the landing URL and this
 * file reduces each one to a short, bounded slug before it reaches the
 * pageview event. Free text, emails, and anything else outside the slug
 * alphabet is dropped, so the fields can never carry PII.
 *
 * @package ExtraChill\Analytics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Campaign parameters recorded on pageview events.
 *
 * @return string[] UTM parameter names, in recording order.
 */
function extrachill_analytics_campaign_params() {
	return array( 'utm_source', 'utm_medium', 'utm_campaign' );
}

/**
 * Normalize one campaign parameter value to a bounded slug.
 *
 * Lowercases, maps whitespace and `+` to `-`, and rejects anything outside
 * `[a-z0-9._-]` rather than stripping it, so `user@example.com` never turns
 * into a plausible-looking slug.
 *
 * @param mixed $raw Raw parameter value from the client.
 * @return string Normalized slug, or '' when absent or not a safe slug.
 */
function extrachill_analytics_normalize_campaign_value( $raw ) {
	if ( ! is_string( $raw ) ) {
		return '';
	}

	$value = strtolower( trim( rawurldecode( $raw ) ) );
	$value = preg_replace( '/[\s+]+/', '-', $value );

	if ( '' === $value || strlen( $value ) > 64 || ! preg_match( '/^[a-z0-9][a-z0-9._-]*$/', $value ) ) {
		return '';
	}

	return $value;
}

/**
 * Extract normalized campaign attribution from ability input.
 *
 * @param array $input Ability input.
 * @return array<string,string> Present, valid campaign fields keyed by parameter name.
 */
function extrachill_analytics_campaign_from_input( array $input ) {
	$campaign = array();
	foreach ( extrachill_analytics_campaign_params() as $param ) {
		$value = isset( $input[ $param ] ) ? extrachill_analytics_normalize_campaign_value( $input[ $param ] ) : '';
		if ( '' !== $value ) {
			$campaign[ $param ] = $value;
		}
	}

	return $campaign;
}
