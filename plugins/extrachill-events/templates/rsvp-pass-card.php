<?php
/**
 * RSVP / guest list pass — calendar-style event card (extrachill#1127).
 *
 * Shown in place of pass artwork when the event has neither custom pass art
 * nor a poster. Mirrors the events calendar item
 * (data-machine-events templates/event-item.php): taxonomy badges, title,
 * time. Calendar classes are styled by the data-machine-events calendar
 * stylesheet; ec-rsvp-pass__card resets the calendar's fixed grid-cell size
 * in single-event.css. Allow-listed in RsvpPassDesignSystemTest.php.
 *
 * Rendered by extrachill_events_render_pass_card().
 *
 * @var string $card_badges Badge markup from data_machine_events_render_taxonomy_badges(); escaped by the renderer. Empty when unavailable.
 * @var string $card_title  Event title.
 * @var string $card_url    Event permalink.
 * @var string $card_time   Formatted date and time; empty when unknown.
 *
 * @package ExtraChillEvents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="data-machine-event-item ec-rsvp-pass__card">
	<div class="data-machine-event-link">
		<?php echo $card_badges; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Badge renderer escapes each taxonomy label and URL. ?>
		<h4 class="data-machine-event-title">
			<a href="<?php echo esc_url( $card_url ); ?>"><?php echo esc_html( $card_title ); ?></a>
		</h4>
		<?php if ( '' !== $card_time ) : ?>
		<div class="data-machine-event-meta">
			<div class="data-machine-event-time"><?php echo esc_html( $card_time ); ?></div>
		</div>
		<?php endif; ?>
	</div>
</div>
