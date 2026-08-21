<?php
/**
 * Weekly Local Scene event digest.
 *
 * @package ExtraChillEvents
 */

defined( 'ABSPATH' ) || exit;

const EXTRACHILL_EVENTS_LOCAL_SCENE_DIGEST_PRODUCER = 'extrachill-events-local-scene-digest';
const EXTRACHILL_EVENTS_LOCAL_SCENE_DIGEST_TYPE     = 'local_scene_weekly_digest';
const EXTRACHILL_EVENTS_LOCAL_SCENE_DIGEST_ENTITY   = 'local_scene_digest';
const EXTRACHILL_EVENTS_LOCAL_SCENE_DIGEST_TAXONOMY = 'location';
const EXTRACHILL_EVENTS_LOCAL_SCENE_DIGEST_LIMIT    = 8;

/** Register recipient authorization and the scoped unsubscribe route. */
function extrachill_events_init_local_scene_digest(): void {
	add_filter( 'extrachill_users_entity_subscription_entities', 'extrachill_events_local_scene_digest_subscription_entities' );
	add_filter( 'extrachill_users_entity_subscription_producer_authorized', 'extrachill_events_authorize_local_scene_digest_producer', 10, 4 );
	add_action( 'rest_api_init', 'extrachill_events_register_local_scene_digest_routes' );
}

/**
 * Register the digest-owned consent identity against the canonical location taxonomy.
 *
 * @param array $entities Registered entity-to-taxonomy mappings.
 * @return array
 */
function extrachill_events_local_scene_digest_subscription_entities( array $entities ): array {
	$entities[ EXTRACHILL_EVENTS_LOCAL_SCENE_DIGEST_ENTITY ] = EXTRACHILL_EVENTS_LOCAL_SCENE_DIGEST_TAXONOMY;
	return $entities;
}

/**
 * Authorize only canonical location subscriptions for digest delivery.
 *
 * @param bool   $authorized Existing authorization decision.
 * @param string $producer   Requesting producer.
 * @param array  $entity     Normalized entity identity.
 * @param string $delivery   Delivery channel.
 * @return bool
 */
function extrachill_events_authorize_local_scene_digest_producer( $authorized, $producer, $entity, $delivery ): bool {
	if ( EXTRACHILL_EVENTS_LOCAL_SCENE_DIGEST_PRODUCER !== $producer ) {
		return (bool) $authorized;
	}

	return is_array( $entity )
		&& EXTRACHILL_EVENTS_LOCAL_SCENE_DIGEST_ENTITY === ( $entity['entity_type'] ?? '' )
		&& EXTRACHILL_EVENTS_LOCAL_SCENE_DIGEST_TAXONOMY === ( $entity['taxonomy'] ?? '' )
		&& in_array( $delivery, array( 'notification', 'email' ), true );
}

/**
 * Run all canonical location digests and return privacy-safe task evidence.
 *
 * @param array $params Task parameters.
 * @return array Aggregate counts only.
 */
function extrachill_events_run_local_scene_digest( array $params = array() ): array {
	$days    = min( 14, max( 1, absint( $params['days'] ?? 7 ) ) );
	$limit   = min( 20, max( 1, absint( $params['limit'] ?? EXTRACHILL_EVENTS_LOCAL_SCENE_DIGEST_LIMIT ) ) );
	$dry_run = ! empty( $params['dry_run'] );
	$counts  = array(
		'locations_scanned'           => 0,
		'locations_with_events'       => 0,
		'qualified_events'            => 0,
		'candidate_query_failures'    => 0,
		'candidate_queries_truncated' => 0,
		'recipients'                  => 0,
		'scene_mismatches'            => 0,
		'notifications_inserted'      => 0,
		'notifications_existing'      => 0,
		'notifications_released'      => 0,
		'notification_failures'       => 0,
		'emails_queued'               => 0,
		'email_failures'              => 0,
		'retryable_failures'          => 0,
	);

	if ( ! function_exists( 'data_machine_events_query_events' ) || ! function_exists( 'data_machine_events_parse_event_data' ) || ! function_exists( 'extrachill_users_entity_subscription_recipients' ) || ! function_exists( 'extrachill_users_get_local_scene' ) || ! function_exists( 'ec_get_blog_id' ) || ! absint( ec_get_blog_id( 'events' ) ) ) {
		$counts['retryable_failures'] = 1;
		return array(
			'dry_run'           => $dry_run,
			'counts'            => $counts,
			'failures'          => array( 'dependency_unavailable' => 1 ),
			'retryable_failure' => true,
		);
	}

	$terms = get_terms(
		array(
			'taxonomy'   => 'location',
			'hide_empty' => false,
		)
	);
	if ( is_wp_error( $terms ) ) {
		$counts['retryable_failures'] = 1;
		return array(
			'dry_run'           => $dry_run,
			'counts'            => $counts,
			'failures'          => array( 'location_query_failed' => 1 ),
			'retryable_failure' => true,
		);
	}

	$failures = array();
	foreach ( $terms as $term ) {
		if ( ! $term instanceof WP_Term || count( get_ancestors( $term->term_id, 'location', 'taxonomy' ) ) < 2 ) {
			continue;
		}

		++$counts['locations_scanned'];
		$query_evidence              = array();
		$candidates                  = extrachill_events_local_scene_digest_candidates( $term, $days, $query_evidence );
		$counts['qualified_events'] += count( $candidates );
		if ( ! empty( $query_evidence['failed'] ) ) {
			++$counts['candidate_query_failures'];
			++$counts['retryable_failures'];
			$failures['candidate_query_failed'] = ( $failures['candidate_query_failed'] ?? 0 ) + 1;
		}
		if ( ! empty( $query_evidence['truncated'] ) ) {
			++$counts['candidate_queries_truncated'];
			$failures['candidate_query_truncated'] = ( $failures['candidate_query_truncated'] ?? 0 ) + 1;
		}
		if ( empty( $candidates ) ) {
			continue;
		}
		++$counts['locations_with_events'];

		$recipient_ids = extrachill_users_entity_subscription_recipients( EXTRACHILL_EVENTS_LOCAL_SCENE_DIGEST_PRODUCER, EXTRACHILL_EVENTS_LOCAL_SCENE_DIGEST_ENTITY, EXTRACHILL_EVENTS_LOCAL_SCENE_DIGEST_TAXONOMY, $term->slug, 'notification' );
		$email_ids     = extrachill_users_entity_subscription_recipients( EXTRACHILL_EVENTS_LOCAL_SCENE_DIGEST_PRODUCER, EXTRACHILL_EVENTS_LOCAL_SCENE_DIGEST_ENTITY, EXTRACHILL_EVENTS_LOCAL_SCENE_DIGEST_TAXONOMY, $term->slug, 'email' );
		if ( is_wp_error( $recipient_ids ) || ! is_array( $recipient_ids ) || is_wp_error( $email_ids ) || ! is_array( $email_ids ) ) {
			++$counts['retryable_failures'];
			$failures['recipient_resolution_failed'] = ( $failures['recipient_resolution_failed'] ?? 0 ) + 1;
			continue;
		}

		$email_ids = array_fill_keys( array_map( 'absint', $email_ids ), true );
		foreach ( array_values( array_unique( array_map( 'absint', $recipient_ids ) ) ) as $user_id ) {
			$scene      = extrachill_users_get_local_scene( $user_id );
			$scene_slug = is_array( $scene ) ? sanitize_title( $scene['slug'] ?? '' ) : '';
			if ( $scene_slug !== $term->slug ) {
				++$counts['scene_mismatches'];
				continue;
			}

			++$counts['recipients'];
			$selected = extrachill_events_select_local_scene_digest_events( $candidates, $user_id, $limit );
			if ( empty( $selected ) || $dry_run ) {
				continue;
			}

			$result = extrachill_events_deliver_local_scene_digest( $user_id, $term, $selected, isset( $email_ids[ $user_id ] ) );
			$status = $result['status'] ?? 'failed';
			if ( 'inserted' === $status ) {
				++$counts['notifications_inserted'];
			} elseif ( 'existing' === $status ) {
				++$counts['notifications_existing'];
			} else {
				++$counts['notification_failures'];
			}
			$reason = sanitize_key( $result['reason'] ?? '' );
			if ( '' !== $reason ) {
				$failures[ $reason ] = ( $failures[ $reason ] ?? 0 ) + 1;
			}
			if ( ! empty( $result['email_queued'] ) ) {
				++$counts['emails_queued'];
			} elseif ( ! empty( $result['email_failed'] ) ) {
				++$counts['email_failures'];
			}
			if ( ! empty( $result['receipt_released'] ) ) {
				++$counts['notifications_released'];
			}
			if ( ! empty( $result['retryable_failure'] ) ) {
				++$counts['retryable_failures'];
			}
		}
	}

	return array(
		'dry_run'           => $dry_run,
		'counts'            => $counts,
		'failures'          => $failures,
		'retryable_failure' => 0 < $counts['retryable_failures'],
	);
}

/**
 * Query, hydrate, and hard-gate one location's upcoming events.
 *
 * @param WP_Term    $location Canonical location term.
 * @param int        $days     Upcoming window length.
 * @param array|null $evidence Aggregate query evidence populated by reference.
 * @return array Qualified event rows.
 */
function extrachill_events_local_scene_digest_candidates( WP_Term $location, int $days, ?array &$evidence = null ): array {
	$evidence      = array(
		'failed'    => false,
		'truncated' => false,
	);
	$now_timestamp = (int) apply_filters( 'extrachill_local_scene_digest_now', time() );
	$utc_now       = ( new DateTimeImmutable( '@' . $now_timestamp ) )->setTimezone( new DateTimeZone( 'UTC' ) );
	$cap           = min( 1000, max( 20, absint( apply_filters( 'extrachill_local_scene_digest_candidate_cap', 250 ) ) ) );
	$query         = data_machine_events_query_events(
		array(
			'status'      => 'publish',
			'date_start'  => $utc_now->modify( '-1 day' )->format( 'Y-m-d' ),
			'date_end'    => $utc_now->modify( '+' . ( $days + 1 ) . ' days' )->format( 'Y-m-d' ),
			'per_page'    => $cap + 1,
			'order'       => 'ASC',
			'tax_filters' => array( 'location' => array( (int) $location->term_id ) ),
		)
	);
	if ( ! is_array( $query ) ) {
		$evidence['failed'] = true;
		return array();
	}
	$posts                 = is_array( $query['posts'] ?? null ) ? $query['posts'] : array();
	$evidence['truncated'] = count( $posts ) > $cap;
	$posts                 = array_slice( $posts, 0, $cap );
	$priority_events       = array_fill_keys( array_map( 'absint', function_exists( 'extrachill_get_priority_event_ids' ) ? extrachill_get_priority_event_ids() : array() ), true );
	$priority_venues       = array_fill_keys( array_map( 'absint', function_exists( 'ec_get_priority_venue_ids' ) ? ec_get_priority_venue_ids() : array() ), true );
	$candidates            = array();

	foreach ( $posts as $post ) {
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			continue;
		}

		$location_ids = wp_get_post_terms( $post->ID, 'location', array( 'fields' => 'ids' ) );
		$venue_terms  = wp_get_post_terms( $post->ID, 'venue' );
		if ( is_wp_error( $location_ids ) || array( (int) $location->term_id ) !== array_values( array_unique( array_map( 'absint', $location_ids ) ) ) || is_wp_error( $venue_terms ) || 1 !== count( $venue_terms ) ) {
			continue;
		}

		$data   = data_machine_events_parse_event_data( $post );
		$status = is_array( $data ) ? (string) ( $data['eventStatus'] ?? '' ) : '';
		if ( ! is_array( $data ) || ! in_array( $status, array( '', 'EventScheduled', 'EventRescheduled' ), true ) ) {
			continue;
		}

		$timezone_name = (string) ( $data['venueTimezone'] ?? '' );
		if ( ! in_array( $timezone_name, DateTimeZone::listIdentifiers( DateTimeZone::ALL_WITH_BC ), true ) ) {
			continue;
		}
		$timezone   = new DateTimeZone( $timezone_name );
		$start      = ( new DateTimeImmutable( '@' . $now_timestamp ) )->setTimezone( $timezone );
		$end        = $start->modify( '+' . $days . ' days' );
		$start_time = (string) ( $data['startTime'] ?? '' );
		if ( preg_match( '/^\d{2}:\d{2}$/', $start_time ) ) {
			$start_time .= ':00';
		}
		if ( in_array( $start_time, array( '00:00', '00:00:00' ), true ) ) {
			continue;
		}
		$source_datetime = (string) ( $data['startDate'] ?? '' ) . ' ' . $start_time;
		$date            = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $source_datetime, $timezone );
		$errors          = DateTimeImmutable::getLastErrors();
		if ( ! $date || ( false !== $errors && ( $errors['warning_count'] || $errors['error_count'] ) ) || $date->format( 'Y-m-d H:i:s' ) !== $source_datetime || $date < $start || $date >= $end ) {
			continue;
		}

		$end_date     = (string) ( $data['endDate'] ?? '' );
		$end_time     = (string) ( $data['endTime'] ?? '' );
		$end_datetime = null;
		if ( '' !== $end_date || '' !== $end_time ) {
			if ( '' === $end_time ) {
				continue;
			}
			if ( preg_match( '/^\d{2}:\d{2}$/', $end_time ) ) {
				$end_time .= ':00';
			}
			$end_source   = ( '' !== $end_date ? $end_date : (string) ( $data['startDate'] ?? '' ) ) . ' ' . $end_time;
			$end_datetime = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $end_source, $timezone );
			$errors       = DateTimeImmutable::getLastErrors();
			if ( ! $end_datetime || ( false !== $errors && ( $errors['warning_count'] || $errors['error_count'] ) ) || $end_datetime->format( 'Y-m-d H:i:s' ) !== $end_source || $end_datetime <= $date ) {
				continue;
			}
		}

		$url   = get_permalink( $post );
		$venue = reset( $venue_terms );
		if ( ! is_string( $url ) || ! wp_http_validate_url( $url ) || ! $venue instanceof WP_Term ) {
			continue;
		}

		$artist_ids = wp_get_post_terms( $post->ID, 'artist', array( 'fields' => 'ids' ) );
		$performers = ! is_wp_error( $artist_ids ) ? array_map( static fn( $id ) => 'term:' . absint( $id ), $artist_ids ) : array();
		if ( empty( $performers ) && '' !== sanitize_title( $data['performer'] ?? '' ) ) {
			$performers[] = sanitize_title( $data['performer'] );
		}
		$price = trim( (string) ( $data['price'] ?? '' ) );
		$item  = array(
			'post_id'        => (int) $post->ID,
			'title'          => get_the_title( $post ),
			'url'            => $url,
			'datetime'       => $date,
			'end_datetime'   => $end_datetime,
			'venue_id'       => (int) $venue->term_id,
			'venue_name'     => $venue->name,
			'performer_keys' => array_values( array_unique( $performers ) ),
			'price'          => $price,
			'priority_event' => isset( $priority_events[ $post->ID ] ),
			'priority_venue' => isset( $priority_venues[ $venue->term_id ] ),
			'has_price'      => '' !== $price,
			'completeness'   => count( array_filter( array( $data['performer'] ?? '', $data['ticketUrl'] ?? '', $data['image'] ?? '', $price ) ) ),
		);
		$key   = sanitize_title( $item['title'] ) . '|' . $item['venue_id'] . '|' . $date->format( 'Y-m-d' );
		if ( ! isset( $candidates[ $key ] ) || extrachill_events_compare_local_scene_digest_events( $item, $candidates[ $key ] ) < 0 ) {
			$candidates[ $key ] = $item;
		}
	}

	$candidates = array_values( $candidates );
	usort( $candidates, 'extrachill_events_compare_local_scene_digest_events' );
	return $candidates;
}

/**
 * Deterministic priority comparator.
 *
 * @param array $a First event.
 * @param array $b Second event.
 * @return int Sort comparison.
 */
function extrachill_events_compare_local_scene_digest_events( array $a, array $b ): int {
	foreach ( array( 'priority_event', 'priority_venue', 'completeness', 'has_price' ) as $field ) {
		$comparison = (int) $b[ $field ] <=> (int) $a[ $field ];
		if ( 0 !== $comparison ) {
			return $comparison;
		}
	}
	$comparison = $a['datetime']->getTimestamp() <=> $b['datetime']->getTimestamp();
	return 0 !== $comparison ? $comparison : ( $a['post_id'] <=> $b['post_id'] );
}

/**
 * Select a bounded personalized list, with Going events in the first section.
 *
 * @param array $candidates Qualified location events.
 * @param int   $user_id    Recipient user ID.
 * @param int   $limit      Maximum selected events.
 * @return array Selected event rows.
 */
function extrachill_events_select_local_scene_digest_events( array $candidates, int $user_id, int $limit ): array {
	$going = array();
	$more  = array();
	$blog  = function_exists( 'ec_get_blog_id' ) ? absint( ec_get_blog_id( 'events' ) ) : 0;
	if ( ! $blog ) {
		return array();
	}
	foreach ( $candidates as $candidate ) {
		if ( function_exists( 'ec_users_is_event_marked' ) && ec_users_is_event_marked( $user_id, $candidate['post_id'], $blog ) ) {
			$going[] = $candidate;
		} else {
			$more[] = $candidate;
		}
	}

	$ordered = array_merge( $going, $more );
	if ( count( $ordered ) <= $limit ) {
		return $ordered;
	}
	if ( count( $going ) >= $limit ) {
		return array_slice( $going, 0, $limit );
	}

	$selected = $going;
	$deferred = array();
	$venues   = array();
	$artists  = array();
	foreach ( $going as $event ) {
		$venues[ $event['venue_id'] ] = ( $venues[ $event['venue_id'] ] ?? 0 ) + 1;
		foreach ( $event['performer_keys'] as $performer_key ) {
			$artists[ $performer_key ] = ( $artists[ $performer_key ] ?? 0 ) + 1;
		}
	}
	foreach ( $more as $event ) {
		$venue_count = $venues[ $event['venue_id'] ] ?? 0;
		$artist_cap  = false;
		foreach ( $event['performer_keys'] as $performer_key ) {
			if ( ( $artists[ $performer_key ] ?? 0 ) >= 2 ) {
				$artist_cap = true;
				break;
			}
		}
		if ( $venue_count >= 2 || $artist_cap ) {
			$deferred[] = $event;
			continue;
		}
		$selected[]                   = $event;
		$venues[ $event['venue_id'] ] = $venue_count + 1;
		foreach ( $event['performer_keys'] as $performer_key ) {
			$artists[ $performer_key ] = ( $artists[ $performer_key ] ?? 0 ) + 1;
		}
		if ( count( $selected ) >= $limit ) {
			return $selected;
		}
	}

	return array_slice( array_merge( $selected, $deferred ), 0, $limit );
}

/**
 * Claim the weekly notification and enqueue email only for a new eligible claim.
 *
 * @param int     $user_id        Recipient user ID.
 * @param WP_Term $location       Canonical location term.
 * @param array   $events         Selected event rows.
 * @param bool    $email_eligible Whether email delivery is enabled.
 * @return array Delivery result.
 */
function extrachill_events_deliver_local_scene_digest( int $user_id, WP_Term $location, array $events, bool $email_eligible ): array {
	if ( ! function_exists( 'ec_users_notify_with_receipts' ) || ! function_exists( 'ec_users_release_notification_receipt' ) || ! function_exists( 'ec_get_network_bot_user_id' ) ) {
		return array(
			'status'            => 'failed',
			'reason'            => 'delivery_dependency_unavailable',
			'retryable_failure' => true,
		);
	}

	$actor_id = absint( ec_get_network_bot_user_id() );
	$link     = extrachill_events_local_scene_digest_tracked_url( get_term_link( $location ) );
	if ( ! $actor_id || is_wp_error( $link ) || ! wp_http_validate_url( $link ) ) {
		return array(
			'status' => 'failed',
			'reason' => 'invalid_delivery_context',
		);
	}

	$week              = ( new DateTimeImmutable( 'now', wp_timezone() ) )->format( 'o-W' );
	$idempotency_key   = $week . ':' . $location->slug;
	$receipt           = ec_users_notify_with_receipts(
		array( $user_id ),
		array(
			'actor_id'            => $actor_id,
			'type'                => EXTRACHILL_EVENTS_LOCAL_SCENE_DIGEST_TYPE,
			'title'               => __( 'Your Local Scene weekly picks are ready', 'extrachill-events' ),
			'link'                => $link,
			'item_id'             => $events[0]['post_id'],
			'producer'            => EXTRACHILL_EVENTS_LOCAL_SCENE_DIGEST_PRODUCER,
			'idempotency_key'     => $idempotency_key,
			'producer_owns_email' => true,
		)
	);
	$recipient_receipt = is_array( $receipt ) && is_array( $receipt['recipients'][ $user_id ] ?? null ) ? $receipt['recipients'][ $user_id ] : array();
	$status            = $recipient_receipt['status'] ?? 'failed';
	$notification_id   = absint( $recipient_receipt['notification_id'] ?? 0 );
	$result            = array(
		'status'            => $status,
		'email_queued'      => false,
		'email_failed'      => false,
		'receipt_released'  => false,
		'retryable_failure' => false,
	);
	if ( 'failed' === $status ) {
		$receipt_error    = sanitize_key( $recipient_receipt['error'] ?? '' );
		$stable_errors    = array( 'invalid_payload', 'invalid_actor', 'incomplete_idempotency', 'invalid_producer', 'invalid_idempotency_key', 'email_ownership_requires_idempotency', 'invalid_user', 'insert_failed', 'row_id_unavailable' );
		$result['reason'] = in_array( $receipt_error, $stable_errors, true ) ? 'notification_' . $receipt_error : 'notification_receipt_failed';
	}
	if ( 'inserted' === $status && ! $notification_id ) {
		$result['status'] = 'failed';
		$result['reason'] = 'invalid_delivery_receipt';
		return $result;
	}
	if ( 'inserted' !== $status || ! $email_eligible ) {
		return $result;
	}

	$user = get_userdata( $user_id );
	if ( ! $user instanceof WP_User || empty( $user->user_email ) || ! is_email( $user->user_email ) || ! function_exists( 'ec_send_email_queued' ) ) {
		return extrachill_events_release_local_scene_digest_receipt( $result, $notification_id, $user_id, $idempotency_key );
	}

	$email = extrachill_events_build_local_scene_digest_email( $user_id, $location, $events );
	if ( empty( $email['subject'] ) || empty( $email['body_html'] ) ) {
		return extrachill_events_release_local_scene_digest_receipt( $result, $notification_id, $user_id, $idempotency_key );
	}
	$queue = ec_send_email_queued( // phpcs:ignore Generic.Formatting.MultipleStatementAlignment.NotSameWarning -- separated from the validated email assignment by a guard.
		array(
			'to'       => $user->user_email,
			'subject'  => $email['subject'],
			'template' => 'extrachill/branded',
			'context'  => array(
				'subject_html'   => esc_html( $email['subject'] ),
				'recipient_name' => $user->display_name,
				'body_html'      => $email['body_html'],
				'cta_url'        => $link,
				'cta_label'      => __( 'See Every Show', 'extrachill-events' ),
				'preheader'      => __( 'Upcoming shows picked for your Local Scene.', 'extrachill-events' ),
			),
		)
	);
	$result['email_queued'] = is_array( $queue ) && ! is_wp_error( $queue ) && ! empty( $queue['success'] );
	if ( ! $result['email_queued'] ) {
		return extrachill_events_release_local_scene_digest_receipt( $result, $notification_id, $user_id, $idempotency_key, true );
	}
	return $result;
}

/**
 * Release a failed rich-email claim so a later task replay can retry.
 *
 * @param array  $result          Current delivery result.
 * @param int    $notification_id Claimed notification ID.
 * @param int    $user_id         Recipient user ID.
 * @param string $idempotency_key Producer replay key.
 * @param bool   $retryable       Whether successful release should retry the whole task.
 * @return array Failed delivery result.
 */
function extrachill_events_release_local_scene_digest_receipt( array $result, int $notification_id, int $user_id, string $idempotency_key, bool $retryable = false ): array {
	$released = ec_users_release_notification_receipt(
		$notification_id,
		$user_id,
		EXTRACHILL_EVENTS_LOCAL_SCENE_DIGEST_PRODUCER,
		$idempotency_key
	);

	$result['email_failed']      = true;
	$result['receipt_released']  = $released;
	$result['retryable_failure'] = $released && $retryable;
	$result['reason']            = $released ? 'email_enqueue_failed' : 'email_receipt_release_failed';
	return $result;
}

/**
 * Build the branded email's selected event list and scoped footer.
 *
 * @param int     $user_id  Recipient user ID.
 * @param WP_Term $location Canonical location term.
 * @param array   $events   Selected event rows.
 * @return array Email subject and body.
 */
function extrachill_events_build_local_scene_digest_email( int $user_id, WP_Term $location, array $events ): array {
	$going = array();
	$more  = array();
	$blog  = function_exists( 'ec_get_blog_id' ) ? absint( ec_get_blog_id( 'events' ) ) : 0;
	if ( ! $blog ) {
		return array(
			'subject'   => __( "Your Local Scene: This Week's Shows", 'extrachill-events' ),
			'body_html' => '',
		);
	}
	foreach ( $events as $event ) {
		$is_going = function_exists( 'ec_users_is_event_marked' ) && ec_users_is_event_marked( $user_id, $event['post_id'], $blog );
		if ( $is_going ) {
			$going[] = $event;
		} else {
			$more[] = $event;
		}
	}

	/* translators: %s: Local Scene name. */
	$body  = '<p>' . esc_html( sprintf( __( 'Here is what is happening in %s this week.', 'extrachill-events' ), $location->name ) ) . '</p>';
	$body .= extrachill_events_render_local_scene_digest_section( __( "You're Going", 'extrachill-events' ), $going );
	$body .= extrachill_events_render_local_scene_digest_section( __( 'More This Week', 'extrachill-events' ), $more );
	$body .= '<p style="font-size:13px"><a href="' . esc_url( extrachill_events_local_scene_digest_unsubscribe_url( $user_id, $location->slug ) ) . '">' . esc_html__( 'Unsubscribe from this Local Scene digest', 'extrachill-events' ) . '</a></p>';

	return array(
		'subject'   => __( "Your Local Scene: This Week's Shows", 'extrachill-events' ),
		'body_html' => $body,
	);
}

/**
 * Render one non-empty digest section.
 *
 * @param string $heading Section heading.
 * @param array  $events  Selected event rows.
 * @return string Section HTML.
 */
function extrachill_events_render_local_scene_digest_section( string $heading, array $events ): string {
	if ( empty( $events ) ) {
		return '';
	}
	$html = '<h2>' . esc_html( $heading ) . '</h2><ul>';
	foreach ( $events as $event ) {
		$price = '';
		if ( $event['has_price'] ) {
			$price = in_array( strtolower( $event['price'] ), array( '0', '0.00', '$0', '$0.00', 'free' ), true ) ? __( 'Free', 'extrachill-events' ) : $event['price'];
		}
		$details   = $event['datetime']->format( 'D, M j \a\t g:i A' );
		$event_end = $event['end_datetime'] ?? null;
		if ( $event_end instanceof DateTimeImmutable ) {
			$details .= $event['datetime']->format( 'Y-m-d' ) === $event_end->format( 'Y-m-d' )
				? ' - ' . $event_end->format( 'g:i A' )
				: ' - ' . $event_end->format( 'D, M j \a\t g:i A' );
		}
		$details .= ' · ' . $event['venue_name'];
		if ( '' !== $price ) {
			$details .= ' · ' . $price;
		}
		$html .= '<li><a href="' . esc_url( extrachill_events_local_scene_digest_tracked_url( $event['url'] ) ) . '"><strong>' . esc_html( $event['title'] ) . '</strong></a><br>' . esc_html( $details ) . '</li>';
	}
	return $html . '</ul>';
}

/**
 * Add generic campaign tags without embedding location identity.
 *
 * @param mixed $url Canonical URL or error.
 * @return string Tracked URL.
 */
function extrachill_events_local_scene_digest_tracked_url( $url ): string {
	if ( is_wp_error( $url ) || ! is_string( $url ) ) {
		return '';
	}
	return extrachill_events_local_scene_digest_add_query_args(
		$url,
		array(
			'utm_source'   => 'local_scene_digest',
			'utm_medium'   => 'email',
			'utm_campaign' => 'weekly_events',
		)
	);
}

/**
 * Mint a location-specific signed unsubscribe URL valid for 30 days.
 *
 * @param int      $user_id Recipient user ID.
 * @param string   $slug    Canonical location slug.
 * @param int|null $expires Optional expiry timestamp.
 * @return string Signed URL.
 */
function extrachill_events_local_scene_digest_unsubscribe_url( int $user_id, string $slug, ?int $expires = null ): string {
	$slug    = sanitize_title( $slug );
	$expires = $expires ?? ( time() + 30 * DAY_IN_SECONDS );
	$token   = hash_hmac( 'sha256', $user_id . '|' . $slug . '|' . $expires, wp_salt( 'auth' ) );
	return extrachill_events_local_scene_digest_add_query_args(
		rest_url( 'extrachill/v1/local-scene-digest/unsubscribe' ),
		array(
			'user'      => $user_id,
			'location'  => $slug,
			'expires'   => $expires,
			'signature' => $token,
		)
	);
}

/**
 * Add query values one at a time through WordPress' public API.
 *
 * @param string $url  Base URL.
 * @param array  $args Query arguments.
 * @return string URL with arguments.
 */
function extrachill_events_local_scene_digest_add_query_args( string $url, array $args ): string {
	foreach ( $args as $key => $value ) {
		$url = add_query_arg( $key, $value, $url );
	}
	return $url;
}

/** Register the public, signature-authenticated one-click route. */
function extrachill_events_register_local_scene_digest_routes(): void {
	register_rest_route(
		'extrachill/v1',
		'/local-scene-digest/unsubscribe',
		array(
			array(
				'methods'             => 'GET',
				'callback'            => 'extrachill_events_local_scene_digest_unsubscribe_confirmation',
				'permission_callback' => '__return_true',
				'args'                => extrachill_events_local_scene_digest_unsubscribe_args(),
			),
			array(
				'methods'             => 'POST',
				'callback'            => 'extrachill_events_local_scene_digest_unsubscribe',
				'permission_callback' => '__return_true',
				'args'                => extrachill_events_local_scene_digest_unsubscribe_args(),
			),
		)
	);
}

/** Return the shared signed unsubscribe request schema. */
function extrachill_events_local_scene_digest_unsubscribe_args(): array {
	return array(
		'user'      => array(
			'required'          => true,
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
		),
		'location'  => array(
			'required'          => true,
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_title',
		),
		'expires'   => array(
			'required'          => true,
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
		),
		'signature' => array(
			'required'          => true,
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
		),
	);
}

/**
 * Verify and normalize one signed unsubscribe request without mutating consent.
 *
 * @param WP_REST_Request $request Unsubscribe request.
 * @return array|WP_Error
 */
function extrachill_events_verify_local_scene_digest_unsubscribe( WP_REST_Request $request ) {
	$user_id   = absint( $request->get_param( 'user' ) );
	$slug      = sanitize_title( $request->get_param( 'location' ) );
	$expires   = absint( $request->get_param( 'expires' ) );
	$signature = (string) $request->get_param( 'signature' );
	$expected  = hash_hmac( 'sha256', $user_id . '|' . $slug . '|' . $expires, wp_salt( 'auth' ) );
	if ( ! $user_id || '' === $slug || $expires < time() || $expires > time() + 30 * DAY_IN_SECONDS || ! hash_equals( $expected, $signature ) ) {
		return new WP_Error( 'invalid_local_scene_digest_unsubscribe', __( 'This unsubscribe link is invalid or expired.', 'extrachill-events' ), array( 'status' => 403 ) );
	}

	return compact( 'user_id', 'slug', 'expires', 'signature' );
}

/**
 * Render a scanner-safe confirmation form without changing consent.
 *
 * @param WP_REST_Request $request Unsubscribe request.
 * @param bool            $render  Whether to render the browser handoff page.
 * @return array|WP_Error
 */
function extrachill_events_local_scene_digest_unsubscribe_confirmation( WP_REST_Request $request, bool $render = true ) {
	$verified = extrachill_events_verify_local_scene_digest_unsubscribe( $request );
	if ( is_wp_error( $verified ) ) {
		if ( $render ) {
			extrachill_events_render_local_scene_digest_unsubscribe_page( __( 'Unsubscribe link invalid', 'extrachill-events' ), __( 'This unsubscribe link is invalid or has expired. You can manage this Local Scene subscription from its events page.', 'extrachill-events' ) );
		}
		return $verified;
	}

	$form = extrachill_events_build_local_scene_digest_unsubscribe_form( $verified );
	if ( $render ) {
		extrachill_events_render_local_scene_digest_unsubscribe_page( __( 'Unsubscribe from weekly Local Scene picks?', 'extrachill-events' ), __( 'This stops both the weekly email and its in-app update for this Local Scene only.', 'extrachill-events' ), $form );
	}
	return array(
		'confirmed' => false,
		'html'      => $form,
	);
}

/**
 * Build the signed POST confirmation form.
 *
 * @param array $verified Verified signed payload.
 * @return string
 */
function extrachill_events_build_local_scene_digest_unsubscribe_form( array $verified ): string {
	$fields = '';
	foreach (
		array(
			'user'      => $verified['user_id'],
			'location'  => $verified['slug'],
			'expires'   => $verified['expires'],
			'signature' => $verified['signature'],
		) as $name => $value
	) {
		$fields .= '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
	}
	return '<form method="post" action="' . esc_url( rest_url( 'extrachill/v1/local-scene-digest/unsubscribe' ) ) . '">' . $fields . '<button type="submit">' . esc_html__( 'Unsubscribe from this Local Scene', 'extrachill-events' ) . '</button></form>';
}

/**
 * Verify a signed POST and remove only its digest-specific location consent.
 *
 * @param WP_REST_Request $request Unsubscribe request.
 * @param bool            $render  Whether to render the browser handoff page.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_events_local_scene_digest_unsubscribe( WP_REST_Request $request, bool $render = true ) {
	$verified = extrachill_events_verify_local_scene_digest_unsubscribe( $request );
	if ( is_wp_error( $verified ) ) {
		if ( $render ) {
			extrachill_events_render_local_scene_digest_unsubscribe_page( __( 'Unsubscribe link invalid', 'extrachill-events' ), __( 'This unsubscribe request is invalid or has expired. You can manage this Local Scene subscription from its events page.', 'extrachill-events' ) );
		}
		return $verified;
	}
	$user_id = $verified['user_id'];
	$slug    = $verified['slug'];
	if ( ! function_exists( 'extrachill_users_unsubscribe_from_entity' ) ) {
		if ( $render ) {
			extrachill_events_render_local_scene_digest_unsubscribe_page( __( 'Unsubscribe unavailable', 'extrachill-events' ), __( 'This subscription could not be updated right now. Please try again later or manage it from the Local Scene events page.', 'extrachill-events' ) );
		}
		return new WP_Error( 'local_scene_digest_unsubscribe_unavailable', __( 'Unsubscribe is temporarily unavailable.', 'extrachill-events' ), array( 'status' => 503 ) );
	}

	$result = extrachill_users_unsubscribe_from_entity( $user_id, EXTRACHILL_EVENTS_LOCAL_SCENE_DIGEST_ENTITY, EXTRACHILL_EVENTS_LOCAL_SCENE_DIGEST_TAXONOMY, $slug );
	if ( is_wp_error( $result ) ) {
		if ( $render ) {
			extrachill_events_render_local_scene_digest_unsubscribe_page( __( 'Unsubscribe unavailable', 'extrachill-events' ), __( 'This subscription could not be updated right now. Please try again later or manage it from the Local Scene events page.', 'extrachill-events' ) );
		}
		return $result;
	}
	if ( $render ) {
		extrachill_events_render_local_scene_digest_unsubscribe_page( __( "You're unsubscribed", 'extrachill-events' ), __( 'You will no longer receive the weekly email or its in-app update for this Local Scene. Your saved Local Scene and other notification preferences have not changed.', 'extrachill-events' ) );
	}
	return rest_ensure_response( array( 'unsubscribed' => true ) );
}

/**
 * Render the standalone browser confirmation used by the signed one-click route.
 *
 * @param string $title       Page title.
 * @param string $message     Confirmation message.
 * @param string $action_html Optional escaped action markup.
 */
function extrachill_events_render_local_scene_digest_unsubscribe_page( string $title, string $message, string $action_html = '' ): void {
	if ( ! headers_sent() ) {
		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow', true );
	}

	echo extrachill_events_build_local_scene_digest_unsubscribe_page( $title, $message, $action_html ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by dedicated builders.
	exit;
}

/**
 * Build escaped standalone unsubscribe confirmation markup.
 *
 * @param string $title       Page title.
 * @param string $message     Confirmation message.
 * @param string $action_html Optional escaped action markup.
 * @return string
 */
function extrachill_events_build_local_scene_digest_unsubscribe_page( string $title, string $message, string $action_html = '' ): string {
	$html  = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . esc_html( $title ) . '</title>';
	$html .= '<style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;background:#f6f6f6;color:#222;margin:0;padding:0}.wrap{max-width:520px;margin:10vh auto;padding:2rem;background:#fff;border-radius:8px;box-shadow:0 1px 4px rgba(0,0,0,.08);text-align:center}h1{font-size:1.4rem;margin:0 0 1rem}p{line-height:1.5;margin:0 0 1.5rem}a,button{display:inline-block;color:#fff;background:#222;text-decoration:none;padding:.65rem 1.25rem;border:0;border-radius:6px;cursor:pointer}form{margin:0 0 1rem}</style>';
	$html .= '</head><body><div class="wrap"><h1>' . esc_html( $title ) . '</h1><p>' . esc_html( $message ) . '</p>' . $action_html . '<a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Back to Extra Chill Events', 'extrachill-events' ) . '</a></div></body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- action markup comes from the escaped form builder.
	return $html;
}

/** Render the explicit archive opt-in directly after the Local Scene CTA. */
function extrachill_events_render_local_scene_digest_opt_in(): void {
	$term = extrachill_events_get_archive_scene_term();
	if ( null === $term ) {
		return;
	}
	$archive_url = get_term_link( $term );
	$intent      = is_user_logged_in() ? extrachill_events_get_archive_auth_intent( $term ) : null;
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only result from the nonce-protected confirmation redirect.
	$status = isset( $_GET['scene_status'] ) && is_scalar( $_GET['scene_status'] ) ? sanitize_key( wp_unslash( $_GET['scene_status'] ) ) : '';
	if ( ! is_user_logged_in() ) {
		echo '<aside class="events-market-context events-market-context--quiet"><span>' . esc_html__( 'Want a weekly email and in-app update for this Local Scene?', 'extrachill-events' ) . '</span> <a href="' . esc_url( extrachill_events_archive_intent_login_url( $term, 'subscribe_digest' ) ) . '">' . esc_html__( 'Sign in to subscribe', 'extrachill-events' ) . '</a></aside>';
		return;
	}

	if ( 'subscribe_digest' === $intent ) {
		$nonce = wp_create_nonce( 'extrachill_events_subscribe_scene_' . $term->term_id );
		?>
		<aside class="events-market-context" role="status" aria-live="polite">
			<div class="events-market-context__copy">
				<strong><?php esc_html_e( 'Confirm Local Scene updates', 'extrachill-events' ); ?></strong>
				<span><?php esc_html_e( 'This will set this Local Scene first, then subscribe you to its weekly email and in-app updates. Nothing changes until you confirm.', 'extrachill-events' ); ?></span>
			</div>
			<form method="post" action="<?php echo esc_url( extrachill_events_archive_intent_clean_url( $term ) ); ?>">
				<input type="hidden" name="extrachill_events_scene_action" value="subscribe_digest">
				<input type="hidden" name="extrachill_events_scene_nonce" value="<?php echo esc_attr( $nonce ); ?>">
				<button class="button-1 button-small" type="submit" autofocus><?php esc_html_e( 'Confirm: save and subscribe', 'extrachill-events' ); ?></button>
			</form>
		</aside>
		<?php
		return;
	}

	if ( 'subscribed' === $status ) {
		echo '<aside class="events-market-context" role="status" aria-live="polite"><span>' . esc_html__( 'Your Local Scene is saved and weekly email + in-app updates are on.', 'extrachill-events' ) . '</span></aside>';
	} elseif ( 'scene_saved' === $status ) {
		echo '<aside class="events-market-context" role="status" aria-live="polite"><span>' . esc_html__( 'Your Local Scene was saved, but the weekly updates could not be enabled. Please try subscribing again.', 'extrachill-events' ) . '</span></aside>';
	} elseif ( 'failed' === $status ) {
		echo '<aside class="events-market-context events-market-context--quiet" role="status" aria-live="polite"><span>' . esc_html__( 'We could not complete that Local Scene update. Nothing else was changed. Please try again.', 'extrachill-events' ) . '</span></aside>';
	}

	$current = extrachill_events_get_account_market();
	if ( ! $current || (int) $current['term_id'] !== (int) $term->term_id ) {
		echo '<aside class="events-market-context events-market-context--quiet"><span>' . esc_html__( 'Make this archive your Local Scene before subscribing to its weekly email and in-app update.', 'extrachill-events' ) . '</span></aside>';
		return;
	}
	?>
	<aside class="events-market-context" data-local-scene-digest-control>
		<div class="events-market-context__copy">
			<strong><?php esc_html_e( 'Weekly Local Scene email + update', 'extrachill-events' ); ?></strong>
			<span data-local-scene-digest-status><?php esc_html_e( 'Checking your subscription…', 'extrachill-events' ); ?></span>
		</div>
		<button class="button-1 button-small" type="button" disabled aria-pressed="false" data-local-scene-digest data-endpoint="<?php echo esc_url( rest_url( 'wp-abilities/v1/abilities/' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>" data-slug="<?php echo esc_attr( $term->slug ); ?>"><?php esc_html_e( 'Subscribe to email + updates', 'extrachill-events' ); ?></button>
	</aside>
	<?php
}
add_action( 'extrachill_archive_below_description', 'extrachill_events_render_local_scene_digest_opt_in', 5 );

/** Enqueue the progressive toggle only when its current-scene control exists. */
function extrachill_events_local_scene_digest_scripts(): void {
	$term = extrachill_events_get_archive_scene_term();
	if ( null === $term || ! is_user_logged_in() ) {
		return;
	}
	$current = extrachill_events_get_account_market();
	if ( ! $current || (int) $current['term_id'] !== (int) $term->term_id ) {
		return;
	}
	wp_enqueue_script( 'extrachill-events-local-scene-digest', EXTRACHILL_EVENTS_PLUGIN_URL . 'assets/js/local-scene-digest.js', array(), EXTRACHILL_EVENTS_VERSION, true );
}
add_action( 'wp_enqueue_scripts', 'extrachill_events_local_scene_digest_scripts' );
