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
<p
	id="ec-rsvp-perk-teaser-<?php echo esc_attr( (string) $event_id ); ?>"
	class="ec-rsvp-perk-teaser"
	<?php echo $has_pass ? 'hidden' : ''; ?>
>
	<?php
	/* translators: %s: attendee-facing perk description, e.g. "Your first beer is on Extra Chill." */
	printf( esc_html__( 'Mark yourself Going to get your pass. %s', 'extrachill-events' ), esc_html( $perk_text ) );
	?>
</p>
<div
	id="ec-rsvp-pass-<?php echo esc_attr( (string) $event_id ); ?>"
	class="ec-rsvp-pass ec-surface-card ec-card-vertical-padding"
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
