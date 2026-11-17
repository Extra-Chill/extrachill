<?php
/**
 * Artist subscriber unsubscribe: signed links and member self-service.
 *
 * Fans join an artist's mailing list through the Subscribe form. Artists send
 * those emails from their own tools, so every exported subscriber row carries
 * a personal signed unsubscribe link the artist pastes into their emails.
 *
 * The link reuses the network signed-token primitive (extrachill_sign() /
 * extrachill_verify() in extrachill-network) and the Local Scene digest
 * unsubscribe flow: a public extrachill-api route is the transport, GET shows
 * a scanner-safe confirmation page that never changes anything, and the page's
 * button POSTs the signed token back to remove that one subscription.
 *
 * Signed-in members also see their artist subscriptions (matched by user ID or
 * account email) in Settings → Subscriptions and can remove them.
 *
 * @package ExtraChillArtistPlatform
 */

defined( 'ABSPATH' ) || exit;

/** Purpose bound into every artist subscriber unsubscribe token. */
const EXTRACHILL_ARTIST_SUBSCRIBER_UNSUBSCRIBE_PURPOSE = 'artist.subscriber_unsubscribe';

/** REST route (under extrachill/v1) that serves the unsubscribe page. */
const EXTRACHILL_ARTIST_SUBSCRIBER_UNSUBSCRIBE_ROUTE = 'extrachill/v1/artists/subscribers/unsubscribe';

/**
 * Artist subscriber table name for the current blog.
 *
 * @return string
 */
function extrachill_artist_subscribers_table(): string {
	global $wpdb;
	return $wpdb->prefix . 'artist_subscribers';
}

/**
 * Non-reversible fingerprint binding a token to one subscriber email.
 *
 * Keeps the address itself out of the link while making a token minted for
 * one email useless for any other address on the same list.
 *
 * @param string $email Subscriber email.
 * @return string
 */
function extrachill_artist_subscriber_email_fingerprint( string $email ): string {
	return substr( hash_hmac( 'sha256', 'artist-subscriber|' . strtolower( trim( $email ) ), wp_salt( 'auth' ) ), 0, 16 );
}

/**
 * Build the personal signed unsubscribe URL for one subscriber row.
 *
 * The link never expires: artists send from their own tools on their own
 * schedule, often months after exporting. It only authorizes removing this
 * email from this one artist's list.
 *
 * @param int    $artist_id     Artist profile post ID.
 * @param string $email         Subscriber email.
 * @param int    $subscriber_id Optional subscriber row ID (lookup hint).
 * @return string Absolute URL, or '' on invalid input.
 */
function extrachill_artist_subscriber_unsubscribe_url( int $artist_id, string $email, int $subscriber_id = 0 ): string {
	if ( $artist_id <= 0 || ! is_email( $email ) || ! function_exists( 'extrachill_sign' ) ) {
		return '';
	}

	$token = extrachill_sign(
		EXTRACHILL_ARTIST_SUBSCRIBER_UNSUBSCRIBE_PURPOSE,
		array(
			'a' => $artist_id,
			's' => max( 0, $subscriber_id ),
			'h' => extrachill_artist_subscriber_email_fingerprint( $email ),
		),
		0
	);
	if ( '' === $token ) {
		return '';
	}

	return add_query_arg( 'token', $token, rest_url( EXTRACHILL_ARTIST_SUBSCRIBER_UNSUBSCRIBE_ROUTE ) );
}

/**
 * Verify a signed unsubscribe token without changing anything.
 *
 * Returns the artist ID and the matching subscriber row ID. A valid token
 * whose subscription is already gone returns subscriber_id 0 so the page can
 * still confirm the person is off the list.
 *
 * @param string $token Signed token.
 * @return array{artist_id:int, subscriber_id:int}|WP_Error
 */
function extrachill_artist_verify_subscriber_unsubscribe_token( string $token ) {
	$invalid = new WP_Error( 'invalid_artist_unsubscribe', __( 'This unsubscribe link is invalid.', 'extrachill-artist-platform' ), array( 'status' => 403 ) );
	if ( '' === $token || ! function_exists( 'extrachill_verify' ) ) {
		return $invalid;
	}

	$claims = extrachill_verify( EXTRACHILL_ARTIST_SUBSCRIBER_UNSUBSCRIBE_PURPOSE, $token );
	if ( null === $claims ) {
		return $invalid;
	}

	$artist_id   = isset( $claims['a'] ) && is_int( $claims['a'] ) ? $claims['a'] : 0;
	$hint        = isset( $claims['s'] ) && is_int( $claims['s'] ) ? $claims['s'] : 0;
	$fingerprint = isset( $claims['h'] ) && is_string( $claims['h'] ) ? $claims['h'] : '';
	if ( $artist_id <= 0 || '' === $fingerprint ) {
		return $invalid;
	}

	global $wpdb;
	$table = extrachill_artist_subscribers_table();

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- trusted table name; consent reads must be live.
	$rows = array();
	if ( $hint > 0 ) {
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT subscriber_id, subscriber_email FROM {$table} WHERE subscriber_id = %d AND artist_profile_id = %d", $hint, $artist_id ), ARRAY_A );
	}
	$match = extrachill_artist_match_subscriber_fingerprint( is_array( $rows ) ? $rows : array(), $fingerprint );
	if ( 0 === $match ) {
		// The person may have resubscribed (new row) since the link was sent.
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT subscriber_id, subscriber_email FROM {$table} WHERE artist_profile_id = %d", $artist_id ), ARRAY_A );
		$match = extrachill_artist_match_subscriber_fingerprint( is_array( $rows ) ? $rows : array(), $fingerprint );
	}
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

	return array(
		'artist_id'     => $artist_id,
		'subscriber_id' => $match,
	);
}

/**
 * Find the subscriber row whose email matches a token fingerprint.
 *
 * @param array  $rows        Rows with subscriber_id and subscriber_email.
 * @param string $fingerprint Token fingerprint.
 * @return int Matching subscriber ID, or 0.
 */
function extrachill_artist_match_subscriber_fingerprint( array $rows, string $fingerprint ): int {
	foreach ( $rows as $row ) {
		if ( is_array( $row ) && isset( $row['subscriber_email'], $row['subscriber_id'] ) && hash_equals( extrachill_artist_subscriber_email_fingerprint( (string) $row['subscriber_email'] ), $fingerprint ) ) {
			return (int) $row['subscriber_id'];
		}
	}
	return 0;
}

/**
 * Delete one subscriber row.
 *
 * @param int $subscriber_id Subscriber row ID.
 * @return bool|WP_Error True when deleted or already gone.
 */
function extrachill_artist_delete_subscriber( int $subscriber_id ) {
	if ( $subscriber_id <= 0 ) {
		return true;
	}

	global $wpdb;
	$deleted = $wpdb->delete( extrachill_artist_subscribers_table(), array( 'subscriber_id' => $subscriber_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- consent removal is a direct write.
	if ( false === $deleted ) {
		return new WP_Error( 'artist_unsubscribe_failed', __( 'This subscription could not be removed right now.', 'extrachill-artist-platform' ), array( 'status' => 500 ) );
	}
	return true;
}

/**
 * Display name for an artist on unsubscribe surfaces.
 *
 * @param int $artist_id Artist profile post ID.
 * @return string
 */
function extrachill_artist_subscriber_artist_name( int $artist_id ): string {
	$name = 'artist_profile' === get_post_type( $artist_id ) ? get_the_title( $artist_id ) : '';
	return '' !== $name ? wp_specialchars_decode( $name, ENT_QUOTES ) : __( 'this artist', 'extrachill-artist-platform' );
}

/**
 * Resolve a signed unsubscribe request into its standalone browser page.
 *
 * GET (confirm false) verifies the token and renders a confirmation button;
 * it never changes consent, so link scanners cannot unsubscribe anyone.
 * POST (confirm true) removes the subscription.
 *
 * @param string $token   Signed token.
 * @param bool   $confirm Whether this is the confirming POST.
 * @return array{status:string, title:string, message:string, html:string}
 */
function extrachill_artist_subscriber_unsubscribe_page( string $token, bool $confirm ): array {
	$verified    = extrachill_artist_verify_subscriber_unsubscribe_token( $token );
	$action_html = '';
	$back_url    = home_url( '/' );

	if ( is_wp_error( $verified ) ) {
		$status  = 'invalid';
		$title   = __( 'Unsubscribe link invalid', 'extrachill-artist-platform' );
		$message = __( 'This unsubscribe link is not valid. Use the link from the artist\'s email, or sign in and manage artist subscriptions in Settings.', 'extrachill-artist-platform' );
	} else {
		$artist_id = $verified['artist_id'];
		$name      = extrachill_artist_subscriber_artist_name( $artist_id );
		if ( 'artist_profile' === get_post_type( $artist_id ) ) {
			$back_url = (string) get_permalink( $artist_id );
		}

		if ( ! $confirm && $verified['subscriber_id'] > 0 ) {
			$status = 'confirm';
			/* translators: %s: artist name. */
			$title       = sprintf( __( 'Unsubscribe from %s?', 'extrachill-artist-platform' ), $name );
			$message     = __( 'You will be removed from this artist\'s mailing list. Other artists and Extra Chill emails are not affected.', 'extrachill-artist-platform' );
			$action_html = extrachill_artist_subscriber_unsubscribe_form( $token, $name );
		} else {
			$removed = $confirm ? extrachill_artist_delete_subscriber( $verified['subscriber_id'] ) : true;
			if ( is_wp_error( $removed ) ) {
				$status  = 'unavailable';
				$title   = __( 'Unsubscribe unavailable', 'extrachill-artist-platform' );
				$message = __( 'Your subscription could not be removed right now. Please try the link again later.', 'extrachill-artist-platform' );
			} else {
				$status = 'unsubscribed';
				/* translators: %s: artist name. */
				$title   = sprintf( __( 'You\'ve unsubscribed from %s', 'extrachill-artist-platform' ), $name );
				$message = __( 'Your email has been removed from this artist\'s mailing list. You can subscribe again any time from their page.', 'extrachill-artist-platform' );
			}
		}
	}

	return array(
		'status'  => $status,
		'title'   => $title,
		'message' => $message,
		'html'    => extrachill_artist_build_subscriber_unsubscribe_page( $title, $message, $action_html, $back_url ),
	);
}

/**
 * Build the confirmation form that POSTs the signed token back.
 *
 * @param string $token Signed token.
 * @param string $name  Artist name.
 * @return string
 */
function extrachill_artist_subscriber_unsubscribe_form( string $token, string $name ): string {
	/* translators: %s: artist name. */
	$label = sprintf( __( 'Unsubscribe from %s', 'extrachill-artist-platform' ), $name );
	return '<form method="post" action="' . esc_url( rest_url( EXTRACHILL_ARTIST_SUBSCRIBER_UNSUBSCRIBE_ROUTE ) ) . '">'
		. '<input type="hidden" name="token" value="' . esc_attr( $token ) . '">'
		. '<button type="submit" class="button-1 button-medium">' . esc_html( $label ) . '</button></form>';
}

/**
 * Build the standalone unsubscribe document.
 *
 * A bare document (no theme) like the other signed unsubscribe pages, so it
 * stays light for link scanners and works from any email client.
 *
 * @param string $title       Page title.
 * @param string $message     Body message.
 * @param string $action_html Escaped action markup.
 * @param string $back_url    Return link.
 * @return string
 */
function extrachill_artist_build_subscriber_unsubscribe_page( string $title, string $message, string $action_html, string $back_url ): string {
	$html  = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . esc_html( $title ) . '</title>';
	$html .= '<style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;background:#f6f6f6;color:#222;margin:0;padding:0}.wrap{max-width:520px;margin:10vh auto;padding:2rem;background:#fff;border-radius:8px;box-shadow:0 1px 4px rgba(0,0,0,.08);text-align:center}h1{font-size:1.4rem;margin:0 0 1rem}p{line-height:1.5;margin:0 0 1.5rem}a,button{display:inline-block;color:#fff;background:#222;text-decoration:none;padding:.65rem 1.25rem;border:0;border-radius:6px;cursor:pointer;font:inherit}form{margin:0 0 1rem}</style>';
	$html .= '</head><body><div class="wrap"><h1>' . esc_html( $title ) . '</h1><p>' . esc_html( $message ) . '</p>' . $action_html . '<a class="button-3 button-medium" href="' . esc_url( $back_url ) . '">' . esc_html__( 'Back to Extra Chill', 'extrachill-artist-platform' ) . '</a></div></body></html>';
	return $html;
}

/**
 * List the artist subscriptions that belong to a member.
 *
 * Matches rows stored with the user's ID or with their account email, so
 * signups made before the person had an account (or while signed out) show
 * up too.
 *
 * @param int $user_id User ID.
 * @return array<int, array{subscriber_id:int, artist_id:int, artist_name:string, artist_url:string, subscribed_at:string}>|WP_Error
 */
function extrachill_artist_get_member_subscriptions( int $user_id ) {
	$user = $user_id > 0 ? get_userdata( $user_id ) : false;
	if ( ! $user ) {
		return array();
	}

	global $wpdb;
	$table = extrachill_artist_subscribers_table();
	$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- consent reads must be live.
		$wpdb->prepare(
			"SELECT subscriber_id, artist_profile_id, subscribed_at FROM {$table} WHERE user_id = %d OR subscriber_email = %s ORDER BY subscribed_at DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table name.
			$user_id,
			(string) $user->user_email
		)
	);
	if ( ! is_array( $rows ) ) {
		return new WP_Error( 'artist_subscriptions_read_failed', __( 'Artist subscriptions could not be loaded.', 'extrachill-artist-platform' ), array( 'status' => 500 ) );
	}

	$subscriptions = array();
	foreach ( $rows as $row ) {
		$artist_id       = (int) $row->artist_profile_id;
		$is_artist       = 'artist_profile' === get_post_type( $artist_id );
		$subscriptions[] = array(
			'subscriber_id' => (int) $row->subscriber_id,
			'artist_id'     => $artist_id,
			'artist_name'   => extrachill_artist_subscriber_artist_name( $artist_id ),
			'artist_url'    => $is_artist ? (string) get_permalink( $artist_id ) : '',
			'subscribed_at' => (string) $row->subscribed_at,
		);
	}

	return $subscriptions;
}

/**
 * Remove one artist subscription that belongs to a member.
 *
 * Rows that are not the member's (by user ID or account email) are reported
 * as not found, so the response never reveals other people's subscriptions.
 *
 * @param int $user_id       User ID.
 * @param int $subscriber_id Subscriber row ID.
 * @return true|WP_Error
 */
function extrachill_artist_remove_member_subscription( int $user_id, int $subscriber_id ) {
	$not_found = new WP_Error( 'artist_subscription_not_found', __( 'That artist subscription was not found.', 'extrachill-artist-platform' ), array( 'status' => 404 ) );
	$user      = $user_id > 0 ? get_userdata( $user_id ) : false;
	if ( ! $user || $subscriber_id <= 0 ) {
		return $not_found;
	}

	global $wpdb;
	$table = extrachill_artist_subscribers_table();
	$owned = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- ownership check must be live.
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE subscriber_id = %d AND ( user_id = %d OR subscriber_email = %s )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table name.
			$subscriber_id,
			$user_id,
			(string) $user->user_email
		)
	);
	if ( 0 === $owned ) {
		return $not_found;
	}

	$deleted = extrachill_artist_delete_subscriber( $subscriber_id );
	return is_wp_error( $deleted ) ? $deleted : true;
}
