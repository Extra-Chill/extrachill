<?php
/**
 * Owner's list pass page: the holder's QR for a venue's or promoter's
 * standing guest list (extrachill#1130).
 *
 * Ships no CSS of its own and does not load the single-event stylesheet:
 * theme classes plus CSS-free hooks, allow-listed in
 * RsvpPassDesignSystemTest.php.
 *
 * @package ExtraChillEvents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$code   = isset( $_GET['code'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_GET['code'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only render keyed by an unguessable bearer code.
$member = ( '' !== $code && class_exists( '\\ExtraChillEvents\\Core\\OwnerGuestListTable' ) )
	? \ExtraChillEvents\Core\OwnerGuestListTable::find_by_code( $code )
	: null;
$owner  = ( $member && \ExtraChillEvents\Core\OwnerGuestListTable::STATUS_ACTIVE === $member['status'] )
	? extrachill_events_owner_list_owner( (string) $member['owner_type'], (int) $member['owner_term_id'] )
	: null;
$valid  = is_array( $owner );

get_header();
?>
<main class="extrachill-content ec-owner-pass-page">
	<div class="ec-owner-pass ec-surface-card ec-card-padding ec-narrow-column">
		<?php if ( $valid ) : ?>
			<h1 class="ec-owner-pass__name"><?php echo esc_html( (string) $member['name'] ); ?></h1>
			<p class="ec-owner-pass__list">
				<?php
				/* translators: 1: "Venue list" or "Promoter list", 2: venue or promoter name. */
				printf( esc_html__( '%1$s: %2$s', 'extrachill-events' ), esc_html( $owner['label'] ), esc_html( $owner['owner_name'] ) );
				?>
			</p>
			<p>
				<?php
				/* translators: %s: venue or promoter name. */
				printf( esc_html__( 'Show this at the door at any %s show.', 'extrachill-events' ), esc_html( $owner['owner_name'] ) );
				?>
			</p>
			<p>
				<img src="<?php echo esc_url( extrachill_events_rsvp_pass_qr_url( $code ) ); ?>" width="250" height="250" alt="<?php esc_attr_e( 'QR code for this pass', 'extrachill-events' ); ?>">
			</p>
			<p><strong><code><?php echo esc_html( $code ); ?></code></strong></p>
		<?php else : ?>
			<p class="notice notice-info"><?php esc_html_e( 'This pass is no longer valid.', 'extrachill-events' ); ?></p>
		<?php endif; ?>
	</div>
</main>
<?php
get_footer();
