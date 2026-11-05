<?php
/**
 * RSVP verify page — the QR scan target.
 *
 * Non-enumerating by design: an invalid code, a valid code viewed by an
 * unauthorized user, and a logged-out visitor all render the identical
 * generic "could not be verified" message. Only an authorized host
 * (extrachill_events_user_can_manage_event()) sees the attendee's name and
 * a Redeem control. Ships no CSS of its own; see rsvp-pass.php's docblock
 * for the same design-system contract.
 *
 * @package ExtraChillEvents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page render; the state-changing Redeem action is its own ability call with its own authorization check.

$pass       = ( '' !== $code && class_exists( '\\ExtraChillEvents\\Core\\RsvpPassesTable' ) )
	? \ExtraChillEvents\Core\RsvpPassesTable::find_by_code( $code )
	: null;
$event_id   = $pass ? (int) $pass['event_id'] : 0;
$authorized = $pass
	&& is_user_logged_in()
	&& function_exists( 'extrachill_events_user_can_manage_event' )
	&& extrachill_events_user_can_manage_event( get_current_user_id(), $event_id );

// Not a per-event pass: maybe a venue's or promoter's standing list pass
// (extrachill#1130). Same non-enumerating contract: null renders the
// generic message below. ?event= pins the show; otherwise tonight's shows.
$owner_view = ( ! $pass && '' !== $code && is_user_logged_in() && function_exists( 'extrachill_events_owner_list_verify_view' ) )
	? extrachill_events_owner_list_verify_view( $code, get_current_user_id(), isset( $_GET['event'] ) ? absint( $_GET['event'] ) : 0 ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only render; check-in is its own authorized ability call.
	: null;

get_header();
?>
<main class="extrachill-content ec-rsvp-verify-page">
	<div class="ec-rsvp-verify ec-surface-card ec-card-padding ec-narrow-column">
		<h1 class="ec-rsvp-verify__heading"><?php esc_html_e( 'RSVP Pass', 'extrachill-events' ); ?></h1>
		<?php if ( $authorized ) : ?>
			<?php
			// Guest list passes (extrachill#433) belong to no account.
			$is_guest_pass = empty( $pass['user_id'] );
			$attendee      = $is_guest_pass ? null : get_user_by( 'id', (int) $pass['user_id'] );
			$event         = get_post( $event_id );
			$perk_text     = function_exists( 'extrachill_events_get_perk_text' ) ? extrachill_events_get_perk_text( $event_id ) : '';
			$pass_status   = (string) $pass['status'];
			?>
			<div
				class="ec-rsvp-verify__result"
				data-event-id="<?php echo esc_attr( (string) $event_id ); ?>"
				data-user-id="<?php echo esc_attr( (string) (int) $pass['user_id'] ); ?>"
				<?php if ( $is_guest_pass ) : ?>
				data-pass-code="<?php echo esc_attr( (string) $pass['code'] ); ?>"
				<?php endif; ?>
			>
				<?php if ( $event ) : ?>
					<p class="ec-rsvp-verify__event"><?php echo esc_html( $event->post_title ); ?></p>
				<?php endif; ?>
				<p class="ec-rsvp-verify__name">
				<?php
				if ( $is_guest_pass ) {
					// Invited-band passes read "Jake (Slim S.O.U.L.)" (extrachill#1128).
					echo esc_html(
						function_exists( 'extrachill_events_guest_list_pass_label' )
							? extrachill_events_guest_list_pass_label( (string) ( $pass['guest_name'] ?? '' ), extrachill_events_guest_list_pass_party_name( $pass ) )
							: ( ! empty( $pass['guest_name'] ) ? (string) $pass['guest_name'] : __( 'Guest list pass', 'extrachill-events' ) )
					);
				} else {
					echo esc_html( $attendee ? $attendee->display_name : __( 'Unknown attendee', 'extrachill-events' ) );
				}
				?>
				</p>
				<?php if ( $perk_text ) : ?>
					<p class="ec-rsvp-verify__perk"><?php echo esc_html( $perk_text ); ?></p>
				<?php endif; ?>
				<?php if ( function_exists( 'extrachill_events_get_guest_list_counts' ) ) : ?>
					<p class="ec-rsvp-verify__count"><?php echo esc_html( extrachill_events_format_guest_list_count( extrachill_events_get_guest_list_counts( $event_id ) ) ); ?></p>
				<?php endif; ?>
				<?php if ( 'redeemed' === $pass_status ) : ?>
					<p class="ec-rsvp-verify__status">
						<?php
						printf(
							/* translators: %s: redemption time. */
							$is_guest_pass ? esc_html__( 'Already checked in %s', 'extrachill-events' ) : esc_html__( 'Already redeemed %s', 'extrachill-events' ),
							esc_html( (string) mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (string) $pass['redeemed_at'] ) )
						);
						?>
					</p>
				<?php elseif ( 'revoked' === $pass_status ) : ?>
					<p class="ec-rsvp-verify__status notice notice-error">
						<?php echo esc_html( $is_guest_pass ? __( 'Not on the list. This guest was taken off the guest list.', 'extrachill-events' ) : __( 'Not valid. This RSVP was cancelled.', 'extrachill-events' ) ); ?>
					</p>
				<?php elseif ( 'active' !== $pass_status ) : ?>
					<?php // Pending and denied names (extrachill#1129) are names the venue never approved. ?>
					<p class="ec-rsvp-verify__status notice notice-error">
						<?php
						echo esc_html(
							'pending' === $pass_status
								? __( 'Not on the list. The venue has not approved this name yet.', 'extrachill-events' )
								: __( 'Not on the list. The venue did not approve this name.', 'extrachill-events' )
						);
						?>
					</p>
				<?php else : ?>
					<button type="button" class="button-2 button-large ec-rsvp-verify__redeem">
						<?php echo esc_html( $is_guest_pass ? __( 'Check in', 'extrachill-events' ) : __( 'Redeem', 'extrachill-events' ) ); ?>
					</button>
					<p class="ec-rsvp-verify__status" hidden></p>
				<?php endif; ?>
			</div>
		<?php elseif ( $owner_view ) : ?>
			<?php $owner_list_line = sprintf( /* translators: 1: "Venue list" or "Promoter list", 2: venue or promoter name. */ __( '%1$s: %2$s', 'extrachill-events' ), $owner_view['owner']['label'], $owner_view['owner']['owner_name'] ); ?>
			<?php if ( 'eligible' === $owner_view['state'] ) : ?>
				<p class="ec-rsvp-verify__name"><?php echo esc_html( $owner_view['name'] ); ?></p>
				<p class="ec-rsvp-verify__perk"><?php echo esc_html( $owner_list_line ); ?></p>
				<?php foreach ( $owner_view['events'] as $owner_event ) : ?>
					<div
						class="ec-rsvp-verify__result"
						data-event-id="<?php echo esc_attr( (string) $owner_event['event_id'] ); ?>"
						data-user-id="0"
						data-pass-code="<?php echo esc_attr( (string) $owner_view['code'] ); ?>"
					>
						<p class="ec-rsvp-verify__event"><?php echo esc_html( $owner_event['title'] ); ?></p>
						<?php if ( '' !== $owner_event['checked_in_at'] ) : ?>
							<p class="ec-rsvp-verify__status">
								<?php
								printf(
									/* translators: %s: check-in time. */
									esc_html__( 'Already checked in %s', 'extrachill-events' ),
									esc_html( (string) mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), get_date_from_gmt( (string) $owner_event['checked_in_at'] ) ) )
								);
								?>
							</p>
						<?php else : ?>
							<button type="button" class="button-2 button-large ec-rsvp-verify__redeem"><?php esc_html_e( 'Check in', 'extrachill-events' ); ?></button>
							<p class="ec-rsvp-verify__status" hidden></p>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			<?php elseif ( 'removed' === $owner_view['state'] ) : ?>
				<p class="ec-rsvp-verify__status notice notice-error"><?php esc_html_e( 'Not on the list. This person was taken off the list.', 'extrachill-events' ); ?></p>
				<p class="ec-rsvp-verify__perk"><?php echo esc_html( $owner_list_line ); ?></p>
			<?php else : ?>
				<p class="ec-rsvp-verify__status notice notice-error">
					<?php
					/* translators: %s: venue or promoter name. */
					printf( esc_html__( 'Not on the list. This pass only works at %s shows.', 'extrachill-events' ), esc_html( $owner_view['owner']['owner_name'] ) );
					?>
				</p>
			<?php endif; ?>
		<?php else : ?>
			<p class="notice notice-info"><?php esc_html_e( 'This pass could not be verified.', 'extrachill-events' ); ?></p>
			<?php if ( ! is_user_logged_in() ) : ?>
				<p>
					<a class="button-3 button-medium" href="<?php echo esc_url( wp_login_url( home_url( add_query_arg( null, null ) ) ) ); ?>">
						<?php esc_html_e( 'Log In', 'extrachill-events' ); ?>
					</a>
				</p>
			<?php endif; ?>
		<?php endif; ?>
	</div>
</main>
<?php
get_footer();
