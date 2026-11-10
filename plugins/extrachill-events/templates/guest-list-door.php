<?php
/**
 * Host door view on /guest-list (extrachill#1246).
 *
 * Rendered by extrachill_events_render_guest_list_door() for people who
 * manage the event, and nothing else: the show title, the pass image, an
 * add-names form (paste, or a CSV/TXT upload, one name per line), and the
 * A to Z list with big green Check in buttons. Checked-in names show a ✅
 * and a green "Checked in" state.
 *
 * Check in reuses the event-page door list wiring: assets/js/rsvp-pass.js
 * binds `.ec-door-list__redeem` and posts to extrachill/redeem-event-pass.
 *
 * Ships no CSS of its own: theme classes, allow-listed in
 * RsvpPassDesignSystemTest.php.
 *
 * @package ExtraChillEvents
 *
 * @var int        $event_id       Event post ID.
 * @var string     $event_title    Show title.
 * @var string     $pass_image_url The host's pass image, or ''.
 * @var string     $pass_image_alt Its alt text.
 * @var array[]    $door_rows      extrachill_events_guest_list_door_rows() result.
 * @var int[]|null $added_counts   [ added, already listed, not added ] after an add, else null.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div id="ec-guest-list-door" class="ec-guest-list-door ec-door-list ec-surface-card ec-card-padding ec-narrow-column" data-event-id="<?php echo esc_attr( (string) $event_id ); ?>">
	<h1 class="ec-guest-list__heading"><?php echo esc_html( $event_title ); ?></h1>

	<?php if ( '' !== $pass_image_url ) : ?>
		<img class="ec-rsvp-pass__art" src="<?php echo esc_url( $pass_image_url ); ?>" alt="<?php echo esc_attr( $pass_image_alt ); ?>" decoding="async">
	<?php endif; ?>

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

	<?php if ( empty( $door_rows ) ) : ?>
		<p class="notice notice-info"><?php esc_html_e( 'No one is on the list yet.', 'extrachill-events' ); ?></p>
	<?php else : ?>
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
					<?php if ( $door_row['checked_in'] ) : ?>
						<button type="button" class="button-success button-large ec-door-list__checked-in" disabled><?php esc_html_e( 'Checked in', 'extrachill-events' ); ?></button>
					<?php elseif ( $is_attendee ) : ?>
						<button type="button" class="button-success button-large ec-door-list__redeem" data-user-id="<?php echo esc_attr( (string) $door_row['user_id'] ); ?>"><?php esc_html_e( 'Check in', 'extrachill-events' ); ?></button>
					<?php else : ?>
						<button type="button" class="button-success button-large ec-door-list__redeem" data-pass-code="<?php echo esc_attr( (string) $door_row['code'] ); ?>"><?php esc_html_e( 'Check in', 'extrachill-events' ); ?></button>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>
