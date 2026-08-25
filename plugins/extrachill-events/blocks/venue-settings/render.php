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
use ExtraChillEvents\Core\LocalSupportSchema;
use ExtraChillEvents\Core\LocalSupportWorkspace;
use ExtraChillEvents\Core\PromoterAuthorization;
use ExtraChillEvents\Core\PromoterWorkspace;
use ExtraChillEvents\Core\VenueAuthorization;
use ExtraChillEvents\Core\VenueMembershipRepository;

$wrapper_attributes = get_block_wrapper_attributes(
	array( 'class' => 'ec-venue-settings ec-mobile-full-width-panel' )
);

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only venue selection; Ability authorization remains authoritative.
$requested_venue_id         = isset( $_GET['venue_id'] ) && is_scalar( $_GET['venue_id'] ) ? absint( wp_unslash( $_GET['venue_id'] ) ) : 0;
$requested_identity         = isset( $_GET['identity'] ) && is_scalar( $_GET['identity'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['identity'] ) ) : '';
$requested_booking_id       = isset( $_GET['booking_id'] ) && is_scalar( $_GET['booking_id'] ) ? absint( wp_unslash( $_GET['booking_id'] ) ) : 0;
$requested_booking_venue_id = isset( $_GET['booking_venue_id'] ) && is_scalar( $_GET['booking_venue_id'] ) ? absint( wp_unslash( $_GET['booking_venue_id'] ) ) : 0;
// phpcs:enable WordPress.Security.NonceVerification.Recommended

if ( '' === $requested_identity && $requested_venue_id ) {
	$requested_identity = 'venue:' . $requested_venue_id;
}
if ( preg_match( '/^venue:([1-9][0-9]{0,9})$/', $requested_identity, $identity_match ) ) {
	$requested_venue_id = (int) $identity_match[1];
} elseif ( 0 === strpos( $requested_identity, 'promoter:' ) ) {
	$requested_venue_id = 0;
}

if ( ! is_user_logged_in() ) {
	$login_venue_id = $requested_venue_id ? $requested_venue_id : $requested_booking_venue_id;
	$login_url      = wp_login_url( ec_events_get_booking_console_url( $login_venue_id, $requested_booking_id ) );
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
$promoter_mode = '' !== $requested_identity && 0 === strpos( $requested_identity, 'promoter' );
$all_terms     = $promoter_mode
	? array()
	: get_terms(
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
if ( ! $promoter_mode && $is_admin ) {
	$active_venue_ids = ( new VenueMembershipRepository() )->list_active_venue_ids();
	$active_venue_ids = is_wp_error( $active_venue_ids ) ? array() : $active_venue_ids;
	foreach ( $claim_venues as $venue ) {
		if ( ! in_array( $venue['id'], $active_venue_ids, true ) ) {
			continue;
		}
		$managed_venues[] = array_merge(
			$venue,
			array(
				'status'   => 'administrator',
				'is_owner' => false,
			)
		);
	}
} elseif ( ! $promoter_mode ) {
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

foreach ( $managed_venues as &$venue ) {
	$venue_id                = (int) $venue['id'];
	$venue_term              = get_term( $venue_id, 'venue' );
	$archive_url             = $venue_term instanceof WP_Term ? get_term_link( $venue_term ) : '';
	$venue['can_access']     = true === $authorization->authorize( $user_id, $venue_id, VenueAuthorization::ACTION_ACCESS_VENUE );
	$venue['can_manage']     = true === $authorization->authorize( $user_id, $venue_id, VenueAuthorization::ACTION_MANAGE_MEMBERS );
	$venue['slug']           = $venue_term instanceof WP_Term ? $venue_term->slug : '';
	$venue['archive_url']    = is_wp_error( $archive_url ) ? '' : $archive_url;
	$venue['booking_url']    = $venue['can_access'] && $venue_term instanceof WP_Term ? \ExtraChillEvents\Core\VenueBookingEmbed::booking_url( $venue_term ) : '';
	$venue_data              = function_exists( 'data_machine_events_get_venue_data' ) ? data_machine_events_get_venue_data( $venue_id ) : null;
	$venue['timezone']       = is_array( $venue_data ) ? (string) ( $venue_data['timezone'] ?? '' ) : '';
	$venue['support_events'] = $venue['can_access'] && function_exists( 'extrachill_events_local_support_organizer_events' )
		? extrachill_events_local_support_organizer_events( $user_id, $venue_id )
		: array();
	$venue['link_page']      = array( 'status' => 'unavailable' );
	if ( function_exists( 'ec_link_page_editor_is_available' ) && ec_link_page_editor_is_available() && function_exists( 'ec_get_link_page_id_for_owner' ) ) {
		$reference = \ExtraChillEvents\Core\VenueLinkPages::owner_reference( $venue_id );
		$page_id   = is_wp_error( $reference ) ? $reference : ec_get_link_page_id_for_owner( $reference );
		if ( ! is_wp_error( $page_id ) ) {
			$venue['link_page']['status'] = $page_id ? 'available' : 'not_provisioned';
		}
	}
}
unset( $venue );

$selected = null;
foreach ( $managed_venues as $venue ) {
	if ( $requested_venue_id === $venue['id'] ) {
		$selected = $venue;
		break;
	}
}

$can_access       = $selected && $selected['can_access'];
$can_manage       = $selected && $selected['can_manage'];
$booking_venue_id = $can_access ? (int) $selected['id'] : 0;
if ( ! $selected && $requested_booking_id && $requested_booking_venue_id ) {
	foreach ( $managed_venues as $venue ) {
		if ( $requested_booking_venue_id === $venue['id'] && $venue['can_access'] ) {
			$booking_venue_id = $venue['id'];
			break;
		}
	}
}
$workspace = ( new PromoterWorkspace( null, null, null, false ) )->resolve_for_user( $user_id, $requested_identity );
if ( is_wp_error( $workspace ) ) {
	$browser_user = get_userdata( $user_id );
	$workspace    = array(
		'actor'                  => array(
			'id'   => $user_id,
			'name' => $browser_user ? (string) ( $browser_user->display_name ?? '' ) : '',
		),
		'identities'             => array(),
		'selection'              => array(
			'reference' => $requested_identity,
			'state'     => 'denied',
			'type'      => $promoter_mode ? 'promoter' : 'venue',
			'reason'    => $promoter_mode ? 'invalid' : 'unavailable',
		),
		'promoter'               => null,
		'venue'                  => null,
		'granted_venues'         => array(),
		'promoter_relationships' => array(),
	);
}
$context = array(
	'user'      => array(
		'id'       => $user_id,
		'name'     => wp_get_current_user()->display_name,
		'is_admin' => $is_admin,
	),
	'route_url' => home_url( '/venue-settings/' ),
	'workspace' => $workspace,
);
if ( ! $promoter_mode ) {
	$context = array_merge(
		$context,
		array(
			'venues'             => $managed_venues,
			'claim_venues'       => $claim_venues,
			'selected_venue'     => $selected,
			'can_access'         => $can_access,
			'can_manage'         => $can_manage,
			'requested_venue_id' => in_array( $requested_venue_id, array_column( $claim_venues, 'id' ), true ) ? $requested_venue_id : 0,
			'booking_id'         => $booking_venue_id ? $requested_booking_id : 0,
			'booking_venue_id'   => $booking_venue_id,
			'booking_url'        => $selected['booking_url'] ?? '',
			'support_events'     => $selected['support_events'] ?? array(),
		)
	);
}
$context_id                            = wp_unique_id( 'ec-venue-settings-context-' );
$editor_available                      = function_exists( 'ec_enqueue_link_page_editor' ) && ec_enqueue_link_page_editor();
$context['link_page_editor_available'] = $editor_available;
$support_requests                      = ! $promoter_mode && $can_access && LocalSupportSchema::is_ready()
	? ( new LocalSupportWorkspace() )->venue_requests( (int) $selected['id'], $user_id )
	: array();
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
	<?php if ( ! empty( $support_requests ) ) : ?>
		<section class="ec-block-shell ec-venue-settings__support" aria-labelledby="ec-venue-support-heading">
			<div class="ec-block-shell-inner ec-block-shell-inner--narrow">
				<h2 id="ec-venue-support-heading"><?php esc_html_e( 'Local support requests', 'extrachill-events' ); ?></h2>
				<p><?php esc_html_e( 'Private opportunities for this exact venue.', 'extrachill-events' ); ?></p>
				<ul class="ec-venue-settings__records">
					<?php foreach ( $support_requests as $request ) : ?>
						<li><span><?php echo esc_html( get_the_title( (int) $request['event_id'] ) ); ?> <small><?php echo esc_html( ucfirst( $request['status'] ) ); ?></small></span><a class="button-2 button-small" href="<?php echo esc_url( home_url( '/local-support/' . (int) $request['id'] . '/' ) ); ?>"><?php esc_html_e( 'Open workspace', 'extrachill-events' ); ?></a></li>
					<?php endforeach; ?>
				</ul>
			</div>
		</section>
	<?php endif; ?>
</div>
