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

get_header();
?>
<main class="extrachill-content ec-rsvp-verify-page">
	<div class="ec-rsvp-verify ec-surface-card ec-card-vertical-padding">
		<h1 class="ec-rsvp-verify__heading"><?php esc_html_e( 'RSVP Pass', 'extrachill-events' ); ?></h1>
		<?php if ( $authorized ) : ?>
			<?php
			$attendee    = get_user_by( 'id', (int) $pass['user_id'] );
			$event       = get_post( $event_id );
			$perk_text   = function_exists( 'extrachill_events_get_perk_text' ) ? extrachill_events_get_perk_text( $event_id ) : '';
			$pass_status = (string) $pass['status'];
			?>
			<div
				class="ec-rsvp-verify__result"
				data-event-id="<?php echo esc_attr( (string) $event_id ); ?>"
				data-user-id="<?php echo esc_attr( (string) $pass['user_id'] ); ?>"
			>
				<?php if ( $event ) : ?>
					<p class="ec-rsvp-verify__event"><?php echo esc_html( $event->post_title ); ?></p>
				<?php endif; ?>
				<p class="ec-rsvp-verify__name"><?php echo esc_html( $attendee ? $attendee->display_name : __( 'Unknown attendee', 'extrachill-events' ) ); ?></p>
				<?php if ( $perk_text ) : ?>
					<p class="ec-rsvp-verify__perk"><?php echo esc_html( $perk_text ); ?></p>
				<?php endif; ?>
				<?php if ( 'redeemed' === $pass_status ) : ?>
					<p class="ec-rsvp-verify__status">
						<?php
						printf(
							/* translators: %s: redemption time. */
							esc_html__( 'Already redeemed %s', 'extrachill-events' ),
							esc_html( (string) mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (string) $pass['redeemed_at'] ) )
						);
						?>
					</p>
				<?php else : ?>
					<button type="button" class="button-2 button-large ec-rsvp-verify__redeem">
						<?php esc_html_e( 'Redeem', 'extrachill-events' ); ?>
					</button>
					<p class="ec-rsvp-verify__status" hidden></p>
				<?php endif; ?>
			</div>
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
