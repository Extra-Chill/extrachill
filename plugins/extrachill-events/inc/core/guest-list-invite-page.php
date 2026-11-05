<?php
/**
 * Guest List Invite Page (extrachill#1128)
 *
 * The invited band's or management's private page:
 * `/events/{slug}/guest-invite/{token}/`, a core rewrite endpoint on the
 * event permalink like the /guest-list page (inc/core/guest-list-page.php).
 * The token in the link is the whole credential: no password, no account.
 * The page shows the spots they have, an add-a-name form, and the names
 * they already added, each with its pass link and a Remove button.
 *
 * Unknown tokens, or a token for another event, answer 404. A revoked
 * invite renders a short "cancelled by the venue" notice. The page is never
 * cached, never indexed, and sends no referrer, so the token never leaves
 * the page through a link click.
 *
 * Forms post to admin-post.php (nopriv too); the token rides along and the
 * extrachill/party-* abilities own validation.
 *
 * @package ExtraChillEvents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the endpoint on single-post permalinks.
 *
 * @hook init
 */
function extrachill_events_guest_invite_rewrite_endpoint(): void {
	if ( ! function_exists( 'ec_is_events_site' ) || ! ec_is_events_site() ) {
		return;
	}

	add_rewrite_endpoint( EXTRACHILL_EVENTS_GUEST_INVITE_ENDPOINT, EP_PERMALINK );
}
add_action( 'init', 'extrachill_events_guest_invite_rewrite_endpoint' );

/**
 * Whether the current request targets an event's invite endpoint.
 *
 * @return bool
 */
function extrachill_events_is_guest_invite_request(): bool {
	global $wp_query;

	return is_singular( 'data_machine_events' )
		&& $wp_query instanceof WP_Query
		&& array_key_exists( EXTRACHILL_EVENTS_GUEST_INVITE_ENDPOINT, (array) $wp_query->query );
}

/**
 * Raw token from the current invite request.
 *
 * @return string
 */
function extrachill_events_guest_invite_request_token(): string {
	return strtolower( trim( (string) get_query_var( EXTRACHILL_EVENTS_GUEST_INVITE_ENDPOINT, '' ), '/ ' ) );
}

/**
 * The party behind the current invite request, resolved once.
 *
 * @return array|WP_Error|null Party row, a resolve error, or null when not an invite request.
 */
function extrachill_events_guest_invite_request_party() {
	static $resolved = array();

	if ( ! extrachill_events_is_guest_invite_request() ) {
		return null;
	}

	$token = extrachill_events_guest_invite_request_token();
	if ( ! array_key_exists( $token, $resolved ) ) {
		$party = extrachill_events_resolve_guest_list_party( $token );
		// A real token for some other event is not a link to this page.
		if ( ! is_wp_error( $party ) && (int) get_queried_object_id() !== (int) $party['event_id'] ) {
			$party = new WP_Error( 'invalid_invite', __( 'This invite link is not valid.', 'extrachill-events' ), array( 'status' => 404 ) );
		}
		$resolved[ $token ] = $party;
	}

	return $resolved[ $token ];
}

/**
 * 404 unknown tokens; keep the page out of caches, search, and referrers.
 *
 * @hook template_redirect
 */
function extrachill_events_guest_invite_template_redirect(): void {
	$party = extrachill_events_guest_invite_request_party();
	if ( null === $party ) {
		return;
	}

	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	nocache_headers();
	header( 'Referrer-Policy: no-referrer' );
	add_filter( 'wp_robots', 'wp_robots_no_robots' );

	if ( is_wp_error( $party ) && 'invite_revoked' !== $party->get_error_code() ) {
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		return;
	}

	wp_enqueue_script( 'ec-guest-list-management', EXTRACHILL_EVENTS_PLUGIN_URL . 'assets/js/guest-list-management.js', array(), EXTRACHILL_EVENTS_VERSION, true );
	wp_localize_script(
		'ec-guest-list-management',
		'ecGuestListManagement',
		array(
			'copied'   => __( 'Pass link copied.', 'extrachill-events' ),
			'copyHint' => __( 'Select and copy the link above.', 'extrachill-events' ),
		)
	);
}
add_action( 'template_redirect', 'extrachill_events_guest_invite_template_redirect', 5 );

/**
 * Render the invite template instead of the single event template.
 *
 * @hook template_include
 * @param string $template Resolved template.
 * @return string
 */
function extrachill_events_guest_invite_template( $template ) {
	if ( is_404() || ! extrachill_events_is_guest_invite_request() ) {
		return $template;
	}

	return EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/templates/guest-list-invite.php';
}
add_filter( 'template_include', 'extrachill_events_guest_invite_template', 99 );

/**
 * Prefix the document title.
 *
 * @hook document_title_parts
 * @param array $parts Title parts.
 * @return array
 */
function extrachill_events_guest_invite_title( $parts ) {
	if ( extrachill_events_is_guest_invite_request() && isset( $parts['title'] ) ) {
		/* translators: %s: event title. */
		$parts['title'] = sprintf( __( 'Your guest list: %s', 'extrachill-events' ), $parts['title'] );
	}
	return $parts;
}
add_filter( 'document_title_parts', 'extrachill_events_guest_invite_title' );

/**
 * Run a party ability from the invite page form, then return to the page.
 *
 * @param string $ability_name Ability to execute.
 * @param array  $input        Ability input (includes the token).
 * @param bool   $flash_code   Whether to return with the new pass code (`?added=`) so the page can show its link.
 */
function extrachill_events_run_guest_invite_form( string $ability_name, array $input, bool $flash_code = false ): void {
	$token = (string) ( $input['token'] ?? '' );
	$party = extrachill_events_resolve_guest_list_party( $token );
	if ( is_wp_error( $party ) ) {
		wp_die( esc_html( $party->get_error_message() ), '', array( 'response' => (int) ( $party->get_error_data()['status'] ?? 403 ) ) );
	}

	check_admin_referer( 'ec_guest_party_' . (int) $party['id'] );

	$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( $ability_name ) : null;
	if ( ! $ability ) {
		wp_die( esc_html__( 'The guest list is unavailable.', 'extrachill-events' ), '', array( 'response' => 503 ) );
	}

	$result = $ability->execute( $input );
	if ( is_wp_error( $result ) ) {
		$data = $result->get_error_data();
		wp_die(
			esc_html( $result->get_error_message() ),
			'',
			array(
				'response'  => is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400,
				'back_link' => true,
			)
		);
	}

	$url = extrachill_events_guest_list_party_invite_url( (int) $party['event_id'], $token );
	if ( $flash_code && ! empty( $result['code'] ) ) {
		$url = add_query_arg( 'added', rawurlencode( (string) $result['code'] ), $url );
	}

	header( 'Referrer-Policy: no-referrer' );
	wp_safe_redirect( $url . '#ec-guest-invite-names' );
	exit;
}

/**
 * Invite page "Add a name" form.
 */
function extrachill_events_guest_invite_add_name(): void {
	// Nonce is verified inside extrachill_events_run_guest_invite_form() once the token resolves the party.
	// phpcs:disable WordPress.Security.NonceVerification.Missing
	extrachill_events_run_guest_invite_form(
		'extrachill/party-add-guest-list-name',
		array(
			'token' => isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '',
			'name'  => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
			'email' => isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '',
		),
		true
	);
	// phpcs:enable WordPress.Security.NonceVerification.Missing
}
add_action( 'admin_post_ec_guest_party_add', 'extrachill_events_guest_invite_add_name' );
add_action( 'admin_post_nopriv_ec_guest_party_add', 'extrachill_events_guest_invite_add_name' );

/**
 * Invite page "Remove" button.
 */
function extrachill_events_guest_invite_remove_name(): void {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified inside extrachill_events_run_guest_invite_form().
	extrachill_events_run_guest_invite_form(
		'extrachill/party-remove-guest-list-name',
		array(
			'token' => isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '',
			'code'  => isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '',
		)
	);
	// phpcs:enable WordPress.Security.NonceVerification.Missing
}
add_action( 'admin_post_ec_guest_party_remove', 'extrachill_events_guest_invite_remove_name' );
add_action( 'admin_post_nopriv_ec_guest_party_remove', 'extrachill_events_guest_invite_remove_name' );
