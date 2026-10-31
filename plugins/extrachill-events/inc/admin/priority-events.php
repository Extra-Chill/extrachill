<?php
/**
 * Priority Events Admin
 *
 * Admin UI for marking events as priority via post meta.
 *
 * @package ExtraChillEvents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Add priority event meta box to event edit screen
 *
 * @return void
 */
function extrachill_events_priority_event_meta_box() {
	add_meta_box(
		'extrachill-priority-event',
		__( 'Priority Event', 'extrachill-events' ),
		'extrachill_events_priority_event_meta_box_callback',
		'data_machine_events',
		'side',
		'high'
	);
}
add_action( 'add_meta_boxes', 'extrachill_events_priority_event_meta_box' );

/**
 * Render priority event meta box content
 *
 * @param WP_Post $post Current post object.
 * @return void
 */
function extrachill_events_priority_event_meta_box_callback( $post ) {
	wp_nonce_field( 'extrachill_priority_event_nonce', 'extrachill_priority_event_nonce_field' );
	$is_priority = get_post_meta( $post->ID, '_extrachill_priority_event', true );
	?>
	<label>
		<input type="checkbox" name="extrachill_priority_event" value="1" <?php checked( $is_priority, true ); ?>>
		<?php esc_html_e( 'Mark as priority event', 'extrachill-events' ); ?>
	</label>
	<p class="description"><?php esc_html_e( 'Priority events appear first in calendar day groups.', 'extrachill-events' ); ?></p>
	<?php
}

/**
 * Save priority event meta on post save
 *
 * @param int $post_id Post ID being saved.
 * @return void
 */
function extrachill_events_save_priority_event( $post_id ) {
	if ( ! isset( $_POST['extrachill_priority_event_nonce_field'] ) ||
		! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['extrachill_priority_event_nonce_field'] ) ), 'extrachill_priority_event_nonce' ) ) {
		return;
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	$is_priority = isset( $_POST['extrachill_priority_event'] ) && '1' === $_POST['extrachill_priority_event'];

	if ( $is_priority ) {
		update_post_meta( $post_id, '_extrachill_priority_event', true );
	} else {
		delete_post_meta( $post_id, '_extrachill_priority_event' );
	}

	wp_cache_delete( 'extrachill_priority_event_ids', 'extrachill-events' );
}
add_action( 'save_post_data_machine_events', 'extrachill_events_save_priority_event' );

/**
 * Get all priority event IDs (cached)
 *
 * @return array Array of priority event post IDs.
 */
function extrachill_get_priority_event_ids() {
	$cached = wp_cache_get( 'extrachill_priority_event_ids', 'extrachill-events' );
	if ( false !== $cached ) {
		return $cached;
	}

	global $wpdb;
	$ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
			'_extrachill_priority_event',
			'1'
		)
	);

	$ids = array_map( 'intval', $ids );
	wp_cache_set( 'extrachill_priority_event_ids', $ids, 'extrachill-events', HOUR_IN_SECONDS );

	return $ids;
}

/**
 * Resolve promoted (priority) upcoming events scoped to one location term.
 *
 * Restricted to `publish` posts with an upcoming `start_datetime`, joined
 * against the denormalized `datamachine_event_dates` table (the query
 * source of truth for event datetimes — see
 * \DataMachineEvents\Core\EventDatesTable). This is promotion, not a second
 * calendar: the result is bounded to 1-3 events regardless of how many
 * priority events exist network-wide.
 *
 * Caching: the cache key is derived from BOTH the target location and the
 * current priority-event ID set (`extrachill_get_priority_event_ids()`,
 * already cached for an hour and cleared on every existing promotion path —
 * the admin save handler above, `PriorityEventAbilities::setPriorityEvent()`,
 * and the extrachill-shop priority-boost grant). Deriving the key this way
 * makes the cache self-invalidating: the instant a promotion is granted or
 * revoked, the underlying ID list changes, the derived key changes with it,
 * and a stale promotion can never be served from a prior generation's cache
 * entry. No new cache-clear call is needed at any existing invalidation
 * site. The short 5-minute TTL bounds the one remaining staleness source —
 * an event's start_datetime passing while the priority set itself is
 * unchanged.
 *
 * @param int $location_term_id Location taxonomy term ID.
 * @param int $limit            Maximum events to return. Clamped to 1-3.
 * @return int[] Promoted event post IDs, soonest start first. Empty when
 *               nothing qualifies (no priority events, no upcoming publish
 *               events in this location, or the data-machine-events query
 *               dependencies are unavailable).
 */
function extrachill_get_promoted_events_for_location_term( int $location_term_id, int $limit = 3 ) {
	if ( $location_term_id <= 0 ) {
		return array();
	}

	$priority_ids = extrachill_get_priority_event_ids();
	if ( empty( $priority_ids ) ) {
		return array();
	}

	// Soft dependency on data-machine-events: never hard-require it from this
	// plugin's own query layer.
	if ( ! class_exists( '\DataMachineEvents\Core\EventDatesTable' )
		|| ! class_exists( '\DataMachineEvents\Blocks\Calendar\Query\UpcomingFilter' ) ) {
		return array();
	}

	$limit = max( 1, min( 3, $limit ) );

	$cache_key = 'extrachill_promoted_events_' . $location_term_id . '_' . md5( implode( ',', $priority_ids ) );
	$cached    = wp_cache_get( $cache_key, 'extrachill-events' );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	global $wpdb;

	$dates_table = \DataMachineEvents\Core\EventDatesTable::table_name();

	// Status is checked against the canonical posts table rather than the
	// event-dates table's denormalized post_status column: EventDatesTable's
	// own status-drift repair tooling (find_status_drift_batch()) documents
	// that the denormalized column can lag canonical status, and this query
	// is already bounded to a handful of priority-event IDs, so the extra
	// join costs nothing.
	$upcoming_sql = \DataMachineEvents\Blocks\Calendar\Query\UpcomingFilter::upcoming_sql( false, 'ed.post_id' );
	$now          = current_time( 'mysql' );

	$id_placeholders = implode( ',', array_fill( 0, count( $priority_ids ), '%d' ) );

	// Table names and the shared UpcomingFilter WHERE fragment are internally
	// constructed identifiers; every request-derived value below remains
	// prepared via $wpdb->prepare(). The ReplacementsWrongNumber sniff can't
	// count placeholders through the single-array-argument calling
	// convention below — the same established pattern used in
	// my-shows-map-filter.php's venue-scoped concert query.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$sql = $wpdb->prepare(
		"SELECT ed.post_id
		FROM {$dates_table} ed
		INNER JOIN {$wpdb->posts} p ON p.ID = ed.post_id
		INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = ed.post_id
		INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
		WHERE ed.post_id IN ({$id_placeholders})
			AND p.post_status = 'publish'
			AND tt.taxonomy = 'location'
			AND tt.term_id = %d
			AND {$upcoming_sql['where']}
		ORDER BY ed.start_datetime ASC
		LIMIT %d",
		array_merge( $priority_ids, array( $location_term_id, $now, $now, $limit ) )
	);

	$post_ids = array_map( 'intval', (array) $wpdb->get_col( $sql ) );
	// phpcs:enable

	wp_cache_set( $cache_key, $post_ids, 'extrachill-events', 5 * MINUTE_IN_SECONDS );

	return $post_ids;
}

/**
 * Render a single promoted-event card.
 *
 * Shared by the location-archive callout
 * (inc/templates/location-promoted-events.php) and the Local Scene-gated
 * homepage card (inc/home/promoted-event-card.php) so the two render
 * surfaces can never drift in markup or CSS class names. Styles live in
 * assets/css/calendar.css under `.promoted-event-card`.
 *
 * @param int $post_id Promoted event post ID.
 * @return void
 */
function extrachill_render_promoted_event_card( int $post_id ) {
	$permalink = get_permalink( $post_id );
	if ( ! $permalink ) {
		return;
	}

	$dates = class_exists( '\DataMachineEvents\Core\EventDatesTable' )
		? \DataMachineEvents\Core\EventDatesTable::get( $post_id )
		: null;

	$venues     = get_the_terms( $post_id, 'venue' );
	$venue_name = ( is_array( $venues ) && ! empty( $venues ) && isset( $venues[0]->name ) ) ? $venues[0]->name : '';
	?>
	<a class="promoted-event-card" href="<?php echo esc_url( $permalink ); ?>">
		<span class="promoted-event-card__label"><?php esc_html_e( 'Promoted', 'extrachill-events' ); ?></span>
		<h3 class="promoted-event-card__title"><?php echo esc_html( get_the_title( $post_id ) ); ?></h3>
		<?php if ( $dates && ! empty( $dates->start_datetime ) ) : ?>
			<span class="promoted-event-card__date"><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $dates->start_datetime ) ) ); ?></span>
		<?php endif; ?>
		<?php if ( $venue_name ) : ?>
			<span class="promoted-event-card__venue"><?php echo esc_html( $venue_name ); ?></span>
		<?php endif; ?>
	</a>
	<?php
}
