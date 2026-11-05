<?php
/**
 * Owner's guest list panel: a venue's or promoter's standing list,
 * reachable from any of its shows (extrachill#1130).
 *
 * Ships no CSS of its own; theme classes only, allow-listed in
 * RsvpPassDesignSystemTest.php. Copy buttons reuse
 * assets/js/guest-list-management.js (data-guest-list-share).
 *
 * @var int     $event_id        Event post ID the panel renders on.
 * @var array[] $owner_lists     extrachill_events_list_owner_list() results the viewer may manage.
 * @var int     $added_member_id Member just added (0 = none), to confirm with their pass link.
 *
 * @package ExtraChillEvents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<section id="ec-owner-guest-list" class="ec-surface-card ec-card-padding">
	<?php foreach ( $owner_lists as $owner_list ) : ?>
		<?php $nonce = wp_create_nonce( extrachill_events_owner_list_nonce_action( $owner_list['owner_type'], (int) $owner_list['owner_id'] ) ); ?>
		<h3>
			<?php
			/* translators: %s: venue or promoter name. */
			printf( esc_html__( 'Owner\'s list for %s', 'extrachill-events' ), esc_html( $owner_list['owner_name'] ) );
			?>
		</h3>
		<p>
			<?php
			/* translators: %s: venue or promoter name. */
			printf( esc_html__( 'Your standing guest list. Everyone here gets in free at every %s show, with one pass that works every night. It does not use this show\'s guest list spots.', 'extrachill-events' ), esc_html( $owner_list['owner_name'] ) );
			?>
		</p>
		<?php if ( empty( $owner_list['members'] ) ) : ?>
			<p><?php esc_html_e( 'No one on the list yet.', 'extrachill-events' ); ?></p>
		<?php else : ?>
			<ul>
				<?php foreach ( $owner_list['members'] as $member ) : ?>
					<li data-guest-list-share>
						<strong><?php echo esc_html( $member['name'] ); ?></strong>
						<?php if ( '' !== $member['email'] ) : ?>
							(<?php echo esc_html( $member['email'] ); ?>)
						<?php endif; ?>
						<?php if ( (int) $member['member_id'] === $added_member_id ) : ?>
							<p class="notice notice-success"><?php esc_html_e( 'Added. Text them this pass link so they have it at the door:', 'extrachill-events' ); ?></p>
						<?php endif; ?>
						<label><?php esc_html_e( 'Pass link', 'extrachill-events' ); ?>
							<input type="url" readonly value="<?php echo esc_attr( $member['pass_url'] ); ?>">
						</label>
						<button type="button" class="button-2 button-small" data-copy-guest-list><?php esc_html_e( 'Copy pass link', 'extrachill-events' ); ?></button>
						<p role="status" aria-live="polite" data-copy-status></p>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-confirm="<?php echo esc_attr( sprintf( /* translators: %s: guest name. */ __( 'Take %s off the list? Their pass stops working right away.', 'extrachill-events' ), $member['name'] ) ); ?>">
							<input type="hidden" name="action" value="ec_owner_list_remove">
							<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $event_id ); ?>">
							<input type="hidden" name="owner_type" value="<?php echo esc_attr( $owner_list['owner_type'] ); ?>">
							<input type="hidden" name="owner_id" value="<?php echo esc_attr( (string) $owner_list['owner_id'] ); ?>">
							<input type="hidden" name="member_id" value="<?php echo esc_attr( (string) $member['member_id'] ); ?>">
							<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
							<button type="submit" class="button-danger button-small"><?php esc_html_e( 'Remove', 'extrachill-events' ); ?></button>
						</form>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<form class="ec-form-stack" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<h4><?php esc_html_e( 'Add someone', 'extrachill-events' ); ?></h4>
			<input type="hidden" name="action" value="ec_owner_list_add">
			<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $event_id ); ?>">
			<input type="hidden" name="owner_type" value="<?php echo esc_attr( $owner_list['owner_type'] ); ?>">
			<input type="hidden" name="owner_id" value="<?php echo esc_attr( (string) $owner_list['owner_id'] ); ?>">
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
			<label><?php esc_html_e( 'Name', 'extrachill-events' ); ?>
				<input type="text" name="name" required maxlength="100" autocomplete="off">
			</label>
			<label><?php esc_html_e( 'Email (optional, sends them the pass)', 'extrachill-events' ); ?>
				<input type="email" name="email" maxlength="200" autocomplete="off">
			</label>
			<button type="submit" class="button-1 button-small"><?php esc_html_e( 'Add to list', 'extrachill-events' ); ?></button>
		</form>
	<?php endforeach; ?>
</section>
