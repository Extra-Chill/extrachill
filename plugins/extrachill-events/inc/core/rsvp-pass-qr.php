<?php
/**
 * RSVP Pass QR
 *
 * Slice 2 of the RSVP perk pass feature (#877): a QR code encoding the
 * pass's verify URL, embedded in the on-screen pass card, the email, and
 * My Shows. Serves the QR PNG via admin-post.php (a lightweight WordPress
 * core action-dispatch endpoint, not a new rewrite rule) — the pass code
 * is not a secret the QR image itself protects; it is a bearer token the
 * attendee already possesses (see RsvpPassesTable's own docblock for why
 * pass codes are plaintext), so rendering its QR requires no additional
 * authorization beyond the pass existing and not being revoked.
 *
 * @package ExtraChillEvents
 * @since 0.70.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build the host-facing verify URL for a pass code.
 *
 * @param string $code Pass code, as issued.
 * @return string
 */
function extrachill_events_rsvp_verify_url( string $code ): string {
	return add_query_arg( 'code', rawurlencode( $code ), home_url( '/rsvp-verify/' ) );
}

/**
 * Build the QR image URL for a pass code.
 *
 * @param string $code Pass code, as issued.
 * @return string
 */
function extrachill_events_rsvp_pass_qr_url( string $code ): string {
	return extrachill_events_rsvp_pass_qr_base_url() . rawurlencode( $code );
}

/**
 * QR image URL prefix that a pass code is appended to.
 *
 * The browser builds the QR URL for a pass revealed without a reload, so it
 * needs a prefix ending in `code=`. Building it with add_query_arg() and an
 * empty code drops the `=` (WordPress omits empty values), which produced
 * `&codeABCDE-...` and a broken QR image.
 *
 * @return string
 */
function extrachill_events_rsvp_pass_qr_base_url(): string {
	return add_query_arg( 'action', 'ec_rsvp_pass_qr', admin_url( 'admin-post.php' ) ) . '&code=';
}

/**
 * Build the scoped permission grant for one pass's QR render.
 *
 * `extrachill/generate-qr-code` (extrachill-network) is deliberately
 * restricted to network admins, CLI, and Action Scheduler so it can't be
 * used as a public image-rendering service. The pass QR is requested
 * anonymously (mail-client image proxies) or by ordinary attendees, so the
 * ability's own permission check always denies it and the endpoint answered
 * 503 for everyone but super admins (#921).
 *
 * This grant does not widen the ability's contract. It is a
 * `wp_ability_permission_result` filter (WordPress core 7.1) that the
 * caller installs only after it has validated an active pass. It grants
 * exactly one ability and exactly one input: that pass's own verify URL.
 * Every other ability call, or any other URL, keeps its original result.
 *
 * @param string $verify_url The validated pass's verify URL.
 * @return callable Filter callback for `wp_ability_permission_result`.
 */
function extrachill_events_rsvp_pass_qr_permission_grant( string $verify_url ): callable {
	return static function ( $permission, $ability_name, $input = null ) use ( $verify_url ) {
		if ( 'extrachill/generate-qr-code' !== $ability_name ) {
			return $permission;
		}

		if ( ! is_array( $input ) || ! isset( $input['url'] ) || $verify_url !== $input['url'] ) {
			return $permission;
		}

		return true;
	};
}

/**
 * Generate the QR image for an already-validated pass code.
 *
 * Callers must validate the pass (it exists and is not revoked) before
 * calling this: it installs the scoped permission grant from
 * {@see extrachill_events_rsvp_pass_qr_permission_grant()} for the duration
 * of the one ability call and always removes it afterwards.
 *
 * @param string $code Validated pass code.
 * @return array|null The ability's `{ image, mime_type, ... }` result, or null on failure.
 */
function extrachill_events_generate_rsvp_pass_qr( string $code ): ?array {
	if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( 'extrachill/generate-qr-code' ) ) {
		return null;
	}

	$ability = wp_get_ability( 'extrachill/generate-qr-code' );
	if ( ! $ability ) {
		return null;
	}

	$verify_url = extrachill_events_rsvp_verify_url( $code );
	$grant      = extrachill_events_rsvp_pass_qr_permission_grant( $verify_url );

	add_filter( 'wp_ability_permission_result', $grant, 10, 3 );
	try {
		$result = $ability->execute(
			array(
				'url'  => $verify_url,
				'size' => 500,
			)
		);
	} finally {
		remove_filter( 'wp_ability_permission_result', $grant, 10 );
	}

	if ( ! is_array( $result ) || empty( $result['image'] ) ) {
		return null;
	}

	return $result;
}

/**
 * Serve the QR PNG for a pass code.
 *
 * Rejects an unknown or revoked code (nothing to encode); a redeemed pass's
 * QR still renders — redeeming again is a safe no-op (RsvpPassesTable::redeem()
 * reports the existing redemption rather than erroring), and an attendee
 * may still want to show their code for the host's records.
 *
 * @hook admin_post_ec_rsvp_pass_qr
 * @hook admin_post_nopriv_ec_rsvp_pass_qr
 */
function extrachill_events_serve_rsvp_pass_qr(): void {
	$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only image render keyed by an unguessable bearer code; there is no state-changing action to protect with a nonce.

	if ( '' === $code || ! class_exists( '\\ExtraChillEvents\\Core\\RsvpPassesTable' ) ) {
		status_header( 404 );
		exit;
	}

	$pass = \ExtraChillEvents\Core\RsvpPassesTable::find_by_code( $code );
	if ( ! $pass || \ExtraChillEvents\Core\RsvpPassesTable::STATUS_REVOKED === $pass['status'] ) {
		status_header( 404 );
		exit;
	}

	$result = extrachill_events_generate_rsvp_pass_qr( $code );

	if ( ! is_array( $result ) || empty( $result['image'] ) ) {
		status_header( 503 );
		exit;
	}

	nocache_headers();
	header( 'Content-Type: ' . ( ! empty( $result['mime_type'] ) ? (string) $result['mime_type'] : 'image/png' ) );
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode, WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw binary PNG bytes decoded from this plugin's own generate-qr-code ability's documented response contract; esc_html()/wp_kses() would corrupt the image, and the Content-Type header above declares the binary response.
	echo base64_decode( (string) $result['image'] );
	exit;
}
/**
 * Register the QR endpoint's hooks, guarded on the canonical events site —
 * matching every other hook registration in this plugin (this one was
 * initially unguarded; not a functional bug on production, where this
 * Network:false plugin only ever loads on events.extrachill.com, but
 * inconsistent with the established pattern and a real gap on any
 * multi-blog test/CI environment that loads the plugin more broadly).
 */
function extrachill_events_register_rsvp_pass_qr_hooks(): void {
	if ( ! function_exists( 'ec_is_events_site' ) || ! ec_is_events_site() ) {
		return;
	}

	add_action( 'admin_post_ec_rsvp_pass_qr', 'extrachill_events_serve_rsvp_pass_qr' );
	add_action( 'admin_post_nopriv_ec_rsvp_pass_qr', 'extrachill_events_serve_rsvp_pass_qr' );
}
add_action( 'init', 'extrachill_events_register_rsvp_pass_qr_hooks', 1 );
