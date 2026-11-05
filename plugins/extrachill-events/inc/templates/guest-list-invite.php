<?php
/**
 * Guest list invite page — /events/{slug}/guest-invite/{token}/ (extrachill#1128).
 *
 * The invited band's or management's private page: type a guest's name,
 * they're on the list. Shows the spots left (their allotment when the venue
 * turned allotments on, otherwise the event's), the names they added with a
 * pass link to text each guest, and a Remove button.
 *
 * Ships no CSS of its own: theme classes plus the guest-list page hooks,
 * allow-listed in RsvpPassDesignSystemTest.php.
 *
 * @package ExtraChillEvents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$event_id = (int) get_queried_object_id();
$event    = get_post( $event_id );
$party    = extrachill_events_guest_invite_request_party();
$revoked  = is_wp_error( $party );
$token    = extrachill_events_guest_invite_request_token();
$is_past  = function_exists( 'ec_users_get_event_timing' ) && 'past' === ec_users_get_event_timing( $event_id, (int) get_current_blog_id() );

$guests     = array();
$spots_line = '';
$no_room    = false;
$added_pass = null;
if ( ! $revoked ) {
	$guests     = extrachill_events_guest_list_party_passes( $party );
	$summary    = extrachill_events_guest_list_party_summary( $event_id, $party );
	$guest_list = extrachill_events_get_guest_list_counts( $event_id );
	if ( null !== $summary['spots_left'] ) {
		$no_room    = 0 === $summary['spots_left'] || ! empty( $guest_list['full'] );
		$spots_line = sprintf(
			/* translators: 1: names added, 2: spots given to this band. */
			_n( '%1$d of %2$d spot used.', '%1$d of %2$d spots used.', (int) $summary['allotment'], 'extrachill-events' ),
			(int) $summary['used'],
			(int) $summary['allotment']
		);
	} else {
		$no_room    = ! empty( $guest_list['full'] );
		$spots_line = sprintf(
			/* translators: %d: names added. */
			_n( '%d name added.', '%d names added.', (int) $summary['used'], 'extrachill-events' ),
			(int) $summary['used']
		);
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only confirmation after the add redirect; the code is checked against this party below.
	$added_code = isset( $_GET['added'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_GET['added'] ) ) ) : '';
	foreach ( $guests as $guest ) {
		if ( '' !== $added_code && (string) $guest['code'] === $added_code ) {
			$added_pass = $guest;
		}
	}
}

get_header();
?>
<main class="extrachill-content ec-guest-list-page">
	<div class="content-column">
	<div class="ec-guest-list ec-surface-card ec-card-padding ec-narrow-column" data-event-id="<?php echo esc_attr( (string) $event_id ); ?>">
		<h1 class="ec-guest-list__heading">
		<?php
		echo esc_html(
			$revoked
				? __( 'Guest list invite', 'extrachill-events' )
				/* translators: %s: band or management name. */
				: sprintf( __( 'Guest list for %s', 'extrachill-events' ), (string) $party['name'] )
		);
		?>
		</h1>
		<p class="ec-guest-list__event">
			<a href="<?php echo esc_url( (string) get_permalink( $event_id ) ); ?>"><?php echo esc_html( $event ? get_the_title( $event ) : '' ); ?></a>
		</p>

		<?php if ( $revoked ) : ?>
			<p class="notice notice-info"><?php echo esc_html( $party->get_error_message() ); ?></p>
		<?php else : ?>
			<?php if ( $added_pass ) : ?>
				<div class="notice notice-success" data-guest-list-share>
					<p>
					<?php
					/* translators: %s: guest name. */
					printf( esc_html__( '%s is on the list. Text them this link so they have their pass at the door:', 'extrachill-events' ), esc_html( (string) $added_pass['guest_name'] ) );
					?>
					</p>
					<label><?php esc_html_e( 'Pass link', 'extrachill-events' ); ?>
						<input type="url" readonly value="<?php echo esc_attr( extrachill_events_guest_list_pass_link( $event_id, (string) $added_pass['code'], (string) $added_pass['guest_name'] ) ); ?>">
					</label>
					<button type="button" class="button-2 button-small" data-copy-guest-list><?php esc_html_e( 'Copy pass link', 'extrachill-events' ); ?></button>
					<p role="status" aria-live="polite" data-copy-status></p>
				</div>
			<?php endif; ?>

			<p><?php echo esc_html( $spots_line ); ?></p>

			<?php if ( $is_past ) : ?>
				<p class="notice notice-info"><?php esc_html_e( 'This event has already happened.', 'extrachill-events' ); ?></p>
			<?php elseif ( $no_room ) : ?>
				<p class="notice notice-info"><?php esc_html_e( "You've used all your guest list spots. Ask the venue for more.", 'extrachill-events' ); ?></p>
			<?php else : ?>
				<form class="ec-form-stack" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="ec_guest_party_add">
					<input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>">
					<?php wp_nonce_field( 'ec_guest_party_' . (int) $party['id'], '_wpnonce', false ); ?>
					<label><?php esc_html_e( 'Guest name', 'extrachill-events' ); ?>
						<input type="text" name="name" required maxlength="100" autocomplete="off">
					</label>
					<label><?php esc_html_e( 'Email (optional, sends them the pass)', 'extrachill-events' ); ?>
						<input type="email" name="email" maxlength="200" autocomplete="off">
					</label>
					<button type="submit" class="button-1 button-medium"><?php esc_html_e( 'Add to guest list', 'extrachill-events' ); ?></button>
				</form>
			<?php endif; ?>

			<h2 id="ec-guest-invite-names"><?php esc_html_e( 'Your names', 'extrachill-events' ); ?></h2>
			<?php if ( empty( $guests ) ) : ?>
				<p><?php esc_html_e( 'No one yet.', 'extrachill-events' ); ?></p>
			<?php else : ?>
				<ul>
					<?php foreach ( $guests as $guest ) : ?>
						<li>
							<?php echo esc_html( (string) $guest['guest_name'] ); ?>
							<?php if ( 'redeemed' === $guest['status'] ) : ?>
								— <?php esc_html_e( 'checked in', 'extrachill-events' ); ?>
							<?php else : ?>
								— <a href="<?php echo esc_url( extrachill_events_guest_list_pass_link( $event_id, (string) $guest['code'], (string) $guest['guest_name'] ) ); ?>" rel="noreferrer"><?php esc_html_e( 'Pass link', 'extrachill-events' ); ?></a>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-confirm="<?php echo esc_attr( sprintf( /* translators: %s: guest name. */ __( 'Take %s off the guest list?', 'extrachill-events' ), (string) $guest['guest_name'] ) ); ?>">
									<input type="hidden" name="action" value="ec_guest_party_remove">
									<input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>">
									<input type="hidden" name="code" value="<?php echo esc_attr( (string) $guest['code'] ); ?>">
									<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'ec_guest_party_' . (int) $party['id'] ) ); ?>">
									<button type="submit" class="button-danger button-small"><?php esc_html_e( 'Remove', 'extrachill-events' ); ?></button>
								</form>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<p><?php esc_html_e( 'Keep this page private: anyone with the link can add names.', 'extrachill-events' ); ?></p>
		<?php endif; ?>
	</div>
	</div>
</main>
<?php
get_footer();
