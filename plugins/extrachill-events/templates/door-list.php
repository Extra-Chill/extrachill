<?php
/**
 * Event door list — host-facing fragment.
 *
 * Appended to the single event page for authorized hosts only (see
 * extrachill_events_user_can_manage_event() in
 * inc/core/event-management-authority.php). Shows every attendee, including
 * private ones — the extrachill-users#414/#415 privacy resolution: private
 * stays private to the public, but the host of an event they run may see
 * who is coming.
 *
 * Available on ANY event the host manages, perk or not (extrachill-events#879)
 * — a guest list is useful for planning/capacity/outreach independent of
 * whether the event happens to offer an RSVP perk. The pass/redeem column
 * is perk-specific and gated on $perk_enabled below; without a perk each
 * row falls back to showing when the attendee marked Going.
 *
 * Ships no CSS of its own; see rsvp-pass.php's docblock for the same
 * design-system contract.
 *
 * @var int    $event_id     Event post ID.
 * @var bool   $perk_enabled Whether this event has an RSVP perk enabled.
 * @var string $perk_text    Attendee-facing perk description (perk events only).
 * @var array  $guest_list   extrachill_events_get_guest_list_counts() result (perk events only).
 * @var array  $guest_rows   Guest list passes with no account (extrachill_events_door_list_guest_rows()): label ("Jake (Slim S.O.U.L.)" when an invited band added them), guest_name, source (party name or ''), code, status, redeemed_at (formatted), added_by (host display name, or '' when the guest claimed it with the password or a band added it).
 *                            Also the venue's and promoter's standing owner's-list people (extrachill_events_owner_list_door_rows(), extrachill#1130): label "Name (Venue list)", owner_list true, status 'redeemed' once checked in at this event; never removable from here (that is the owner's list panel).
 * @var string $guest_list_url Guest list page URL when the event has a password-gated list, else ''.
 * @var array  $door_rows    $rows and $guest_rows merged with a `kind` ('attendee'|'guest') and sorted A to Z by extrachill_events_sort_guest_rows() (extrachill#1142); this is what renders.
 * @var array  $rows         Each: user_id, display_name, marked_at (formatted), pass_status ('active'|'redeemed'|'revoked'|''), redeemed_at (formatted, or '').
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div id="ec-door-list" class="ec-door-list ec-surface-card ec-card-padding" data-event-id="<?php echo esc_attr( (string) $event_id ); ?>">
	<h3 class="ec-door-list__heading"><?php esc_html_e( 'Door List', 'extrachill-events' ); ?></h3>
	<p class="ec-door-list__note"><?php esc_html_e( 'Visible only to you as this event\'s host. Not shown to attendees or the public.', 'extrachill-events' ); ?></p>
	<?php if ( $perk_enabled && $perk_text ) : ?>
		<p class="ec-door-list__perk"><?php echo esc_html( $perk_text ); ?></p>
	<?php endif; ?>
	<?php if ( $perk_enabled && ! empty( $guest_list ) ) : ?>
		<p class="ec-door-list__count"><?php echo esc_html( extrachill_events_format_guest_list_count( $guest_list ) ); ?></p>
	<?php endif; ?>
	<?php if ( ! empty( $guest_list_url ) ) : ?>
		<p class="ec-door-list__note">
			<?php esc_html_e( 'Guests get their pass from the guest list page. Scan a pass to check someone in.', 'extrachill-events' ); ?>
			<a href="<?php echo esc_url( $guest_list_url ); ?>"><?php esc_html_e( 'Guest list page', 'extrachill-events' ); ?></a>
		</p>
	<?php endif; ?>
	<?php if ( empty( $rows ) && empty( $guest_list_url ) && empty( $guest_rows ) ) : ?>
		<p class="notice notice-info"><?php esc_html_e( 'No one has RSVPed yet.', 'extrachill-events' ); ?></p>
	<?php else : ?>
		<?php if ( count( $door_rows ) > 1 ) : ?>
			<label><?php esc_html_e( 'Find a name', 'extrachill-events' ); ?>
				<input type="search" autocomplete="off" data-door-list-filter data-no-match="<?php esc_attr_e( 'No names match.', 'extrachill-events' ); ?>" aria-controls="ec-door-list-rows">
			</label>
			<p role="status" aria-live="polite" data-door-list-filter-status></p>
		<?php endif; ?>
		<ul id="ec-door-list-rows" class="ec-door-list__rows">
			<?php foreach ( $door_rows as $door_row ) : ?>
				<?php if ( 'attendee' === $door_row['kind'] ) : ?>
					<?php $row = $door_row; ?>
					<?php $pass_status = (string) ( $row['pass_status'] ?? '' ); ?>
					<li class="ec-door-list__row" data-door-group="<?php echo esc_attr( (string) ( $row['door_group'] ?? '' ) ); ?>" data-user-id="<?php echo esc_attr( (string) $row['user_id'] ); ?>">
						<span class="ec-door-list__name"><?php echo esc_html( $row['display_name'] ); ?></span>
						<?php if ( ! $perk_enabled ) : ?>
							<span class="ec-door-list__status"><?php echo esc_html( (string) $row['marked_at'] ); ?></span>
						<?php elseif ( 'redeemed' === $pass_status ) : ?>
							<span class="ec-door-list__status">
								<?php
								/* translators: %s: redemption time. */
								printf( esc_html__( 'Redeemed %s', 'extrachill-events' ), esc_html( (string) $row['redeemed_at'] ) );
								?>
							</span>
						<?php elseif ( 'revoked' === $pass_status ) : ?>
							<span class="ec-door-list__status"><?php esc_html_e( 'RSVP cancelled', 'extrachill-events' ); ?></span>
						<?php elseif ( 'active' === $pass_status ) : ?>
							<button
								type="button"
								class="button-2 button-small ec-door-list__redeem"
								data-user-id="<?php echo esc_attr( (string) $row['user_id'] ); ?>"
							>
								<?php esc_html_e( 'Redeem', 'extrachill-events' ); ?>
							</button>
						<?php else : ?>
							<span class="ec-door-list__status"><?php esc_html_e( 'No pass', 'extrachill-events' ); ?></span>
						<?php endif; ?>
					</li>
				<?php else : ?>
					<?php $guest_row = $door_row; ?>
					<li class="ec-door-list__row" data-door-group="<?php echo esc_attr( (string) ( $guest_row['door_group'] ?? '' ) ); ?>">
						<span class="ec-door-list__name"><?php echo esc_html( (string) $guest_row['label'] ); ?></span>
						<?php if ( '' !== (string) ( $guest_row['added_by'] ?? '' ) ) : ?>
							<span class="ec-door-list__status">
								<?php
								/* translators: %s: name of the host who added the guest. */
								printf( esc_html__( 'Added by %s', 'extrachill-events' ), esc_html( (string) $guest_row['added_by'] ) );
								?>
							</span>
						<?php endif; ?>
						<?php if ( 'redeemed' === $guest_row['status'] ) : ?>
							<span class="ec-door-list__status">
								<?php
								/* translators: %s: check-in time. */
								printf( esc_html__( 'Checked in %s', 'extrachill-events' ), esc_html( (string) $guest_row['redeemed_at'] ) );
								?>
							</span>
						<?php elseif ( 'active' === $guest_row['status'] ) : ?>
							<button
								type="button"
								class="button-2 button-small ec-door-list__redeem"
								data-pass-code="<?php echo esc_attr( (string) $guest_row['code'] ); ?>"
							>
								<?php esc_html_e( 'Check in', 'extrachill-events' ); ?>
							</button>
							<?php if ( empty( $guest_row['owner_list'] ) ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-confirm="<?php echo esc_attr( sprintf( /* translators: %s: guest name. */ __( 'Take %s off the guest list?', 'extrachill-events' ), '' !== (string) $guest_row['guest_name'] ? (string) $guest_row['guest_name'] : __( 'this guest', 'extrachill-events' ) ) ); ?>">
								<input type="hidden" name="action" value="ec_guest_list_remove">
								<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $event_id ); ?>">
								<input type="hidden" name="code" value="<?php echo esc_attr( (string) $guest_row['code'] ); ?>">
								<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'ec_guest_list_remove_' . $event_id ) ); ?>">
								<button type="submit" class="button-danger button-small"><?php esc_html_e( 'Remove', 'extrachill-events' ); ?></button>
							</form>
							<?php endif; ?>
						<?php endif; ?>
					</li>
				<?php endif; ?>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>
