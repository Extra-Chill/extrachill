<?php
/**
 * Guest-list management, shared by the event page and perk meta box.
 *
 * @package ExtraChillEvents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<section id="ec-guest-list-management" class="ec-surface-card ec-card-padding">
	<h3><?php esc_html_e( 'Manage guest list', 'extrachill-events' ); ?></h3>
	<?php if ( $settings ) : ?>
	<form class="ec-form-stack" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="ec_guest_list_settings">
		<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $event_id ); ?>">
		<?php wp_nonce_field( 'ec_guest_list_settings_' . $event_id ); ?>
		<label><?php esc_html_e( 'Guest list password (leave blank to keep)', 'extrachill-events' ); ?>
			<input type="password" name="password" autocomplete="new-password" maxlength="128">
		</label>
		<label><?php esc_html_e( 'Guest list spots (0 = unlimited)', 'extrachill-events' ); ?>
			<input type="number" name="capacity" min="0" max="100000" value="<?php echo esc_attr( (string) $counts['capacity'] ); ?>">
		</label>
		<?php
		$pass_image_source = extrachill_events_get_pass_image_source( $event_id );
		$pass_image_url    = extrachill_events_get_pass_image_url( $event_id, 'medium' );
		?>
		<fieldset>
			<legend><?php esc_html_e( 'Pass image', 'extrachill-events' ); ?></legend>
			<?php if ( '' !== $pass_image_url ) : ?>
				<img class="ec-guest-list-management__art" src="<?php echo esc_url( $pass_image_url ); ?>" alt="<?php echo esc_attr( extrachill_events_get_pass_image_alt( $event_id ) ); ?>" loading="lazy">
			<?php endif; ?>
			<p>
			<?php
			if ( 'custom' === $pass_image_source ) {
				esc_html_e( 'Passes show your custom image.', 'extrachill-events' );
			} elseif ( 'poster' === $pass_image_source ) {
				esc_html_e( 'Passes show the event poster. Upload an image to use your own instead.', 'extrachill-events' );
			} else {
				esc_html_e( 'No pass image yet. Upload one, or add an event poster and passes will use it.', 'extrachill-events' );
			}
			?>
			</p>
			<label><?php echo esc_html( 'custom' === $pass_image_source ? __( 'Replace pass image', 'extrachill-events' ) : __( 'Upload pass image (optional)', 'extrachill-events' ) ); ?>
				<input type="file" name="pass_image" accept="image/jpeg,image/png,image/gif,image/webp">
			</label>
			<?php if ( 'custom' === $pass_image_source ) : ?>
				<label>
					<input type="checkbox" name="remove_pass_image" value="1">
					<?php esc_html_e( 'Remove custom image (use the event poster)', 'extrachill-events' ); ?>
				</label>
			<?php endif; ?>
		</fieldset>
		<button type="submit" class="button-1 button-small"><?php esc_html_e( 'Save and enable guest list', 'extrachill-events' ); ?></button>
	</form>
	<?php endif; ?>
	<p>
	<?php
	printf(
		/* translators: 1: claimed passes, 2: checked-in passes, 3: spots left or Unlimited. */
		esc_html__( 'Claimed: %1$d. Checked in: %2$d. Spots left: %3$s.', 'extrachill-events' ),
		(int) $counts['claimed'],
		(int) $counts['checked_in'],
		esc_html( null === $counts['spots_left'] ? __( 'Unlimited', 'extrachill-events' ) : (string) $counts['spots_left'] )
	);
	?>
	</p>
	<?php
	if ( extrachill_events_has_guest_list_page( $event_id ) ) :
		$share_url = extrachill_events_guest_list_url( $event_id );
		$qr_url    = add_query_arg(
			array(
				'action'   => 'ec_guest_list_qr',
				'event_id' => $event_id,
			),
			admin_url( 'admin-post.php' )
		);
		?>
		<div data-guest-list-share>
			<label><?php esc_html_e( 'Share link', 'extrachill-events' ); ?>
				<input type="url" readonly value="<?php echo esc_attr( $share_url ); ?>">
			</label>
			<button type="button" class="button-2 button-small" data-copy-guest-list><?php esc_html_e( 'Copy share link', 'extrachill-events' ); ?></button>
			<p role="status" aria-live="polite" data-copy-status></p>
			<p><a href="<?php echo esc_url( $share_url ); ?>"><?php esc_html_e( 'Open guest list share page', 'extrachill-events' ); ?></a></p>
			<img src="<?php echo esc_url( $qr_url ); ?>" width="250" height="250" alt="<?php esc_attr_e( 'Guest list share QR code', 'extrachill-events' ); ?>">
			<p><a class="button-2 button-small" href="<?php echo esc_url( add_query_arg( 'download', '1', $qr_url ) ); ?>"><?php esc_html_e( 'Download share QR', 'extrachill-events' ); ?></a></p>
		</div>
	<?php else : ?>
		<p><?php esc_html_e( 'Enable the perk and set a password, then save to activate the share page and QR.', 'extrachill-events' ); ?></p>
	<?php endif; ?>
	<p><a class="button-2 button-small" href="<?php echo esc_url( get_permalink( $event_id ) . '#ec-door-list' ); ?>"><?php esc_html_e( 'Open door list', 'extrachill-events' ); ?></a></p>
	<p><?php esc_html_e( 'Door staff: log in on your phone before doors. Scan the attendee’s pass to open /rsvp-verify/, then tap Check in. You can also check in manually from the door list.', 'extrachill-events' ); ?></p>
</section>
