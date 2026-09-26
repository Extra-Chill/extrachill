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
	return add_query_arg(
		array(
			'action' => 'ec_rsvp_pass_qr',
			'code'   => rawurlencode( $code ),
		),
		admin_url( 'admin-post.php' )
	);
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

	if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( 'extrachill/generate-qr-code' ) ) {
		status_header( 503 );
		exit;
	}

	$ability = wp_get_ability( 'extrachill/generate-qr-code' );
	$result  = $ability ? $ability->execute(
		array(
			'url'  => extrachill_events_rsvp_verify_url( $code ),
			'size' => 500,
		)
	) : null;

	if ( ! $result || is_wp_error( $result ) || empty( $result['image'] ) ) {
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
