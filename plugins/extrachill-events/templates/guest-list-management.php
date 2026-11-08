<?php
/**
 * Guest-list management, shared by the event page and perk meta box.
 *
 * @package ExtraChillEvents
 *
 * @var int        $event_id           Event post ID.
 * @var array|null $settings           Guest list settings.
 * @var array      $counts             Claimed and checked-in counts.
 * @var bool       $allotments_enabled Whether party allotments are on.
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
		<label>
			<input type="checkbox" name="allotments_enabled" value="1" <?php checked( $allotments_enabled ); ?>>
			<?php esc_html_e( 'Give each invited band a set number of spots (allotments). Off: bands can add names until the list is full.', 'extrachill-events' ); ?>
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
				esc_html_e( 'No pass image yet, so passes show an event card with the title and time. Upload an image, or add an event poster and passes will use it.', 'extrachill-events' );
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
		<?php
		$ticket_settings = \ExtraChillEvents\Abilities\TicketAbilities::settings( $event_id );
		$seller_resolver = new \ExtraChillEvents\Abilities\CommerceSellerAbilities( null, null, false );
		?>
		<fieldset>
			<legend><?php esc_html_e( 'Paid tickets (tax included; seller responsible for tax)', 'extrachill-events' ); ?></legend>
			<label><input type="checkbox" name="tickets_enabled" value="1" <?php checked( $ticket_settings['tickets_enabled'] ); ?>> <?php esc_html_e( 'Enable tickets', 'extrachill-events' ); ?></label>
			<label><?php esc_html_e( 'Price per ticket ($, tax included)', 'extrachill-events' ); ?> <input type="number" name="ticket_price" min="0" step="0.01" inputmode="decimal" value="<?php echo esc_attr( $ticket_settings['ticket_price_minor'] ? number_format( $ticket_settings['ticket_price_minor'] / 100, 2, '.', '' ) : '' ); ?>"></label>
			<label><?php esc_html_e( 'Quantity (0 uses guest list capacity)', 'extrachill-events' ); ?> <input type="number" name="ticket_quantity" min="0" step="1" value="<?php echo esc_attr( $ticket_settings['ticket_quantity'] ); ?>"></label>
			<label><?php esc_html_e( 'Seller', 'extrachill-events' ); ?>
			<select name="ticket_seller">
				<option value=""><?php esc_html_e( 'Choose a seller', 'extrachill-events' ); ?></option>
				<?php foreach ( array( 'venue', 'promoter' ) as $seller_type ) : ?>
					<?php
					$seller_terms = get_terms( array(
						'taxonomy'   => $seller_type,
						'hide_empty' => false,
					) );
					?>
					<?php foreach ( is_wp_error( $seller_terms ) ? array() : $seller_terms as $seller_term ) : ?>
						<?php
						$seller = $seller_resolver->execute( array(
							'type' => $seller_type,
							'id'   => (int) $seller_term->term_id,
						) );
						?>
						<?php if ( ! is_wp_error( $seller ) && $seller['can_manage_finances'] ) : ?>
							<option value="<?php echo esc_attr( $seller_type . ':' . $seller['id'] ); ?>" <?php selected( $ticket_settings['ticket_seller']['type'] . ':' . $ticket_settings['ticket_seller']['id'], $seller_type . ':' . $seller['id'] ); ?>><?php echo esc_html( $seller['name'] ); ?></option>
						<?php endif; ?>
					<?php endforeach; ?>
				<?php endforeach; ?>
			</select></label>
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
	<?php if ( $settings && extrachill_events_has_guest_list_page( $event_id ) ) : ?>
		<?php
		// Front end only: the wp-admin meta box renders this panel inside the post form, where a nested form would break saving.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only confirmation after the add redirect; the code is checked against this event below.
		$added_code = isset( $_GET['ec_guest_added'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_GET['ec_guest_added'] ) ) ) : '';
		$added_pass = ( '' !== $added_code && class_exists( '\\ExtraChillEvents\\Core\\RsvpPassesTable' ) ) ? \ExtraChillEvents\Core\RsvpPassesTable::find_by_code( $added_code ) : null;
		if ( $added_pass && (int) $added_pass['event_id'] === $event_id && empty( $added_pass['user_id'] ) ) :
			$added_name = (string) ( $added_pass['guest_name'] ?? '' );
			?>
			<div class="notice notice-success" data-guest-list-share>
				<p>
				<?php
				/* translators: %s: guest name. */
				printf( esc_html__( '%s is on the list. Text them this link so they have their pass at the door:', 'extrachill-events' ), esc_html( $added_name ) );
				?>
				</p>
				<label><?php esc_html_e( 'Pass link', 'extrachill-events' ); ?>
					<input type="url" readonly value="<?php echo esc_attr( extrachill_events_guest_list_pass_link( $event_id, $added_code, $added_name ) ); ?>">
				</label>
				<button type="button" class="button-2 button-small" data-copy-guest-list><?php esc_html_e( 'Copy pass link', 'extrachill-events' ); ?></button>
				<p role="status" aria-live="polite" data-copy-status></p>
			</div>
		<?php endif; ?>
		<form class="ec-form-stack" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<h4><?php esc_html_e( 'Add a guest', 'extrachill-events' ); ?></h4>
			<p><?php esc_html_e( 'Comps and guest list spots: add a name and they get a pass that scans at the door. No password needed. If they are not on the list, they are not on the list.', 'extrachill-events' ); ?></p>
			<input type="hidden" name="action" value="ec_guest_list_add">
			<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $event_id ); ?>">
			<?php wp_nonce_field( 'ec_guest_list_add_' . $event_id, '_wpnonce', false ); ?>
			<label><?php esc_html_e( 'Guest name', 'extrachill-events' ); ?>
				<input type="text" name="name" required maxlength="100" autocomplete="off">
			</label>
			<label><?php esc_html_e( 'Email (optional, sends them the pass)', 'extrachill-events' ); ?>
				<input type="email" name="email" maxlength="200" autocomplete="off">
			</label>
			<button type="submit" class="button-1 button-small"><?php esc_html_e( 'Add to guest list', 'extrachill-events' ); ?></button>
		</form>
		<div id="ec-eventbrite-import">
			<?php
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only counts after the import redirect.
			$eventbrite_summary = isset( $_GET['ec_eventbrite_imported'] ) ? sanitize_text_field( wp_unslash( $_GET['ec_eventbrite_imported'] ) ) : '';
			if ( preg_match( '/^(\d+)\.(\d+)\.(\d+)$/', $eventbrite_summary, $eventbrite_counts ) ) :
				?>
				<div class="notice notice-success" role="status">
					<p>
					<?php
					printf(
						/* translators: 1: names added, 2: names already on the list, 3: rows skipped. */
						esc_html__( 'Eventbrite import: %1$d added to the door list, %2$d already there, %3$d skipped (refunded, cancelled, or no name).', 'extrachill-events' ),
						(int) $eventbrite_counts[1],
						(int) $eventbrite_counts[2],
						(int) $eventbrite_counts[3]
					);
					?>
					</p>
				</div>
			<?php endif; ?>
			<form class="ec-form-stack" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<h4><?php esc_html_e( 'Bring your Eventbrite list', 'extrachill-events' ); ?></h4>
				<p><?php esc_html_e( 'Selling this show on Eventbrite too? In Eventbrite, open the event, go to Reports, then Attendee summary, and export it as CSV. Upload it here and those buyers go on the door list by name, marked Eventbrite, so the door checks everyone in from one list.', 'extrachill-events' ); ?></p>
				<p><?php esc_html_e( 'Nobody gets emailed and no email addresses are kept. Upload a newer export any time; only new tickets are added.', 'extrachill-events' ); ?></p>
				<input type="hidden" name="action" value="ec_eventbrite_import">
				<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $event_id ); ?>">
				<?php wp_nonce_field( 'ec_eventbrite_import_' . $event_id, '_wpnonce', false ); ?>
				<label><?php esc_html_e( 'Eventbrite attendee CSV', 'extrachill-events' ); ?>
					<input type="file" name="eventbrite_csv" accept=".csv,text/csv" required>
				</label>
				<button type="submit" class="button-2 button-small"><?php esc_html_e( 'Import to door list', 'extrachill-events' ); ?></button>
			</form>
		</div>
		<?php if ( ! empty( $requests ) ) : ?>
			<div id="ec-guest-list-requests">
				<h4><?php esc_html_e( 'Pending requests', 'extrachill-events' ); ?></h4>
				<p><?php esc_html_e( 'Nothing here works at the door until you accept it.', 'extrachill-events' ); ?></p>
				<ul>
					<?php foreach ( $requests as $request ) : ?>
						<?php
						$request_kind  = (string) $request['kind'];
						$request_names = (array) $request['names'];
						$request_spots = null !== $request['requested_spots'] ? (int) $request['requested_spots'] : count( $request_names );
						?>
						<li>
							<strong><?php echo esc_html( (string) $request['name'] ); ?></strong>
							(<?php echo esc_html( (string) $request['email'] ); ?>):
							<?php
							if ( 'party' === $request_kind ) {
								/* translators: %d: spots asked for. */
								printf( esc_html( _n( 'not invited, asks for %d spot', 'not invited, asks for %d spots', $request_spots, 'extrachill-events' ) ), (int) $request_spots );
							} elseif ( 'spots' === $request_kind ) {
								printf(
									/* translators: 1: extra spots asked for, 2: spots used, 3: spots allotted. */
									esc_html( _n( 'asks for %1$d more spot (%2$d of %3$d used)', 'asks for %1$d more spots (%2$d of %3$d used)', $request_spots, 'extrachill-events' ) ),
									(int) $request_spots,
									(int) $request['used'],
									(int) $request['allotment']
								);
							} else {
								/* translators: %d: names submitted. */
								printf( esc_html( _n( 'sent %d name for approval', 'sent %d names for approval', count( $request_names ), 'extrachill-events' ) ), count( $request_names ) );
							}
							?>
							<?php if ( '' !== (string) $request['note'] ) : ?>
								<br><em><?php echo esc_html( (string) $request['note'] ); ?></em>
							<?php endif; ?>
							<?php if ( $request_names ) : ?>
								<br><?php echo esc_html( implode( ', ', $request_names ) ); ?>
							<?php endif; ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="ec_guest_request_approve">
								<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $event_id ); ?>">
								<input type="hidden" name="party_id" value="<?php echo esc_attr( (string) $request['party_id'] ); ?>">
								<input type="hidden" name="kind" value="<?php echo esc_attr( $request_kind ); ?>">
								<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'ec_guest_party_manage_' . $event_id ) ); ?>">
								<?php if ( 'spots' === $request_kind || ( 'party' === $request_kind && $allotments_enabled ) ) : ?>
									<label><?php echo esc_html( 'spots' === $request_kind ? __( 'Extra spots', 'extrachill-events' ) : __( 'Spots for this band', 'extrachill-events' ) ); ?>
										<input type="number" name="spots" min="<?php echo 'spots' === $request_kind ? '1' : '0'; ?>" max="100000" value="<?php echo esc_attr( (string) $request_spots ); ?>">
									</label>
								<?php endif; ?>
								<button type="submit" class="button-1 button-small"><?php esc_html_e( 'Accept', 'extrachill-events' ); ?></button>
							</form>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-confirm="<?php echo esc_attr( sprintf( /* translators: %s: band or management name. */ __( 'Deny this request from %s? They will be emailed.', 'extrachill-events' ), (string) $request['name'] ) ); ?>">
								<input type="hidden" name="action" value="ec_guest_request_deny">
								<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $event_id ); ?>">
								<input type="hidden" name="party_id" value="<?php echo esc_attr( (string) $request['party_id'] ); ?>">
								<input type="hidden" name="kind" value="<?php echo esc_attr( $request_kind ); ?>">
								<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'ec_guest_party_manage_' . $event_id ) ); ?>">
								<button type="submit" class="button-danger button-small"><?php esc_html_e( 'Deny', 'extrachill-events' ); ?></button>
							</form>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>
		<div id="ec-guest-list-parties">
			<h4><?php esc_html_e( 'Invite a band', 'extrachill-events' ); ?></h4>
			<p><?php esc_html_e( 'Send a band or their management a private link. They add their own guest names, no account or password, and the door list shows who added each guest.', 'extrachill-events' ); ?></p>
			<?php
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only confirmation after the invite redirect; the token is resolved against this event below.
			$invite_link  = isset( $_GET['ec_party_invite'] ) ? sanitize_text_field( wp_unslash( $_GET['ec_party_invite'] ) ) : '';
			$invite_token = preg_match( '#/' . EXTRACHILL_EVENTS_GUEST_INVITE_ENDPOINT . '/([a-f0-9]{64})/#', $invite_link, $invite_match ) ? $invite_match[1] : '';
			$invite_party = '' !== $invite_token ? extrachill_events_resolve_guest_list_party( $invite_token ) : null;
			if ( is_array( $invite_party ) && (int) $invite_party['event_id'] === $event_id ) :
				?>
				<div class="notice notice-success" data-guest-list-share>
					<p>
					<?php
					/* translators: %s: band or management name. */
					printf( esc_html__( 'Invite sent to %s. You can also text them their private link:', 'extrachill-events' ), esc_html( (string) $invite_party['name'] ) );
					?>
					</p>
					<label><?php esc_html_e( 'Invite link', 'extrachill-events' ); ?>
						<input type="url" readonly value="<?php echo esc_attr( extrachill_events_guest_list_party_invite_url( $event_id, $invite_token ) ); ?>">
					</label>
					<button type="button" class="button-2 button-small" data-copy-guest-list><?php esc_html_e( 'Copy invite link', 'extrachill-events' ); ?></button>
					<p role="status" aria-live="polite" data-copy-status></p>
				</div>
			<?php endif; ?>
			<form class="ec-form-stack" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="ec_guest_party_invite">
				<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $event_id ); ?>">
				<?php wp_nonce_field( 'ec_guest_party_invite_' . $event_id, '_wpnonce', false ); ?>
				<label><?php esc_html_e( 'Band or management name', 'extrachill-events' ); ?>
					<input type="text" name="name" required maxlength="100" autocomplete="off">
				</label>
				<label><?php esc_html_e( 'Email for the invite link', 'extrachill-events' ); ?>
					<input type="email" name="email" required maxlength="200" autocomplete="off">
				</label>
				<?php if ( $allotments_enabled ) : ?>
					<label><?php esc_html_e( 'Spots for this band', 'extrachill-events' ); ?>
						<input type="number" name="allotment" required min="0" max="100000" value="2">
					</label>
				<?php endif; ?>
				<button type="submit" class="button-1 button-small"><?php esc_html_e( 'Send invite', 'extrachill-events' ); ?></button>
			</form>
			<?php if ( ! empty( $parties ) ) : ?>
				<ul>
					<?php foreach ( $parties as $party ) : ?>
						<?php
						// Uninvited requests (extrachill#1129) show under Pending requests; denied ones never were parties.
						if ( in_array( $party['status'], array( 'pending', 'denied' ), true ) ) {
							continue;
						}
						$party_active = 'active' === $party['status'];
						?>
						<li>
							<strong><?php echo esc_html( (string) $party['name'] ); ?></strong>
							(<?php echo esc_html( (string) $party['email'] ); ?>):
							<?php
							if ( $allotments_enabled ) {
								printf(
									/* translators: 1: names added, 2: spots allotted. */
									esc_html__( '%1$d of %2$d spots used', 'extrachill-events' ),
									(int) $party['used'],
									(int) $party['allotment']
								);
							} else {
								printf(
									/* translators: %d: names added. */
									esc_html( _n( '%d name added', '%d names added', (int) $party['used'], 'extrachill-events' ) ),
									(int) $party['used']
								);
							}
							if ( ! $party_active ) {
								echo ' · ' . esc_html__( 'Invite revoked', 'extrachill-events' );
							}
							?>
							<?php if ( $party_active && $allotments_enabled ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="ec_guest_party_allotment">
									<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $event_id ); ?>">
									<input type="hidden" name="party_id" value="<?php echo esc_attr( (string) $party['party_id'] ); ?>">
									<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'ec_guest_party_manage_' . $event_id ) ); ?>">
									<label><?php esc_html_e( 'Spots', 'extrachill-events' ); ?>
										<input type="number" name="allotment" min="0" max="100000" value="<?php echo esc_attr( (string) (int) $party['allotment'] ); ?>">
									</label>
									<button type="submit" class="button-2 button-small"><?php esc_html_e( 'Save spots', 'extrachill-events' ); ?></button>
								</form>
							<?php endif; ?>
							<?php if ( $party_active ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-confirm="<?php echo esc_attr( sprintf( /* translators: %s: band or management name. */ __( 'Revoke the invite for %s? Their link stops working. Guests they already added stay on the list.', 'extrachill-events' ), (string) $party['name'] ) ); ?>">
									<input type="hidden" name="action" value="ec_guest_party_revoke">
									<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $event_id ); ?>">
									<input type="hidden" name="party_id" value="<?php echo esc_attr( (string) $party['party_id'] ); ?>">
									<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'ec_guest_party_manage_' . $event_id ) ); ?>">
									<button type="submit" class="button-danger button-small"><?php esc_html_e( 'Revoke', 'extrachill-events' ); ?></button>
								</form>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
	<?php endif; ?>
	<p><a class="button-2 button-small" href="<?php echo esc_url( get_permalink( $event_id ) . '#ec-door-list' ); ?>"><?php esc_html_e( 'Open door list', 'extrachill-events' ); ?></a></p>
	<p><?php esc_html_e( 'Door staff: log in on your phone before doors. Scan the attendee’s pass to open /rsvp-verify/, then tap Check in. You can also check in manually from the door list.', 'extrachill-events' ); ?></p>
</section>
