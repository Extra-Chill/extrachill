<?php
/**
 * RSVP perk door list — host-facing fragment.
 *
 * Appended to the single event page for authorized hosts only (see
 * extrachill_events_user_can_manage_event_door_list() in
 * inc/core/rsvp-door-list-authority.php). Shows every attendee, including
 * private ones — the extrachill-users#414/#415 privacy resolution: private
 * stays private to the public, but the host of an event they run may see
 * who is coming. Ships no CSS of its own; see rsvp-pass.php's docblock for
 * the same design-system contract.
 *
 * @var int    $event_id  Event post ID.
 * @var string $perk_text Attendee-facing perk description.
 * @var array  $rows      Each: user_id, display_name, pass_status ('active'|'redeemed'|'revoked'|''), redeemed_at (formatted, or '').
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="ec-door-list ec-surface-card ec-card-vertical-padding" data-event-id="<?php echo esc_attr( (string) $event_id ); ?>">
	<h3 class="ec-door-list__heading"><?php esc_html_e( 'Door List', 'extrachill-events' ); ?></h3>
	<p class="ec-door-list__note"><?php esc_html_e( 'Visible only to you as this event\'s host. Not shown to attendees or the public.', 'extrachill-events' ); ?></p>
	<?php if ( $perk_text ) : ?>
		<p class="ec-door-list__perk"><?php echo esc_html( $perk_text ); ?></p>
	<?php endif; ?>
	<?php if ( empty( $rows ) ) : ?>
		<p class="notice notice-info"><?php esc_html_e( 'No one has RSVPed yet.', 'extrachill-events' ); ?></p>
	<?php else : ?>
		<ul class="ec-door-list__rows">
			<?php foreach ( $rows as $row ) : ?>
				<?php $pass_status = (string) ( $row['pass_status'] ?? '' ); ?>
				<li class="ec-door-list__row" data-user-id="<?php echo esc_attr( (string) $row['user_id'] ); ?>">
					<span class="ec-door-list__name"><?php echo esc_html( $row['display_name'] ); ?></span>
					<?php if ( 'redeemed' === $pass_status ) : ?>
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
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>
