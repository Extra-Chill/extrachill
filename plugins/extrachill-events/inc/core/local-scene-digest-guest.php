<?php
/**
 * Email-only (guest) signup for the weekly Local Scene digest (#989).
 *
 * Same shape as guest RSVP (inc/core/rsvp-guest-service.php):
 *
 *   1. `extrachill/resolve-account-by-email` reuses an account or creates an
 *      unclaimed one and hands back a set-password `claim_url`.
 *   2. One email goes out: "Confirm to get this week's shows in {city}",
 *      carrying the claim link when the account is unclaimed.
 *   3. Nothing is subscribed until the inbox owner confirms, so a stranger
 *      can't sign someone else's inbox up for weekly mail. The confirm link
 *      is signed (like the digest's unsubscribe link) and lands on a
 *      scanner-safe page whose button POSTs the confirmation.
 *   4. Confirming records the subscription through the extrachill-users
 *      entity-subscription service, so the existing sender and signed
 *      unsubscribe work unchanged, and saves the city as the account's
 *      Local Scene only when it has none.
 *
 * Every request also records which city was asked for on the account
 * (`_ec_local_scene_digest_requests` user meta, #465) so an unconfirmed
 * request is not lost. That record is not a subscription and never sets the
 * Local Scene; confirming stamps it so the request→confirm funnel per city
 * stays readable.
 *
 * The public response is always `{ status: 'check_inbox' }`: it never says
 * whether the address had an account, was throttled, or was already in.
 *
 * @package ExtraChillEvents
 */

defined( 'ABSPATH' ) || exit;

const EXTRACHILL_EVENTS_GUEST_DIGEST_CONFIRM_TTL = 604800; // 7 days; a literal so loading never depends on WP time constants.
const EXTRACHILL_EVENTS_GUEST_DIGEST_QUERY_VAR   = 'ec_digest_confirm';

/*
 * Pending/confirmed guest digest city requests, one meta row per account:
 *
 *   array<string slug, array{requested_at:int|null, confirmed_at:int|null}>
 *
 * Unix timestamps. `requested_at` is the first request (repeats never move
 * it); `confirmed_at` is null until the signed link is confirmed. Bounded to
 * EXTRACHILL_EVENTS_GUEST_DIGEST_REQUESTS_MAX cities; the oldest entry drops
 * first. Core deletes it with the rest of the user's meta on account
 * deletion; the privacy exporter/eraser below cover export/erasure requests.
 */
const EXTRACHILL_EVENTS_GUEST_DIGEST_REQUESTS_META = '_ec_local_scene_digest_requests';
const EXTRACHILL_EVENTS_GUEST_DIGEST_REQUESTS_MAX  = 20;

/**
 * Resolve a digest-eligible city (city-depth location term) by slug.
 *
 * @param string $slug Location slug.
 * @return WP_Term|null
 */
function extrachill_events_guest_digest_city( string $slug ): ?WP_Term {
	$slug = sanitize_title( $slug );
	if ( '' === $slug ) {
		return null;
	}

	$term = get_term_by( 'slug', $slug, EXTRACHILL_EVENTS_LOCAL_SCENE_DIGEST_TAXONOMY );
	if ( ! $term instanceof WP_Term || count( get_ancestors( $term->term_id, EXTRACHILL_EVENTS_LOCAL_SCENE_DIGEST_TAXONOMY, 'taxonomy' ) ) < 2 ) {
		return null;
	}

	return $term;
}

/**
 * Seconds before a repeat signup for the same inbox and city re-sends.
 *
 * @return int
 */
function extrachill_events_guest_digest_resend_window(): int {
	/**
	 * Filters the guest digest confirmation re-send window in seconds.
	 *
	 * @param int $window Default 10 minutes.
	 */
	return max( 0, (int) apply_filters( 'extrachill_events_guest_digest_resend_window', 10 * MINUTE_IN_SECONDS ) );
}

/**
 * Transient key bounding confirmation emails per user and city.
 *
 * @param int    $user_id User ID.
 * @param string $slug    City slug.
 * @return string
 */
function extrachill_events_guest_digest_resend_key( int $user_id, string $slug ): string {
	return 'ec_guest_digest_' . $user_id . '_' . md5( $slug );
}

/**
 * Ask for the weekly digest by email. Response is deliberately opaque.
 *
 * @param string $slug        City (location) slug.
 * @param string $email       Email address.
 * @param array  $attribution Optional `referrer` (string) and `utm` (array).
 * @return array|WP_Error `{ status: 'check_inbox' }` or a validation error.
 */
function extrachill_events_guest_subscribe_local_scene_digest( string $slug, string $email, array $attribution = array() ) {
	$term = extrachill_events_guest_digest_city( $slug );
	if ( null === $term ) {
		return new WP_Error( 'city_not_found', __( 'That city could not be found.', 'extrachill-events' ), array( 'status' => 404 ) );
	}

	$email = sanitize_email( $email );
	if ( '' === $email || ! is_email( $email ) ) {
		return new WP_Error( 'invalid_email', __( 'Enter a valid email address.', 'extrachill-events' ), array( 'status' => 400 ) );
	}

	$resolve = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'extrachill/resolve-account-by-email' ) : null;
	if ( ! $resolve || ! function_exists( 'ec_send_email_queued' ) ) {
		return new WP_Error( 'guest_digest_unavailable', __( 'Email signup is temporarily unavailable.', 'extrachill-events' ), array( 'status' => 503 ) );
	}

	$resolve_input = array(
		'email'               => $email,
		'registration_source' => 'local_scene_digest',
	);
	if ( ! empty( $attribution['referrer'] ) ) {
		$resolve_input['referrer'] = (string) $attribution['referrer'];
	}
	if ( ! empty( $attribution['utm'] ) && is_array( $attribution['utm'] ) ) {
		$resolve_input['utm'] = $attribution['utm'];
	}

	$account = $resolve->execute( $resolve_input );
	if ( is_wp_error( $account ) ) {
		return 'invalid_email' === $account->get_error_code()
			? $account
			: new WP_Error( 'guest_digest_failed', __( 'Something went wrong. Try again.', 'extrachill-events' ), array( 'status' => 500 ) );
	}

	$user_id = (int) ( $account['user_id'] ?? 0 );
	if ( $user_id <= 0 ) {
		return new WP_Error( 'guest_digest_failed', __( 'Something went wrong. Try again.', 'extrachill-events' ), array( 'status' => 500 ) );
	}

	// Record the city before the resend throttle so every request is kept.
	extrachill_events_record_guest_digest_request( $user_id, $term->slug );

	$throttle = extrachill_events_guest_digest_resend_key( $user_id, $term->slug );
	if ( false === get_transient( $throttle ) ) {
		$sent = extrachill_events_send_guest_digest_confirm_email( $user_id, $email, $term, (string) ( $account['claim_url'] ?? '' ) );
		if ( $sent && extrachill_events_guest_digest_resend_window() > 0 ) {
			set_transient( $throttle, 1, extrachill_events_guest_digest_resend_window() );
		}
	}

	return array( 'status' => 'check_inbox' );
}

/**
 * Read an account's guest digest city requests.
 *
 * @param int $user_id User ID.
 * @return array<string, array{requested_at:int|null, confirmed_at:int|null}>
 */
function extrachill_events_get_guest_digest_requests( int $user_id ): array {
	if ( $user_id <= 0 ) {
		return array();
	}

	$stored = get_user_meta( $user_id, EXTRACHILL_EVENTS_GUEST_DIGEST_REQUESTS_META, true );
	if ( ! is_array( $stored ) ) {
		return array();
	}

	$requests = array();
	foreach ( $stored as $slug => $entry ) {
		$slug = sanitize_title( (string) $slug );
		if ( '' === $slug || ! is_array( $entry ) ) {
			continue;
		}
		$requests[ $slug ] = array(
			'requested_at' => isset( $entry['requested_at'] ) ? (int) $entry['requested_at'] : null,
			'confirmed_at' => isset( $entry['confirmed_at'] ) ? (int) $entry['confirmed_at'] : null,
		);
	}

	return $requests;
}

/**
 * Persist requests, keeping the newest EXTRACHILL_EVENTS_GUEST_DIGEST_REQUESTS_MAX.
 *
 * @param int   $user_id  User ID.
 * @param array $requests Slug-keyed request map.
 */
function extrachill_events_save_guest_digest_requests( int $user_id, array $requests ): void {
	if ( count( $requests ) > EXTRACHILL_EVENTS_GUEST_DIGEST_REQUESTS_MAX ) {
		uasort(
			$requests,
			static function ( array $a, array $b ): int {
				return ( $b['requested_at'] ?? $b['confirmed_at'] ?? 0 ) <=> ( $a['requested_at'] ?? $a['confirmed_at'] ?? 0 );
			}
		);
		$requests = array_slice( $requests, 0, EXTRACHILL_EVENTS_GUEST_DIGEST_REQUESTS_MAX, true );
	}

	update_user_meta( $user_id, EXTRACHILL_EVENTS_GUEST_DIGEST_REQUESTS_META, $requests );
}

/**
 * Record that this account asked for a city's digest. Idempotent: a repeat
 * request for a city already on record changes nothing, so `requested_at`
 * stays the first ask. Does not subscribe or touch the Local Scene.
 *
 * @param int    $user_id User ID.
 * @param string $slug    City slug.
 */
function extrachill_events_record_guest_digest_request( int $user_id, string $slug ): void {
	$slug     = sanitize_title( $slug );
	$requests = extrachill_events_get_guest_digest_requests( $user_id );
	if ( '' === $slug || $user_id <= 0 || isset( $requests[ $slug ] ) ) {
		return;
	}

	$requests[ $slug ] = array(
		'requested_at' => time(),
		'confirmed_at' => null,
	);
	extrachill_events_save_guest_digest_requests( $user_id, $requests );
}

/**
 * Mark a city's request confirmed. Kept (not cleared) so the per-city
 * request→confirm funnel survives. A confirm with no request on record
 * (link minted before requests were recorded) is stored with a null
 * `requested_at`.
 *
 * @param int    $user_id User ID.
 * @param string $slug    City slug.
 */
function extrachill_events_mark_guest_digest_request_confirmed( int $user_id, string $slug ): void {
	$slug     = sanitize_title( $slug );
	$requests = extrachill_events_get_guest_digest_requests( $user_id );
	if ( '' === $slug || $user_id <= 0 || null !== ( $requests[ $slug ]['confirmed_at'] ?? null ) ) {
		return;
	}

	$requests[ $slug ] = array(
		'requested_at' => $requests[ $slug ]['requested_at'] ?? null,
		'confirmed_at' => time(),
	);
	extrachill_events_save_guest_digest_requests( $user_id, $requests );
}

/**
 * Register the guest digest request privacy exporter.
 *
 * @hook wp_privacy_personal_data_exporters
 * @param array $exporters Exporters.
 * @return array
 */
function extrachill_events_register_guest_digest_privacy_exporter( array $exporters ): array {
	$exporters['extrachill-events-guest-digest-requests'] = array(
		'exporter_friendly_name' => __( 'Extra Chill weekly email requests', 'extrachill-events' ),
		'callback'               => 'extrachill_events_export_guest_digest_requests',
	);
	return $exporters;
}
add_filter( 'wp_privacy_personal_data_exporters', 'extrachill_events_register_guest_digest_privacy_exporter' );

/**
 * Register the guest digest request privacy eraser.
 *
 * @hook wp_privacy_personal_data_erasers
 * @param array $erasers Erasers.
 * @return array
 */
function extrachill_events_register_guest_digest_privacy_eraser( array $erasers ): array {
	$erasers['extrachill-events-guest-digest-requests'] = array(
		'eraser_friendly_name' => __( 'Extra Chill weekly email requests', 'extrachill-events' ),
		'callback'             => 'extrachill_events_erase_guest_digest_requests',
	);
	return $erasers;
}
add_filter( 'wp_privacy_personal_data_erasers', 'extrachill_events_register_guest_digest_privacy_eraser' );

/**
 * Core privacy exporter callback.
 *
 * @param string $email_address Email address.
 * @param int    $page          Page (single page).
 * @return array{data:array,done:bool}
 */
function extrachill_events_export_guest_digest_requests( string $email_address, int $page = 1 ): array {
	unset( $page );
	$user = get_user_by( 'email', $email_address );
	$data = array();
	if ( $user ) {
		foreach ( extrachill_events_get_guest_digest_requests( (int) $user->ID ) as $slug => $entry ) {
			$data[] = array(
				'group_id'    => 'extrachill-events-guest-digest-requests',
				'group_label' => __( 'Weekly email requests', 'extrachill-events' ),
				'item_id'     => 'guest-digest-request-' . $slug,
				'data'        => array(
					array(
						'name'  => __( 'City', 'extrachill-events' ),
						'value' => $slug,
					),
					array(
						'name'  => __( 'Requested', 'extrachill-events' ),
						'value' => null === $entry['requested_at'] ? '' : gmdate( 'c', $entry['requested_at'] ),
					),
					array(
						'name'  => __( 'Confirmed', 'extrachill-events' ),
						'value' => null === $entry['confirmed_at'] ? '' : gmdate( 'c', $entry['confirmed_at'] ),
					),
				),
			);
		}
	}

	return array(
		'data' => $data,
		'done' => true,
	);
}

/**
 * Core privacy eraser callback.
 *
 * @param string $email_address Email address.
 * @param int    $page          Page (single page).
 * @return array{items_removed:bool,items_retained:bool,messages:array,done:bool}
 */
function extrachill_events_erase_guest_digest_requests( string $email_address, int $page = 1 ): array {
	unset( $page );
	$user    = get_user_by( 'email', $email_address );
	$removed = false;
	if ( $user && array() !== extrachill_events_get_guest_digest_requests( (int) $user->ID ) ) {
		$removed = (bool) delete_user_meta( (int) $user->ID, EXTRACHILL_EVENTS_GUEST_DIGEST_REQUESTS_META );
	}

	return array(
		'items_removed'  => $removed,
		'items_retained' => false,
		'messages'       => array(),
		'done'           => true,
	);
}

/**
 * Sign a confirm link. The `confirm|` prefix keeps these from ever matching
 * an unsubscribe signature over the same user, city and expiry.
 *
 * @param int    $user_id User ID.
 * @param string $slug    City slug.
 * @param int    $expires Expiry timestamp.
 * @return string
 */
function extrachill_events_guest_digest_signature( int $user_id, string $slug, int $expires ): string {
	return hash_hmac( 'sha256', 'confirm|' . $user_id . '|' . $slug . '|' . $expires, wp_salt( 'auth' ) );
}

/**
 * Mint the signed confirm link, landing on the city's calendar.
 *
 * @param int      $user_id User ID.
 * @param WP_Term  $term    City term.
 * @param int|null $expires Optional expiry timestamp.
 * @return string
 */
function extrachill_events_guest_digest_confirm_url( int $user_id, WP_Term $term, ?int $expires = null ): string {
	$expires = $expires ?? ( time() + EXTRACHILL_EVENTS_GUEST_DIGEST_CONFIRM_TTL );
	$base    = get_term_link( $term );
	$base    = is_wp_error( $base ) ? home_url( '/' ) : (string) $base;

	return extrachill_events_local_scene_digest_add_query_args(
		$base,
		array(
			EXTRACHILL_EVENTS_GUEST_DIGEST_QUERY_VAR => 1,
			'user'                                   => $user_id,
			'location'                               => $term->slug,
			'expires'                                => $expires,
			'signature'                              => extrachill_events_guest_digest_signature( $user_id, $term->slug, $expires ),
		)
	);
}

/**
 * Verify a confirm link's parameters. Does not change anything.
 *
 * @param array $params `user`, `location`, `expires`, `signature`.
 * @return array{user_id:int,term:WP_Term,expires:int,signature:string}|WP_Error
 */
function extrachill_events_verify_guest_digest_confirm( array $params ) {
	$user_id   = absint( $params['user'] ?? 0 );
	$slug      = sanitize_title( (string) ( $params['location'] ?? '' ) );
	$expires   = absint( $params['expires'] ?? 0 );
	$signature = is_scalar( $params['signature'] ?? null ) ? (string) $params['signature'] : '';
	$invalid   = new WP_Error( 'invalid_guest_digest_confirm', __( 'This confirmation link is invalid or expired.', 'extrachill-events' ), array( 'status' => 403 ) );

	if ( $user_id <= 0 || '' === $slug || $expires < time() || $expires > time() + EXTRACHILL_EVENTS_GUEST_DIGEST_CONFIRM_TTL ) {
		return $invalid;
	}
	if ( ! hash_equals( extrachill_events_guest_digest_signature( $user_id, $slug, $expires ), $signature ) ) {
		return $invalid;
	}

	$term = extrachill_events_guest_digest_city( $slug );
	if ( null === $term || ! get_userdata( $user_id ) ) {
		return $invalid;
	}

	return array(
		'user_id'   => $user_id,
		'term'      => $term,
		'expires'   => $expires,
		'signature' => $signature,
	);
}

/**
 * Record a confirmed guest subscription.
 *
 * @param array $params Confirm link parameters.
 * @return array{subscribed:bool,term:WP_Term}|WP_Error
 */
function extrachill_events_confirm_guest_digest( array $params ) {
	$verified = extrachill_events_verify_guest_digest_confirm( $params );
	if ( is_wp_error( $verified ) ) {
		return $verified;
	}
	if ( ! function_exists( 'extrachill_users_subscribe_to_entity' ) ) {
		return new WP_Error( 'guest_digest_unavailable', __( 'Email signup is temporarily unavailable.', 'extrachill-events' ), array( 'status' => 503 ) );
	}

	$user_id = $verified['user_id'];
	$term    = $verified['term'];

	extrachill_events_digest_subscription_context(
		array(
			'surface' => 'email_confirm',
			'via'     => 'guest',
		)
	);

	$result = extrachill_users_subscribe_to_entity( $user_id, EXTRACHILL_EVENTS_LOCAL_SCENE_DIGEST_ENTITY, EXTRACHILL_EVENTS_LOCAL_SCENE_DIGEST_TAXONOMY, $term->slug );
	if ( is_wp_error( $result ) || empty( $result['subscribed'] ) ) {
		return new WP_Error( 'guest_digest_failed', __( 'Something went wrong. Try again.', 'extrachill-events' ), array( 'status' => 500 ) );
	}

	extrachill_events_mark_guest_digest_request_confirmed( $user_id, $term->slug );

	// Save the city as the account's Local Scene only when it has none.
	if ( function_exists( 'extrachill_users_get_local_scene' ) && function_exists( 'extrachill_users_set_local_scene' ) && null === extrachill_users_get_local_scene( $user_id ) ) {
		extrachill_users_set_local_scene( $user_id, $term->slug );
	}

	return array(
		'subscribed' => true,
		'term'       => $term,
	);
}

/**
 * Build the confirmation email body.
 *
 * @param WP_Term $term        City.
 * @param string  $confirm_url Signed confirm link.
 * @param string  $claim_url   Optional set-password link for an unclaimed account.
 * @return string
 */
function extrachill_events_render_guest_digest_email_body( WP_Term $term, string $confirm_url, string $claim_url = '' ): string {
	/* translators: %s: City name. */
	$html  = '<p>' . sprintf( esc_html__( "Confirm and we'll send you upcoming shows in %s once a week.", 'extrachill-events' ), esc_html( $term->name ) ) . '</p>';
	$html .= '<p><a href="' . esc_url( $confirm_url ) . '">' . esc_html__( 'Confirm the weekly email', 'extrachill-events' ) . '</a></p>';
	$html .= '<p>' . esc_html__( "Didn't ask for this? Ignore this email and nothing happens.", 'extrachill-events' ) . '</p>';

	if ( '' !== $claim_url ) {
		$html .= '<hr><p>' . esc_html__( 'We made you an Extra Chill account with this email. Set a password any time to save shows and RSVP faster:', 'extrachill-events' ) . '</p>';
		$html .= '<p><a href="' . esc_url( $claim_url ) . '">' . esc_html__( 'Set your password', 'extrachill-events' ) . '</a></p>';
	}

	return $html;
}

/**
 * Queue the one confirmation email.
 *
 * @param int     $user_id   User ID.
 * @param string  $email     Recipient.
 * @param WP_Term $term      City.
 * @param string  $claim_url Optional set-password link.
 * @return bool Whether the send was queued.
 */
function extrachill_events_send_guest_digest_confirm_email( int $user_id, string $email, WP_Term $term, string $claim_url = '' ): bool {
	$confirm_url = extrachill_events_guest_digest_confirm_url( $user_id, $term );
	$result      = ec_send_email_queued(
		array(
			'to'        => $email,
			/* translators: %s: City name. */
			'subject'   => sprintf( __( "Confirm to get this week's shows in %s", 'extrachill-events' ), $term->name ),
			'from_name' => 'Extra Chill Events',
			'template'  => 'extrachill/branded',
			'context'   => array(
				'body_html' => extrachill_events_render_guest_digest_email_body( $term, $confirm_url, $claim_url ),
				'cta_url'   => $confirm_url,
				'cta_label' => __( 'Confirm', 'extrachill-events' ),
			),
		)
	);

	$queued = true === ( $result['success'] ?? false );
	if ( ! $queued ) {
		do_action(
			'datamachine_log',
			'error',
			'Local Scene digest confirmation email was not queued',
			array(
				'user_id'    => $user_id,
				'error_code' => (string) ( $result['error_code'] ?? '' ),
			)
		);
	}

	return $queued;
}

/**
 * Handle a confirm link: GET shows one button (link scanners never
 * subscribe anyone), POST records the subscription.
 *
 * @hook template_redirect
 */
function extrachill_events_handle_guest_digest_confirm(): void {
	// phpcs:disable WordPress.Security.NonceVerification -- The HMAC signature is the authorization; there is no session to bind a nonce to.
	$source = 'POST' === ( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( (string) wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) ? $_POST : $_GET;
	if ( empty( $source[ EXTRACHILL_EVENTS_GUEST_DIGEST_QUERY_VAR ] ) ) {
		return;
	}

	$params = array();
	foreach ( array( 'user', 'location', 'expires', 'signature' ) as $key ) {
		$params[ $key ] = isset( $source[ $key ] ) && is_scalar( $source[ $key ] ) ? sanitize_text_field( (string) wp_unslash( $source[ $key ] ) ) : '';
	}
	$is_post = $source === $_POST;
	// phpcs:enable WordPress.Security.NonceVerification

	$page = extrachill_events_guest_digest_confirm_page( $params, $is_post );
	extrachill_events_render_local_scene_digest_unsubscribe_page( $page['title'], $page['message'], $page['action_html'], $page['page_assets'] );
}
add_action( 'template_redirect', 'extrachill_events_handle_guest_digest_confirm', 1 );

/**
 * Decide what a confirm link request shows, subscribing only on POST.
 *
 * The GET page is the one a person lands on from the email, so it opts in to
 * standalone page assets: Extra Chill Analytics loads its view tracker there,
 * which mints the first-party `ec_vid` visitor cookie in the browser. The
 * button's POST then carries that cookie, so the visitor classifier can count
 * the `local_scene_digest_subscribed` event as a person instead of a bot.
 * Scanners that only GET still never subscribe anyone.
 *
 * @param array $params  Confirm link parameters (`user`, `location`, `expires`, `signature`).
 * @param bool  $is_post Whether the request is the button's POST.
 * @return array{title:string,message:string,action_html:string,page_assets:bool}
 */
function extrachill_events_guest_digest_confirm_page( array $params, bool $is_post ): array {
	$page = array(
		'title'       => __( 'Confirmation link invalid', 'extrachill-events' ),
		'message'     => __( 'This link is invalid or has expired. Ask for the weekly email again from any city calendar.', 'extrachill-events' ),
		'action_html' => '',
		'page_assets' => false,
	);

	$verified = extrachill_events_verify_guest_digest_confirm( $params );
	if ( is_wp_error( $verified ) ) {
		return $page;
	}

	$term = $verified['term'];
	if ( ! $is_post ) {
		return array(
			/* translators: %s: City name. */
			'title'       => sprintf( __( "Get this week's shows in %s?", 'extrachill-events' ), $term->name ),
			'message'     => __( 'One email a week. Unsubscribe from any email.', 'extrachill-events' ),
			'action_html' => extrachill_events_build_guest_digest_confirm_form( $params ),
			'page_assets' => true,
		);
	}

	$result = extrachill_events_confirm_guest_digest( $params );
	if ( is_wp_error( $result ) ) {
		$page['title']   = __( 'Something went wrong', 'extrachill-events' );
		$page['message'] = __( 'Your subscription could not be saved. Open the link from your email again to retry.', 'extrachill-events' );
		return $page;
	}

	$calendar = get_term_link( $term );
	return array(
		'title'       => __( "You're in", 'extrachill-events' ),
		/* translators: %s: City name. */
		'message'     => sprintf( __( 'Upcoming shows in %s will land in your inbox once a week.', 'extrachill-events' ), $term->name ),
		'action_html' => is_wp_error( $calendar ) ? '' : '<form><a href="' . esc_url( (string) $calendar ) . '">' . esc_html__( 'See the calendar', 'extrachill-events' ) . '</a></form>',
		'page_assets' => false,
	);
}

/**
 * Build the confirm POST form for the scanner-safe GET page.
 *
 * @param array $params Verified confirm parameters.
 * @return string Escaped markup.
 */
function extrachill_events_build_guest_digest_confirm_form( array $params ): string {
	$html  = '<form method="post" action="">';
	$html .= '<input type="hidden" name="' . esc_attr( EXTRACHILL_EVENTS_GUEST_DIGEST_QUERY_VAR ) . '" value="1">';
	foreach ( array( 'user', 'location', 'expires', 'signature' ) as $key ) {
		$html .= '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) ( $params[ $key ] ?? '' ) ) . '">';
	}
	$html .= '<button type="submit">' . esc_html__( 'Yes, send me the weekly email', 'extrachill-events' ) . '</button></form>';

	return $html;
}

/**
 * Render the logged-out email form for a city's weekly digest.
 *
 * Templates fire `extrachill_events_render_guest_digest_form` for logged-out
 * visitors; this is the only listener. Signed-in states never call it.
 *
 * @hook extrachill_events_render_guest_digest_form
 * @param mixed  $term      City term (anything else renders nothing).
 * @param string $placement Analytics placement (event_digest_offer|archive_scene).
 */
function extrachill_events_render_guest_digest_form( $term, string $placement = '' ): void {
	if ( ! $term instanceof WP_Term || is_user_logged_in() ) {
		return;
	}

	extrachill_events_enqueue_guest_digest_assets();
	$id = 'ec-digest-guest-email-' . sanitize_key( $placement . '-' . $term->term_id );
	?>
	<form class="ec-digest-guest" data-ec-digest-guest data-location-slug="<?php echo esc_attr( $term->slug ); ?>" novalidate>
		<input type="email" id="<?php echo esc_attr( $id ); ?>" aria-label="<?php esc_attr_e( 'Email', 'extrachill-events' ); ?>" name="email" autocomplete="email" placeholder="<?php esc_attr_e( 'you@example.com', 'extrachill-events' ); ?>" required>
		<button type="submit" class="button-1 button-small" data-ec-track="<?php echo esc_attr( 'digest-guest-' . sanitize_key( $placement ) ); ?>"><?php esc_html_e( "Get this week's shows", 'extrachill-events' ); ?></button>
		<?php
		if ( function_exists( 'ec_render_turnstile_widget' ) ) {
			echo wp_kses_post( ec_render_turnstile_widget( array( 'data-appearance' => 'interaction-only' ) ) );
		}
		?>
		<div class="notice ec-digest-guest__status" role="status" hidden></div>
	</form>
	<?php
}
add_action( 'extrachill_events_render_guest_digest_form', 'extrachill_events_render_guest_digest_form', 10, 2 );

/** Enqueue the guest form script, Turnstile, and its route config once. */
function extrachill_events_enqueue_guest_digest_assets(): void {
	if ( wp_script_is( 'extrachill-events-guest-digest', 'enqueued' ) ) {
		return;
	}

	if ( function_exists( 'ec_enqueue_turnstile_script' ) ) {
		ec_enqueue_turnstile_script();
	}

	wp_enqueue_script( 'extrachill-events-guest-digest', EXTRACHILL_EVENTS_PLUGIN_URL . 'assets/js/guest-digest.js', array( 'wp-api-fetch' ), EXTRACHILL_EVENTS_VERSION, true );
	wp_localize_script(
		'extrachill-events-guest-digest',
		'ecGuestDigest',
		array(
			'path' => '/extrachill/v1/events/local-scene-digest/guest-subscribe',
			'text' => array(
				'sending' => __( 'Sending...', 'extrachill-events' ),
				'success' => __( 'Check your inbox and tap Confirm. That\'s it.', 'extrachill-events' ),
				'error'   => __( 'Something went wrong. Check your email address and try again.', 'extrachill-events' ),
			),
		)
	);
}
