<?php
/**
 * RSVP perk pass — attendee-facing fragment.
 *
 * Rendered inside the theme chrome; ships no CSS of its own. Uses only
 * verified theme surface classes (grep themes/extrachill/style.css, not
 * inferred) plus a small set of intentionally CSS-free hook classes,
 * documented and allow-listed in RsvpPassDesignSystemTest.php. Visibility
 * toggling uses the native [hidden] attribute, not a class, so no CSS rule
 * is needed for show/hide.
 *
 * @var int    $event_id  Event post ID.
 * @var bool   $has_pass  Whether the current viewer already holds an active pass.
 * @var string $code      Pass code, when $has_pass is true.
 * @var string $perk_text Attendee-facing perk description.
 * @var string $qr_url    QR image URL for $code, when $has_pass is true. Empty otherwise.
 * @var bool   $guest_form Whether to render the email-only guest RSVP form (logged-out viewers).
 * @var bool   $password_required Whether claiming a pass needs the guest list password (extrachill#433).
 * @var array  $guest_list        extrachill_events_get_guest_list_counts() result.
 * @var bool   $claim_form        Whether to render the logged-in password claim form.
 *
 * The teaser is the conversion half of the perk: anyone without an active
 * pass (logged out, not Going yet) sees what marking Going gets them. It and
 * the pass card are mutually exclusive; assets/js/rsvp-pass.js swaps them when
 * attendance changes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<?php
$guest_list_full  = ! empty( $guest_list['full'] );
$guest_list_spots = '';
if ( isset( $guest_list['spots_left'] ) && ! $guest_list_full ) {
	$guest_list_spots = sprintf(
		/* translators: 1: spots left, 2: total guest list spots. */
		_n( '%1$d of %2$d spot left.', '%1$d of %2$d spots left.', (int) $guest_list['spots_left'], 'extrachill-events' ),
		(int) $guest_list['spots_left'],
		(int) $guest_list['capacity']
	);
}
?>
<p
	id="ec-rsvp-perk-teaser-<?php echo esc_attr( (string) $event_id ); ?>"
	class="ec-rsvp-perk-teaser"
	<?php echo $has_pass ? 'hidden' : ''; ?>
>
	<?php
	if ( $guest_list_full ) {
		esc_html_e( 'The guest list is full.', 'extrachill-events' );
	} elseif ( ! empty( $password_required ) && ! empty( $guest_form ) ) {
		/* translators: %s: attendee-facing perk description. */
		printf( esc_html__( 'Enter the guest list password and your email to get your pass. %s', 'extrachill-events' ), esc_html( $perk_text ) );
	} elseif ( ! empty( $password_required ) ) {
		/* translators: %s: attendee-facing perk description. */
		printf( esc_html__( 'Enter the guest list password to get your pass. %s', 'extrachill-events' ), esc_html( $perk_text ) );
	} elseif ( ! empty( $guest_form ) ) {
		/* translators: %s: attendee-facing perk description, e.g. "Your first beer is on Extra Chill." */
		printf( esc_html__( 'Enter your email to get your pass. %s', 'extrachill-events' ), esc_html( $perk_text ) );
	} else {
		/* translators: %s: attendee-facing perk description, e.g. "Your first beer is on Extra Chill." */
		printf( esc_html__( 'Mark yourself Going to get your pass. %s', 'extrachill-events' ), esc_html( $perk_text ) );
	}
	if ( '' !== $guest_list_spots ) {
		echo ' ' . esc_html( $guest_list_spots );
	}
	?>
</p>
<?php if ( ! empty( $guest_form ) && ! $guest_list_full ) : ?>
<form
	id="ec-rsvp-guest-<?php echo esc_attr( (string) $event_id ); ?>"
	class="ec-rsvp-guest"
	data-event-id="<?php echo esc_attr( (string) $event_id ); ?>"
	novalidate
>
	<label for="ec-rsvp-guest-email-<?php echo esc_attr( (string) $event_id ); ?>"><?php esc_html_e( 'Email', 'extrachill-events' ); ?></label>
	<input
		type="email"
		id="ec-rsvp-guest-email-<?php echo esc_attr( (string) $event_id ); ?>"
		name="email"
		autocomplete="email"
		required
	>
	<?php if ( ! empty( $password_required ) ) : ?>
	<label for="ec-rsvp-guest-password-<?php echo esc_attr( (string) $event_id ); ?>"><?php esc_html_e( 'Guest list password', 'extrachill-events' ); ?></label>
	<input
		type="password"
		id="ec-rsvp-guest-password-<?php echo esc_attr( (string) $event_id ); ?>"
		name="password"
		autocomplete="off"
		required
	>
	<?php endif; ?>
	<?php
	if ( function_exists( 'ec_render_turnstile_widget' ) ) {
		echo wp_kses_post( ec_render_turnstile_widget( array( 'data-appearance' => 'interaction-only' ) ) );
	}
	?>
	<button type="submit" class="button-1 button-medium"><?php esc_html_e( 'Get my pass', 'extrachill-events' ); ?></button>
	<p class="ec-rsvp-guest__note"><?php esc_html_e( "We'll email your pass and a link to set up your free Extra Chill account.", 'extrachill-events' ); ?></p>
	<div class="notice ec-rsvp-guest__status" role="status" hidden></div>
</form>
<?php endif; ?>
<?php if ( ! empty( $claim_form ) && ! $has_pass && ! $guest_list_full ) : ?>
<form
	id="ec-rsvp-claim-<?php echo esc_attr( (string) $event_id ); ?>"
	class="ec-rsvp-guest"
	data-event-id="<?php echo esc_attr( (string) $event_id ); ?>"
	data-mode="claim"
	novalidate
>
	<label for="ec-rsvp-claim-password-<?php echo esc_attr( (string) $event_id ); ?>"><?php esc_html_e( 'Guest list password', 'extrachill-events' ); ?></label>
	<input
		type="password"
		id="ec-rsvp-claim-password-<?php echo esc_attr( (string) $event_id ); ?>"
		name="password"
		autocomplete="off"
		required
	>
	<button type="submit" class="button-1 button-medium"><?php esc_html_e( 'Get my pass', 'extrachill-events' ); ?></button>
	<div class="notice ec-rsvp-guest__status" role="status" hidden></div>
</form>
<?php endif; ?>
<div
	id="ec-rsvp-pass-<?php echo esc_attr( (string) $event_id ); ?>"
	class="ec-rsvp-pass ec-surface-card ec-card-padding"
	data-event-id="<?php echo esc_attr( (string) $event_id ); ?>"
	<?php echo $has_pass ? '' : 'hidden'; ?>
>
	<p class="ec-rsvp-pass__perk"><?php echo esc_html( $perk_text ); ?></p>
	<p class="ec-rsvp-pass__label"><?php esc_html_e( 'Show this pass at check-in:', 'extrachill-events' ); ?></p>
	<p class="ec-rsvp-pass__code"><?php echo esc_html( $code ); ?></p>
	<img
		class="ec-rsvp-pass__qr"
		src="<?php echo $has_pass ? esc_url( $qr_url ) : ''; ?>"
		alt="<?php esc_attr_e( 'QR code for this pass', 'extrachill-events' ); ?>"
		width="200"
		height="200"
		<?php echo $has_pass ? '' : 'hidden'; ?>
	>
</div>
