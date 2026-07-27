<?php
/**
 * Venue settings server bootstrap.
 *
 * Authentication and venue choices are rendered by WordPress. The client uses
 * these only for presentation; every data read and mutation is reauthorized by
 * its canonical Ability.
 *
 * @package ExtraChillEvents
 */

use ExtraChillEvents\Core\BookingSchema;
use ExtraChillEvents\Core\VenueAuthorization;

$wrapper_attributes = get_block_wrapper_attributes(
	array( 'class' => 'ec-venue-settings ec-mobile-full-width-panel' )
);

if ( ! is_user_logged_in() ) {
	$login_url = wp_login_url( home_url( '/venue-settings/' ) );
	?>
	<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by get_block_wrapper_attributes(). ?>>
		<div class="ec-block-shell ec-block-shell--depth-0">
			<div class="ec-block-shell-inner ec-block-shell-inner--narrow">
				<h1><?php esc_html_e( 'Venue settings', 'extrachill-events' ); ?></h1>
				<p><?php esc_html_e( 'Sign in to manage a venue or request access to an unclaimed profile.', 'extrachill-events' ); ?></p>
				<a class="button-1" href="<?php echo esc_url( $login_url ); ?>"><?php esc_html_e( 'Sign in', 'extrachill-events' ); ?></a>
			</div>
		</div>
	</div>
	<?php
	return;
}

if ( ! BookingSchema::is_ready() ) {
	?>
	<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by get_block_wrapper_attributes(). ?>>
		<div class="ec-inline-status ec-inline-status--error" role="alert">
			<?php esc_html_e( 'Venue management is temporarily unavailable. Please try again later.', 'extrachill-events' ); ?>
		</div>
	</div>
	<?php
	return;
}

$user_id       = get_current_user_id();
$authorization = new VenueAuthorization();
$is_admin      = $authorization->is_administrator( $user_id );
$all_terms     = get_terms(
	array(
		'taxonomy'   => 'venue',
		'hide_empty' => false,
		'orderby'    => 'name',
		'order'      => 'ASC',
	)
);
$all_terms     = is_wp_error( $all_terms ) ? array() : $all_terms;
$claim_venues  = array_map(
	static function ( WP_Term $term ): array {
		return array(
			'id'   => (int) $term->term_id,
			'name' => $term->name,
		);
	},
	$all_terms
);

$managed_venues = array();
if ( $is_admin ) {
	foreach ( $claim_venues as $venue ) {
		$managed_venues[] = array_merge(
			$venue,
			array(
				'status'   => 'administrator',
				'is_owner' => false,
			)
		);
	}
} else {
	global $wpdb;
	$table = BookingSchema::memberships_table();
	$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT venue_term_id, is_owner, status FROM {$table} WHERE user_id = %d ORDER BY created_at ASC", $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Private current-user bootstrap scoped to the Events site.
	foreach ( (array) $rows as $row ) {
		$venue_term = get_term( (int) $row['venue_term_id'], 'venue' );
		if ( ! $venue_term instanceof WP_Term ) {
			continue;
		}
		$managed_venues[] = array(
			'id'       => (int) $venue_term->term_id,
			'name'     => $venue_term->name,
			'status'   => sanitize_key( $row['status'] ),
			'is_owner' => (bool) $row['is_owner'],
		);
	}
}

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only venue selection; Ability authorization remains authoritative.
$requested_venue_id   = isset( $_GET['venue_id'] ) && is_scalar( $_GET['venue_id'] ) ? absint( wp_unslash( $_GET['venue_id'] ) ) : 0;
$requested_booking_id = isset( $_GET['booking_id'] ) && is_scalar( $_GET['booking_id'] ) ? absint( wp_unslash( $_GET['booking_id'] ) ) : 0;
// phpcs:enable WordPress.Security.NonceVerification.Recommended
$selected = null;
foreach ( $managed_venues as $venue ) {
	if ( $requested_venue_id === $venue['id'] || ( ! $requested_venue_id && 'active' === $venue['status'] ) ) {
		$selected = $venue;
		break;
	}
}

$can_access = $selected && true === $authorization->authorize( $user_id, $selected['id'], VenueAuthorization::ACTION_ACCESS_VENUE );
$can_manage = $selected && true === $authorization->authorize( $user_id, $selected['id'], VenueAuthorization::ACTION_MANAGE_MEMBERS );
$context    = array(
	'user'           => array(
		'id'       => $user_id,
		'name'     => wp_get_current_user()->display_name,
		'is_admin' => $is_admin,
	),
	'venues'         => $managed_venues,
	'claim_venues'   => $claim_venues,
	'selected_venue' => $selected,
	'can_access'     => $can_access,
	'can_manage'     => $can_manage,
	'route_url'      => home_url( '/venue-settings/' ),
	'booking_id'     => $can_access ? $requested_booking_id : 0,
);
$context_id = wp_unique_id( 'ec-venue-settings-context-' );
?>
<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by get_block_wrapper_attributes(). ?>>
	<div class="ec-venue-settings__root" data-context-id="<?php echo esc_attr( $context_id ); ?>">
		<div class="ec-block-shell ec-block-shell--depth-0" aria-busy="true">
			<div class="ec-block-shell-inner ec-block-shell-inner--narrow">
				<p><?php esc_html_e( 'Loading venue settings...', 'extrachill-events' ); ?></p>
			</div>
		</div>
	</div>
	<script id="<?php echo esc_attr( $context_id ); ?>" type="application/json"><?php echo wp_json_encode( $context, JSON_HEX_TAG | JSON_HEX_AMP ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON is hex-escaped for an inert script element. ?></script>
</div>
