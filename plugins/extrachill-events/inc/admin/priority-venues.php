<?php
/**
 * Priority Venues Admin
 *
 * Admin UI for marking venues as priority via term meta.
 *
 * @package ExtraChillEvents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Add priority checkbox to venue edit form
 *
 * @param WP_Term $term     Current term object.
 * @param string  $taxonomy Current taxonomy slug.
 */
function ec_events_priority_venue_field( $term, $taxonomy ) {
	$is_priority = get_term_meta( $term->term_id, '_ec_priority_venue', true );
	?>
	<tr class="form-field">
		<th scope="row">
			<label for="ec-priority-venue"><?php esc_html_e( 'Priority Venue', 'extrachill-events' ); ?></label>
		</th>
		<td>
			<input type="checkbox" name="ec_priority_venue" id="ec-priority-venue" value="1" <?php checked( $is_priority, true ); ?>>
			<p class="description"><?php esc_html_e( 'Priority venues appear first in calendar day groups.', 'extrachill-events' ); ?></p>
		</td>
	</tr>
	<?php
}
add_action( 'venue_edit_form_fields', 'ec_events_priority_venue_field', 10, 2 );

/**
 * Save priority venue meta
 *
 * @param int $term_id Term ID.
 * @param int $tt_id   Term taxonomy ID.
 */
function ec_events_save_priority_venue( $term_id, $tt_id ) {
	if ( ! current_user_can( 'manage_categories' ) ) {
		return;
	}

	$is_priority = isset( $_POST['ec_priority_venue'] ) && '1' === $_POST['ec_priority_venue'];

	if ( $is_priority ) {
		update_term_meta( $term_id, '_ec_priority_venue', true );
	} else {
		delete_term_meta( $term_id, '_ec_priority_venue' );
	}

	wp_cache_delete( 'ec_priority_venue_ids', 'extrachill-events' );
}
add_action( 'edited_venue', 'ec_events_save_priority_venue', 10, 2 );

/**
 * Get all priority venue term IDs (cached)
 *
 * @return array Array of priority venue term IDs.
 */
function ec_get_priority_venue_ids() {
	$cached = wp_cache_get( 'ec_priority_venue_ids', 'extrachill-events' );
	if ( false !== $cached ) {
		return $cached;
	}

	$terms = get_terms(
		array(
			'taxonomy'   => 'venue',
			'hide_empty' => false,
			'meta_query' => array(
				array(
					'key'     => '_ec_priority_venue',
					'value'   => '1',
					'compare' => '=',
				),
			),
			'fields'     => 'ids',
		)
	);

	$ids = is_wp_error( $terms ) ? array() : $terms;
	wp_cache_set( 'ec_priority_venue_ids', $ids, 'extrachill-events', HOUR_IN_SECONDS );

	return $ids;
}
