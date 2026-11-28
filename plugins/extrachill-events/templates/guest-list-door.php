<?php
/**
 * Host screen on /guest-list (extrachill#1246, #1702).
 *
 * Rendered by extrachill_events_render_guest_list_door() for people who
 * manage the event, and nothing else. Check-in comes first: the show
 * title, then the A to Z list with a find-a-name box and big green Check
 * in buttons. Checked-in names show a ✅ and a green "Checked in" state.
 * Below the list: paste/upload names, the pass image, and the host tools
 * (guest list settings, counts, share link and QR, band invites and
 * requests, owner's lists) in a collapsible section. No directions, map,
 * related events, or other listing chrome.
 *
 * Check in: assets/js/rsvp-pass.js binds `.ec-door-list__redeem` and posts
 * to extrachill/redeem-event-pass. Find a name, Remove confirms, and copy
 * buttons: assets/js/guest-list-management.js.
 *
 * Ships no CSS of its own: theme classes, allow-listed in
 * RsvpPassDesignSystemTest.php.
 *
 * @package ExtraChillEvents
 *
 * @var int        $event_id        Event post ID.
 * @var string     $event_title     Show title.
 * @var string     $pass_image_url  The host's pass image, or ''.
 * @var string     $pass_image_alt  Its alt text.
 * @var array[]    $door_rows       extrachill_events_guest_list_door_rows() result.
 * @var array[]    $going_rows      extrachill_events_guest_list_going_rows() result.
 * @var bool       $has_guest_list  Whether the event has a guest list page for guests (perk on or tickets).
 * @var string     $host_tools_html extrachill_events_guest_list_host_tools_html() result.
 * @var bool       $host_tools_open Whether the host tools section starts open.
 * @var int[]|null $added_counts    [ added, already listed, not added ] after an add, else null.
 * @var string     $buy_tickets_url   Event ticket page while the sale is open, else ''.
 * @var string     $buy_tickets_qr    Buy tickets QR image URL, '' while the sale is closed.
 * @var array|null $ticket_report     Sales report (extrachill/get-show-ticket-report), or null.
 * @var string     $door_list_csv_url Nonced door list CSV download, or ''.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div id="ec-guest-list-door" class="ec-guest-list-door ec-door-list ec-surface-card ec-card-padding ec-narrow-column" data-event-id="<?php echo esc_attr( (string) $event_id ); ?>">
	<h1 class="ec-guest-list__heading"><?php echo esc_html( $event_title ); ?></h1>

	<?php if ( null !== $added_counts ) : ?>
		<p class="notice notice-success" role="status">
			<?php
			printf(
				/* translators: 1: names added, 2: names already on the list, 3: names not added because the list is full. */
				esc_html__( 'Added %1$d. Already on the list: %2$d. Not added (list full): %3$d.', 'extrachill-events' ),
				(int) $added_counts[0],
				(int) $added_counts[1],
				(int) $added_counts[2]
			);
			?>
		</p>
	<?php endif; ?>

	<?php if ( empty( $door_rows ) ) : ?>
		<p class="notice notice-info">
			<?php
			if ( $has_guest_list ) {
				esc_html_e( 'No one is on the list yet.', 'extrachill-events' );
			} else {
				esc_html_e( 'The guest list is off for this show. Turn it on under Host tools below.', 'extrachill-events' );
			}
			?>
		</p>
	<?php else : ?>
		<?php if ( count( $door_rows ) > 1 ) : ?>
			<label><?php esc_html_e( 'Find a name', 'extrachill-events' ); ?>
				<input type="search" autocomplete="off" data-door-list-filter data-no-match="<?php esc_attr_e( 'No names match.', 'extrachill-events' ); ?>" aria-controls="ec-guest-list-door-rows">
			</label>
			<p role="status" aria-live="polite" data-door-list-filter-status></p>
		<?php endif; ?>
		<ul id="ec-guest-list-door-rows" class="ec-door-list__rows">
			<?php foreach ( $door_rows as $door_row ) : ?>
				<?php $is_attendee = 'attendee' === $door_row['kind']; ?>
				<li class="ec-door-list__row" data-door-group="<?php echo esc_attr( (string) ( $door_row['door_group'] ?? '' ) ); ?>">
					<span class="ec-door-list__name">
						<?php if ( $door_row['checked_in'] ) : ?>
							<span class="ec-door-list__check" aria-hidden="true">✅</span>
						<?php endif; ?>
						<?php echo esc_html( (string) $door_row['name'] ); ?>
					</span>
					<?php if ( '' !== (string) ( $door_row['added_by'] ?? '' ) ) : ?>
						<span class="ec-door-list__status">
							<?php
							/* translators: %s: name of the host who added the guest. */
							printf( esc_html__( 'Added by %s', 'extrachill-events' ), esc_html( (string) $door_row['added_by'] ) );
							?>
						</span>
					<?php endif; ?>
					<?php if ( $door_row['checked_in'] ) : ?>
						<button type="button" class="button-success button-large ec-door-list__checked-in" disabled><?php esc_html_e( 'Checked in', 'extrachill-events' ); ?></button>
					<?php elseif ( $is_attendee ) : ?>
						<button type="button" class="button-success button-large ec-door-list__redeem" data-user-id="<?php echo esc_attr( (string) $door_row['user_id'] ); ?>"><?php esc_html_e( 'Check in', 'extrachill-events' ); ?></button>
					<?php else : ?>
						<button type="button" class="button-success button-large ec-door-list__redeem" data-pass-code="<?php echo esc_attr( (string) $door_row['code'] ); ?>"><?php esc_html_e( 'Check in', 'extrachill-events' ); ?></button>
						<?php if ( empty( $door_row['owner_list'] ) ) : ?>
							<?php $guest_name = (string) ( $door_row['guest_name'] ?? '' ); ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-confirm="<?php echo esc_attr( sprintf( /* translators: %s: guest name. */ __( 'Take %s off the guest list?', 'extrachill-events' ), '' !== $guest_name ? $guest_name : __( 'this guest', 'extrachill-events' ) ) ); ?>">
								<input type="hidden" name="action" value="ec_guest_list_remove">
								<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $event_id ); ?>">
								<input type="hidden" name="code" value="<?php echo esc_attr( (string) $door_row['code'] ); ?>">
								<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'ec_guest_list_remove_' . $event_id ) ); ?>">
								<button type="submit" class="button-danger button-small"><?php esc_html_e( 'Remove', 'extrachill-events' ); ?></button>
							</form>
						<?php endif; ?>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<?php if ( '' !== $buy_tickets_qr ) : ?>
		<section id="ec-door-buy-tickets" class="ec-surface-card ec-card-padding">
			<h2><?php esc_html_e( 'Buy tickets', 'extrachill-events' ); ?></h2>
			<p><?php esc_html_e( 'Walk-ups can scan this to buy on their phone. Sales stay open through the show.', 'extrachill-events' ); ?></p>
			<img src="<?php echo esc_url( $buy_tickets_qr ); ?>" width="250" height="250" alt="<?php esc_attr_e( 'Buy tickets QR code', 'extrachill-events' ); ?>">
			<p><a href="<?php echo esc_url( $buy_tickets_url ); ?>"><?php esc_html_e( 'Open the ticket page', 'extrachill-events' ); ?></a></p>
			<p><a class="button-2 button-small" href="<?php echo esc_url( add_query_arg( 'download', '1', $buy_tickets_qr ) ); ?>"><?php esc_html_e( 'Download Buy tickets QR', 'extrachill-events' ); ?></a></p>
		</section>
	<?php endif; ?>

	<?php if ( $has_guest_list ) : ?>
		<form class="ec-form-stack" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="ec_guest_list_bulk_add">
			<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $event_id ); ?>">
			<?php wp_nonce_field( 'ec_guest_list_bulk_add_' . $event_id, '_wpnonce', false ); ?>
			<label for="ec-guest-list-door-names"><?php esc_html_e( 'Paste names, one per line', 'extrachill-events' ); ?></label>
			<textarea id="ec-guest-list-door-names" name="names" rows="5" autocomplete="off"></textarea>
			<label for="ec-guest-list-door-file"><?php esc_html_e( 'Or upload a CSV or TXT file, one name per line', 'extrachill-events' ); ?></label>
			<input type="file" id="ec-guest-list-door-file" name="names_file" accept=".csv,.txt,text/csv,text/plain">
			<button type="submit" class="button-1 button-medium"><?php esc_html_e( 'Add names', 'extrachill-events' ); ?></button>
		</form>
	<?php endif; ?>

	<?php if ( '' !== $pass_image_url ) : ?>
		<img class="ec-rsvp-pass__art" src="<?php echo esc_url( $pass_image_url ); ?>" alt="<?php echo esc_attr( $pass_image_alt ); ?>" decoding="async">
	<?php endif; ?>
</div>

<details id="ec-host-tools" class="ec-narrow-column" <?php echo $host_tools_open ? 'open' : ''; ?>>
	<summary><?php esc_html_e( 'Host tools: sales report, door list download, guest list settings, share link and QR, owner\'s lists', 'extrachill-events' ); ?></summary>
	<?php if ( $going_rows ) : ?>
		<section class="ec-surface-card ec-card-padding">
			<h3><?php esc_html_e( 'Going, no pass', 'extrachill-events' ); ?></h3>
			<p><?php esc_html_e( 'Marked Going on the event page. They have no pass to check in.', 'extrachill-events' ); ?></p>
			<ul>
				<?php foreach ( $going_rows as $going_row ) : ?>
					<li>
						<?php echo esc_html( (string) $going_row['display_name'] ); ?>
						<?php if ( '' !== (string) ( $going_row['marked_at'] ?? '' ) ) : ?>
							(<?php echo esc_html( (string) $going_row['marked_at'] ); ?>)
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
	<?php endif; ?>
	<?php
	if ( '' !== $door_list_csv_url ) {
		include EXTRACHILL_EVENTS_PLUGIN_DIR . 'templates/show-ticket-report.php';
	}
	?>
	<?php echo $host_tools_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the guest list and owner's list templates. ?>
</details>
