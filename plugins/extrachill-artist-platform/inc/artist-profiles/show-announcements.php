<?php
/**
 * New-show announcements to an artist's mailing list.
 *
 * Ownership boundary (#1725):
 * - extrachill-events owns event data and the Events-site artist term ↔
 *   canonical artist term resolution. When a canonical event first becomes
 *   published it emits one "show published for these artists" signal: an
 *   Action Scheduler action on the artist blog carrying plain event scalars
 *   and canonical main-site artist term IDs. Events never reads this
 *   plugin's tables or calls its functions.
 * - extrachill-artist-platform (this file) owns the list and the send. It
 *   resolves each canonical term to its bound artist profile, decides
 *   eligibility (upcoming, not cancelled), and emails the artist's
 *   subscribers in Action Scheduler batches.
 *
 * Delivery follows the Local Scene digest pattern: a receipt is claimed
 * before each email is queued (so replays never resend), released when the
 * email cannot be queued (so a replay can retry), and every email carries the
 * existing one-click artist unsubscribe link.
 *
 * Idempotency has two layers in one receipts table:
 * - An announcement marker row (empty subscriber_email) per artist + event.
 *   Re-publishing, re-importing, or re-signalling the same event finds the
 *   marker and never starts a second announcement, so people who subscribed
 *   after the show was announced are not emailed about it later.
 * - A per-subscriber receipt per artist + event + email, so batch replays
 *   never send the same person the same show twice.
 *
 * @package ExtraChillArtistPlatform
 */

defined( 'ABSPATH' ) || exit;

/** Signal hook Events enqueues on the artist blog when a show is published. */
const EXTRACHILL_ARTIST_SHOW_PUBLISHED_HOOK = 'extrachill_artist_show_published';

/** Batch worker hook. */
const EXTRACHILL_ARTIST_SHOW_ANNOUNCEMENT_BATCH_HOOK = 'extrachill_artist_show_announcement_batch';

/** Action Scheduler group for signals and batches. */
const EXTRACHILL_ARTIST_SHOW_ANNOUNCEMENT_GROUP = 'extrachill-artist-show-announcements';

/** Subscribers emailed per batch action. */
const EXTRACHILL_ARTIST_SHOW_ANNOUNCEMENT_BATCH_SIZE = 50;

/** Receipts table schema version. */
const EXTRACHILL_ARTIST_SHOW_ANNOUNCEMENT_DB_VERSION = '1';

/** Option storing the installed receipts schema version. */
const EXTRACHILL_ARTIST_SHOW_ANNOUNCEMENT_DB_OPTION = 'extrachill_artist_show_announcements_db_version';

add_action( EXTRACHILL_ARTIST_SHOW_PUBLISHED_HOOK, 'extrachill_artist_handle_show_published', 10, 1 );
add_action( EXTRACHILL_ARTIST_SHOW_ANNOUNCEMENT_BATCH_HOOK, 'extrachill_artist_run_show_announcement_batch', 10, 3 );

/**
 * Receipts table name for the current blog.
 *
 * @return string
 */
function extrachill_artist_show_announcements_table(): string {
	global $wpdb;
	return $wpdb->prefix . 'artist_show_announcements';
}

/**
 * Create the announcement receipts table.
 */
function extrachill_artist_create_show_announcements_table(): void {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table_name      = extrachill_artist_show_announcements_table();
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE $table_name (
        receipt_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        artist_profile_id BIGINT(20) UNSIGNED NOT NULL,
        event_key VARCHAR(64) NOT NULL,
        subscriber_email VARCHAR(255) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL,
        PRIMARY KEY (receipt_id),
        UNIQUE KEY artist_event_email (artist_profile_id, event_key, subscriber_email),
        KEY event_key (event_key)
    ) $charset_collate;";

	dbDelta( $sql );
	update_option( EXTRACHILL_ARTIST_SHOW_ANNOUNCEMENT_DB_OPTION, EXTRACHILL_ARTIST_SHOW_ANNOUNCEMENT_DB_VERSION, false );
}

/**
 * Install the receipts table on first use or after a schema bump.
 */
function extrachill_artist_ensure_show_announcements_table(): void {
	if ( EXTRACHILL_ARTIST_SHOW_ANNOUNCEMENT_DB_VERSION !== (string) get_option( EXTRACHILL_ARTIST_SHOW_ANNOUNCEMENT_DB_OPTION, '' ) ) {
		extrachill_artist_create_show_announcements_table();
	}
}

/**
 * Normalize an Events show signal into trusted scalars.
 *
 * @param mixed $signal Raw signal payload.
 * @return array{event_key:string, title:string, url:string, venue:string, starts_at:string, has_time:bool, status:string, ticket_url:string, artist_term_ids:int[]}|null
 */
function extrachill_artist_normalize_show_signal( $signal ): ?array {
	if ( ! is_array( $signal ) ) {
		return null;
	}

	$event_key = isset( $signal['event_key'] ) && is_string( $signal['event_key'] ) && preg_match( '/^\d+:\d+$/', $signal['event_key'] ) ? $signal['event_key'] : '';
	$url       = isset( $signal['url'] ) && is_string( $signal['url'] ) ? esc_url_raw( $signal['url'] ) : '';
	$title     = isset( $signal['title'] ) && is_string( $signal['title'] ) ? sanitize_text_field( $signal['title'] ) : '';
	$starts_at = isset( $signal['starts_at'] ) && is_string( $signal['starts_at'] ) ? $signal['starts_at'] : '';
	$ticket    = isset( $signal['ticket_url'] ) && is_string( $signal['ticket_url'] ) ? esc_url_raw( $signal['ticket_url'] ) : '';
	$terms     = isset( $signal['artist_term_ids'] ) && is_array( $signal['artist_term_ids'] ) ? array_values( array_unique( array_filter( array_map( 'absint', $signal['artist_term_ids'] ) ) ) ) : array();

	if ( '' === $event_key || ! extrachill_artist_show_signal_url_is_valid( $url ) || '' === $title || null === extrachill_artist_show_signal_start( $starts_at ) ) {
		return null;
	}

	return array(
		'event_key'       => $event_key,
		'title'           => $title,
		'url'             => $url,
		'venue'           => isset( $signal['venue'] ) && is_string( $signal['venue'] ) ? sanitize_text_field( $signal['venue'] ) : '',
		'starts_at'       => $starts_at,
		'has_time'        => ! empty( $signal['has_time'] ),
		'status'          => isset( $signal['status'] ) && is_string( $signal['status'] ) ? sanitize_text_field( $signal['status'] ) : '',
		'ticket_url'      => extrachill_artist_show_signal_url_is_valid( $ticket ) ? $ticket : '',
		'artist_term_ids' => $terms,
	);
}

/**
 * Whether a signal URL is an absolute http(s) URL.
 *
 * Deliberately avoids wp_http_validate_url(): that is an outbound-request
 * guard that resolves DNS and rejects private hosts, while these URLs are
 * only printed as links.
 *
 * @param string $url URL.
 * @return bool
 */
function extrachill_artist_show_signal_url_is_valid( string $url ): bool {
	if ( '' === $url ) {
		return false;
	}
	$parts = wp_parse_url( $url );
	return is_array( $parts ) && in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true ) && '' !== (string) ( $parts['host'] ?? '' );
}

/**
 * Parse the signal's ISO 8601 start (with the venue's UTC offset).
 *
 * @param string $starts_at ISO 8601 start.
 * @return DateTimeImmutable|null
 */
function extrachill_artist_show_signal_start( string $starts_at ): ?DateTimeImmutable {
	if ( '' === $starts_at ) {
		return null;
	}
	$date = DateTimeImmutable::createFromFormat( DATE_ATOM, $starts_at );
	return $date instanceof DateTimeImmutable ? $date : null;
}

/**
 * Whether a normalized show may be announced right now.
 *
 * Only upcoming, scheduled (or rescheduled) shows qualify. Cancelled,
 * postponed, moved-online, and past shows never send. Date-only shows stay
 * eligible through the end of their day.
 *
 * @param array    $show Normalized signal.
 * @param int|null $now  Current timestamp.
 * @return bool
 */
function extrachill_artist_show_is_announceable( array $show, ?int $now = null ): bool {
	$now   = $now ?? (int) apply_filters( 'extrachill_artist_show_announcement_now', time() );
	$start = extrachill_artist_show_signal_start( (string) ( $show['starts_at'] ?? '' ) );
	if ( null === $start ) {
		return false;
	}
	if ( ! in_array( (string) ( $show['status'] ?? '' ), array( '', 'EventScheduled', 'EventRescheduled' ), true ) ) {
		return false;
	}
	$cutoff = empty( $show['has_time'] ) ? $start->setTime( 23, 59, 59 ) : $start;
	return $cutoff->getTimestamp() > $now;
}

/**
 * Resolve a canonical main-site artist term to a published artist profile.
 *
 * @param int $artist_term_id Canonical artist term ID.
 * @return int Artist profile post ID, or 0.
 */
function extrachill_artist_show_announcement_profile_for_term( int $artist_term_id ): int {
	if ( $artist_term_id <= 0 || ! function_exists( 'ec_get_artist_identity' ) ) {
		return 0;
	}
	$identity = ec_get_artist_identity( array( 'term_id' => $artist_term_id ) );
	if ( ! is_array( $identity ) || empty( $identity['binding_valid'] ) || (int) $identity['term_id'] !== $artist_term_id ) {
		return 0;
	}
	$profile_id = (int) $identity['profile_id'];
	if ( $profile_id <= 0 || 'artist_profile' !== get_post_type( $profile_id ) || 'publish' !== get_post_status( $profile_id ) ) {
		return 0;
	}
	return $profile_id;
}

/**
 * Claim one receipt row.
 *
 * @param int    $artist_id Artist profile post ID.
 * @param string $event_key Event key.
 * @param string $email     Lowercased subscriber email, or '' for the announcement marker.
 * @return string inserted|existing|failed
 */
function extrachill_artist_claim_show_announcement_receipt( int $artist_id, string $event_key, string $email ): string {
	global $wpdb;
	$table = extrachill_artist_show_announcements_table();

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- trusted table name; the unique key is the idempotency claim and receipts must be read live.
	// INSERT IGNORE (same claim shape as extrachill-users entity subscriptions):
	// a duplicate affects 0 rows instead of raising an error, so the connection
	// stays healthy for the follow-up read on every database driver.
	$inserted = $wpdb->query(
		$wpdb->prepare(
			"INSERT IGNORE INTO {$table} (artist_profile_id, event_key, subscriber_email, created_at) VALUES (%d, %s, %s, %s)",
			$artist_id,
			$event_key,
			$email,
			current_time( 'mysql', true )
		)
	);
	if ( 1 === (int) $inserted ) {
		return 'inserted';
	}
	if ( false === $inserted ) {
		return 'failed';
	}

	$existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE artist_profile_id = %d AND event_key = %s AND subscriber_email = %s", $artist_id, $event_key, $email ) );
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	return $existing > 0 ? 'existing' : 'failed';
}

/**
 * Release a claimed receipt so a later replay can retry.
 *
 * @param int    $artist_id Artist profile post ID.
 * @param string $event_key Event key.
 * @param string $email     Lowercased subscriber email, or '' for the marker.
 * @return bool
 */
function extrachill_artist_release_show_announcement_receipt( int $artist_id, string $event_key, string $email ): bool {
	global $wpdb;
	$deleted = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- receipt release is a direct write.
		extrachill_artist_show_announcements_table(),
		array(
			'artist_profile_id' => $artist_id,
			'event_key'         => $event_key,
			'subscriber_email'  => $email,
		),
		array( '%d', '%s', '%s' )
	);
	return false !== $deleted;
}

/**
 * Handle Events' "show published for these artists" signal.
 *
 * Runs on the artist blog from Action Scheduler. Claims one announcement per
 * artist + event and queues its first batch. Returns aggregate evidence.
 *
 * @param mixed $signal Signal payload from extrachill-events.
 * @return array{status:string, announcements:int, existing:int, skipped:int}
 */
function extrachill_artist_handle_show_published( $signal ): array {
	$result = array(
		'status'        => 'skipped',
		'announcements' => 0,
		'existing'      => 0,
		'skipped'       => 0,
	);

	$show = extrachill_artist_normalize_show_signal( $signal );
	if ( null === $show ) {
		$result['status'] = 'invalid';
		return $result;
	}
	if ( ! extrachill_artist_show_is_announceable( $show ) ) {
		$result['status'] = 'not_announceable';
		return $result;
	}

	extrachill_artist_ensure_show_announcements_table();
	$result['status'] = 'processed';

	$profiles = array();
	foreach ( $show['artist_term_ids'] as $term_id ) {
		$profile_id = extrachill_artist_show_announcement_profile_for_term( $term_id );
		if ( $profile_id > 0 ) {
			$profiles[ $profile_id ] = true;
		} else {
			++$result['skipped'];
		}
	}

	foreach ( array_keys( $profiles ) as $artist_id ) {
		$claim = extrachill_artist_claim_show_announcement_receipt( $artist_id, $show['event_key'], '' );
		if ( 'existing' === $claim ) {
			++$result['existing'];
			continue;
		}
		if ( 'inserted' !== $claim ) {
			++$result['skipped'];
			continue;
		}

		++$result['announcements'];
		extrachill_artist_queue_show_announcement_batch( $artist_id, $show, 0 );
	}

	return $result;
}

/**
 * Queue (or, without Action Scheduler, run) one announcement batch.
 *
 * @param int   $artist_id           Artist profile post ID.
 * @param array $show                Normalized signal.
 * @param int   $after_subscriber_id Cursor: last processed subscriber ID.
 */
function extrachill_artist_queue_show_announcement_batch( int $artist_id, array $show, int $after_subscriber_id ): void {
	$args = array( $artist_id, $show, $after_subscriber_id );
	if ( function_exists( 'as_enqueue_async_action' ) && (bool) apply_filters( 'extrachill_artist_show_announcement_async', true ) ) {
		as_enqueue_async_action( EXTRACHILL_ARTIST_SHOW_ANNOUNCEMENT_BATCH_HOOK, $args, EXTRACHILL_ARTIST_SHOW_ANNOUNCEMENT_GROUP, true );
		return;
	}

	extrachill_artist_run_show_announcement_batch( ...$args );
}

/**
 * Email one batch of an artist's subscribers about one show.
 *
 * Reads the live subscriber table, so anyone who unsubscribed before their
 * batch runs is never emailed. Platform follow-consent rows are excluded,
 * matching the subscriber list and export.
 *
 * @param int   $artist_id           Artist profile post ID.
 * @param mixed $show                Normalized signal.
 * @param int   $after_subscriber_id Cursor: last processed subscriber ID.
 * @return array{status:string, sent:int, existing:int, failed:int, next_cursor:int}
 */
function extrachill_artist_run_show_announcement_batch( $artist_id, $show, $after_subscriber_id = 0 ): array {
	$result = array(
		'status'      => 'done',
		'sent'        => 0,
		'existing'    => 0,
		'failed'      => 0,
		'next_cursor' => 0,
	);

	$artist_id = absint( $artist_id );
	$show      = extrachill_artist_normalize_show_signal( $show );
	if ( $artist_id <= 0 || null === $show ) {
		$result['status'] = 'invalid';
		return $result;
	}
	if ( ! extrachill_artist_show_is_announceable( $show ) ) {
		$result['status'] = 'not_announceable';
		return $result;
	}

	extrachill_artist_ensure_show_announcements_table();

	global $wpdb;
	$table      = extrachill_artist_subscribers_table();
	$batch_size = max( 1, (int) apply_filters( 'extrachill_artist_show_announcement_batch_size', EXTRACHILL_ARTIST_SHOW_ANNOUNCEMENT_BATCH_SIZE ) );
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- trusted table name; consent reads must be live.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT subscriber_id, subscriber_email FROM {$table} WHERE artist_profile_id = %d AND source <> %s AND subscriber_id > %d ORDER BY subscriber_id ASC LIMIT %d",
			$artist_id,
			'platform_follow_consent',
			absint( $after_subscriber_id ),
			$batch_size
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	if ( ! is_array( $rows ) ) {
		$result['status'] = 'read_failed';
		return $result;
	}

	$artist_name = extrachill_artist_subscriber_artist_name( $artist_id );
	$cursor      = absint( $after_subscriber_id );
	foreach ( $rows as $row ) {
		$cursor = max( $cursor, (int) $row->subscriber_id );
		$email  = strtolower( trim( (string) $row->subscriber_email ) );
		if ( ! is_email( $email ) ) {
			continue;
		}

		$claim = extrachill_artist_claim_show_announcement_receipt( $artist_id, $show['event_key'], $email );
		if ( 'existing' === $claim ) {
			++$result['existing'];
			continue;
		}
		if ( 'inserted' !== $claim ) {
			++$result['failed'];
			continue;
		}

		$queued = extrachill_artist_send_show_announcement_email( $artist_id, $artist_name, $show, $email, (int) $row->subscriber_id );
		if ( $queued ) {
			++$result['sent'];
		} else {
			++$result['failed'];
			extrachill_artist_release_show_announcement_receipt( $artist_id, $show['event_key'], $email );
		}
	}

	if ( count( $rows ) >= $batch_size ) {
		$result['status']      = 'continued';
		$result['next_cursor'] = $cursor;
		extrachill_artist_queue_show_announcement_batch( $artist_id, $show, $cursor );
	}

	return $result;
}

/**
 * Queue one announcement email.
 *
 * @param int    $artist_id     Artist profile post ID.
 * @param string $artist_name   Artist display name.
 * @param array  $show          Normalized signal.
 * @param string $email         Subscriber email.
 * @param int    $subscriber_id Subscriber row ID.
 * @return bool Whether the email was queued.
 */
function extrachill_artist_send_show_announcement_email( int $artist_id, string $artist_name, array $show, string $email, int $subscriber_id ): bool {
	if ( ! function_exists( 'ec_send_email_queued' ) ) {
		return false;
	}

	$unsubscribe_url = extrachill_artist_subscriber_unsubscribe_url( $artist_id, $email, $subscriber_id );
	if ( '' === $unsubscribe_url ) {
		return false;
	}

	$email_parts = extrachill_artist_build_show_announcement_email( $artist_name, $show, $unsubscribe_url );
	$queue       = ec_send_email_queued(
		array(
			'to'       => $email,
			'subject'  => $email_parts['subject'],
			'template' => 'extrachill/branded',
			'context'  => array(
				'subject_html' => esc_html( $email_parts['subject'] ),
				'body_html'    => $email_parts['body_html'],
				'cta_url'      => $email_parts['cta_url'],
				'cta_label'    => $email_parts['cta_label'],
				'preheader'    => $email_parts['preheader'],
			),
		)
	);

	return ! empty( $queue['success'] );
}

/**
 * Human date for a show.
 *
 * @param array $show Normalized signal.
 * @return string
 */
function extrachill_artist_show_announcement_date_label( array $show ): string {
	$start = extrachill_artist_show_signal_start( $show['starts_at'] );
	if ( null === $start ) {
		return '';
	}
	return empty( $show['has_time'] ) ? $start->format( 'D, M j' ) : $start->format( 'D, M j \a\t g:i A' );
}

/**
 * Add campaign tags to an announcement link.
 *
 * @param string $url Canonical URL.
 * @return string
 */
function extrachill_artist_show_announcement_tracked_url( string $url ): string {
	foreach ( array(
		'utm_source'   => 'artist_list',
		'utm_medium'   => 'email',
		'utm_campaign' => 'new_show',
	) as $key => $value ) {
		$url = add_query_arg( $key, $value, $url );
	}
	return $url;
}

/**
 * Build the announcement email.
 *
 * The artist is the sender context: the subject leads with the artist, and
 * the footer says the email comes from their mailing list with a one-click
 * unsubscribe from that artist only.
 *
 * @param string $artist_name     Artist display name.
 * @param array  $show            Normalized signal.
 * @param string $unsubscribe_url Signed artist unsubscribe URL.
 * @return array{subject:string, body_html:string, preheader:string, cta_url:string, cta_label:string}
 */
function extrachill_artist_build_show_announcement_email( string $artist_name, array $show, string $unsubscribe_url ): array {
	$date  = extrachill_artist_show_announcement_date_label( $show );
	$venue = '' !== $show['venue'] ? $show['venue'] : $show['title'];
	$day   = extrachill_artist_show_signal_start( $show['starts_at'] );
	$short = $day ? $day->format( 'M j' ) : $date;

	/* translators: 1: artist name, 2: venue name, 3: show date. */
	$subject = sprintf( __( 'New show: %1$s at %2$s, %3$s', 'extrachill-artist-platform' ), $artist_name, $venue, $short );
	/* translators: 1: artist name, 2: venue name, 3: show date. */
	$preheader = sprintf( __( '%1$s is playing %2$s on %3$s.', 'extrachill-artist-platform' ), $artist_name, $venue, $date );

	$event_url = extrachill_artist_show_announcement_tracked_url( $show['url'] );
	$details   = $date . ( '' !== $show['venue'] ? ' · ' . $show['venue'] : '' );

	/* translators: %s: artist name. */
	$body  = '<p>' . esc_html( sprintf( __( '%s has a new show coming up.', 'extrachill-artist-platform' ), $artist_name ) ) . '</p>';
	$body .= '<p><a href="' . esc_url( $event_url ) . '"><strong>' . esc_html( $show['title'] ) . '</strong></a><br>' . esc_html( $details ) . '</p>';
	if ( '' !== $show['ticket_url'] ) {
		$body .= '<p><a href="' . esc_url( $show['ticket_url'] ) . '">' . esc_html__( 'Get tickets', 'extrachill-artist-platform' ) . '</a></p>';
	}
	/* translators: %s: artist name. */
	$body .= '<p style="font-size:13px">' . esc_html( sprintf( __( 'You are getting this because you joined the %s mailing list on Extra Chill.', 'extrachill-artist-platform' ), $artist_name ) );
	/* translators: %s: artist name. */
	$body .= ' <a href="' . esc_url( $unsubscribe_url ) . '">' . esc_html( sprintf( __( 'Unsubscribe from %s', 'extrachill-artist-platform' ), $artist_name ) ) . '</a></p>';

	return array(
		'subject'   => $subject,
		'body_html' => $body,
		'preheader' => $preheader,
		'cta_url'   => $event_url,
		'cta_label' => __( 'See Show Details', 'extrachill-artist-platform' ),
	);
}
