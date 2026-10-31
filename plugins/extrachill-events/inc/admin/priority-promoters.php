<?php
/**
 * Priority Promoters
 *
 * Marks promoter terms as priority via term meta, mirroring priority venues.
 * Events carrying a priority promoter are flagged as priority events through
 * the established `_extrachill_priority_event` post meta, so ordering,
 * promoted-event surfaces, and the Local Scene digest all keep reading a
 * single source of truth.
 *
 * Flagging is one-way: removing a promoter (or un-marking a priority
 * promoter) never clears an event's priority flag, because that flag may
 * also have been set manually or granted by a paid priority boost.
 *
 * @package ExtraChillEvents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Add priority checkbox to promoter edit form
 *
 * @param WP_Term $term     Current term object.
 * @param string  $taxonomy Current taxonomy slug.
 */
function ec_events_priority_promoter_field( $term, $taxonomy ) {
	unset( $taxonomy );
	$is_priority = get_term_meta( $term->term_id, '_ec_priority_promoter', true );
	?>
	<tr class="form-field">
		<th scope="row">
			<label for="ec-priority-promoter"><?php esc_html_e( 'Priority Promoter', 'extrachill-events' ); ?></label>
		</th>
		<td>
			<input type="checkbox" name="ec_priority_promoter" id="ec-priority-promoter" value="1" <?php checked( $is_priority, true ); ?>>
			<p class="description"><?php esc_html_e( 'Events from priority promoters are marked as priority events and appear first in calendar day groups.', 'extrachill-events' ); ?></p>
		</td>
	</tr>
	<?php
}
add_action( 'promoter_edit_form_fields', 'ec_events_priority_promoter_field', 10, 2 );

/**
 * Save priority promoter meta
 *
 * @param int $term_id Term ID.
 * @param int $tt_id   Term taxonomy ID.
 */
function ec_events_save_priority_promoter( $term_id, $tt_id ) {
	unset( $tt_id );
	if ( ! current_user_can( 'manage_categories' ) ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Fires on core's edited_promoter term hook, which WordPress verifies with its own term-edit nonce before dispatch; capability is re-checked via current_user_can( 'manage_categories' ) above.
	$is_priority = isset( $_POST['ec_priority_promoter'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['ec_priority_promoter'] ) );

	ec_set_priority_promoter( (int) $term_id, $is_priority );
}
add_action( 'edited_promoter', 'ec_events_save_priority_promoter', 10, 2 );

/**
 * Get all priority promoter term IDs (cached)
 *
 * @return int[] Priority promoter term IDs.
 */
function ec_get_priority_promoter_ids() {
	$cached = wp_cache_get( 'ec_priority_promoter_ids', 'extrachill-events' );
	if ( false !== $cached ) {
		return $cached;
	}

	$terms = get_terms(
		array(
			'taxonomy'   => 'promoter',
			'hide_empty' => false,
			'meta_query' => array(
				array(
					'key'     => '_ec_priority_promoter',
					'value'   => '1',
					'compare' => '=',
				),
			),
			'fields'     => 'ids',
		)
	);

	$ids = is_wp_error( $terms ) ? array() : array_map( 'intval', $terms );
	wp_cache_set( 'ec_priority_promoter_ids', $ids, 'extrachill-events', HOUR_IN_SECONDS );

	return $ids;
}

/**
 * Mark or unmark a promoter as priority.
 *
 * Marking a promoter that was not already priority backfills its upcoming
 * published events. Unmarking leaves existing event flags untouched.
 *
 * @param int  $term_id  Promoter term ID.
 * @param bool $priority True to mark as priority, false to remove.
 * @return int Number of events newly flagged by the backfill.
 */
function ec_set_priority_promoter( int $term_id, bool $priority ): int {
	$was_priority = (bool) get_term_meta( $term_id, '_ec_priority_promoter', true );

	if ( $priority ) {
		update_term_meta( $term_id, '_ec_priority_promoter', true );
	} else {
		delete_term_meta( $term_id, '_ec_priority_promoter' );
	}

	wp_cache_delete( 'ec_priority_promoter_ids', 'extrachill-events' );

	if ( ! $priority || $was_priority ) {
		return 0;
	}

	return ec_backfill_priority_promoter_events( $term_id );
}

/**
 * Flag an event as priority unless it already is.
 *
 * Uses the same meta write and cache invalidation as every other priority
 * event path (admin meta box, set-priority-event ability, priority boost).
 *
 * @param int $post_id Event post ID.
 * @return bool True when the event was newly flagged.
 */
function ec_flag_priority_event( int $post_id ): bool {
	if ( get_post_meta( $post_id, '_extrachill_priority_event', true ) ) {
		return false;
	}

	update_post_meta( $post_id, '_extrachill_priority_event', true );
	wp_cache_delete( 'extrachill_priority_event_ids', 'extrachill-events' );

	return true;
}

/**
 * Flag events as priority when a priority promoter is assigned.
 *
 * @param int    $object_id Object ID.
 * @param array  $terms     Terms passed to wp_set_object_terms().
 * @param array  $tt_ids    Term taxonomy IDs now set on the object.
 * @param string $taxonomy  Taxonomy slug.
 * @return void
 */
function ec_events_flag_priority_promoter_event( $object_id, $terms, $tt_ids, $taxonomy ) {
	unset( $terms );
	if ( 'promoter' !== $taxonomy || empty( $tt_ids ) ) {
		return;
	}

	$priority_promoter_ids = ec_get_priority_promoter_ids();
	if ( empty( $priority_promoter_ids ) ) {
		return;
	}

	if ( 'data_machine_events' !== get_post_type( (int) $object_id ) ) {
		return;
	}

	$assigned = wp_get_object_terms( (int) $object_id, 'promoter', array( 'fields' => 'ids' ) );
	if ( is_wp_error( $assigned ) || empty( $assigned ) ) {
		return;
	}

	if ( array_intersect( array_map( 'intval', $assigned ), $priority_promoter_ids ) ) {
		ec_flag_priority_event( (int) $object_id );
	}
}
add_action( 'set_object_terms', 'ec_events_flag_priority_promoter_event', 10, 4 );

/**
 * Flag a promoter's upcoming published events as priority.
 *
 * Joins the denormalized `datamachine_event_dates` table (the query source of
 * truth for event datetimes) and canonical post status, matching
 * extrachill_get_promoted_events_for_location_term().
 *
 * @param int $term_id Promoter term ID.
 * @return int Number of events newly flagged.
 */
function ec_backfill_priority_promoter_events( int $term_id ): int {
	if ( $term_id <= 0 ) {
		return 0;
	}

	// Soft dependency on data-machine-events.
	if ( ! class_exists( '\DataMachineEvents\Core\EventDatesTable' )
		|| ! class_exists( '\DataMachineEvents\Blocks\Calendar\Query\UpcomingFilter' ) ) {
		return 0;
	}

	global $wpdb;

	$dates_table  = \DataMachineEvents\Core\EventDatesTable::table_name();
	$upcoming_sql = \DataMachineEvents\Blocks\Calendar\Query\UpcomingFilter::upcoming_sql( false, 'ed.post_id' );
	$now          = current_time( 'mysql' );

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$sql = $wpdb->prepare(
		"SELECT DISTINCT ed.post_id
		FROM {$dates_table} ed
		INNER JOIN {$wpdb->posts} p ON p.ID = ed.post_id
		INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = ed.post_id
		INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
		WHERE p.post_status = 'publish'
			AND tt.taxonomy = 'promoter'
			AND tt.term_id = %d
			AND {$upcoming_sql['where']}",
		array( $term_id, $now, $now )
	);

	$post_ids = array_map( 'intval', (array) $wpdb->get_col( $sql ) );
	// phpcs:enable

	$flagged = 0;
	foreach ( $post_ids as $post_id ) {
		if ( ec_flag_priority_event( $post_id ) ) {
			++$flagged;
		}
	}

	return $flagged;
}
