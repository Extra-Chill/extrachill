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
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div
	id="ec-rsvp-pass-<?php echo esc_attr( (string) $event_id ); ?>"
	class="ec-rsvp-pass ec-surface-card ec-card-vertical-padding"
	data-event-id="<?php echo esc_attr( (string) $event_id ); ?>"
	<?php echo $has_pass ? '' : 'hidden'; ?>
>
	<p class="ec-rsvp-pass__perk"><?php echo esc_html( $perk_text ); ?></p>
	<p class="ec-rsvp-pass__label"><?php esc_html_e( 'Show this pass at the door:', 'extrachill-events' ); ?></p>
	<p class="ec-rsvp-pass__code"><?php echo esc_html( $code ); ?></p>
</div>
