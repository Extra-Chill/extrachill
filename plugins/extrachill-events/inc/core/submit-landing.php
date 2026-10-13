<?php
/**
 * Landing section for the event submission page (#959).
 *
 * /submit used to open with a single sentence before the form. This section
 * says what a submitter gets, what happens after they submit, and offers the
 * two real paths: import a whole calendar/tour, or add one show.
 *
 * @package ExtraChillEvents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Analytics id for links in the landing section; placement tells them apart. */
const EXTRACHILL_EVENTS_SUBMIT_LANDING_TRACK = 'events_submit_landing';

/** Stable anchor for the event-source (bulk) import panel in the block. */
const EXTRACHILL_EVENTS_SUBMIT_IMPORT_ANCHOR = 'ec-event-source-import';

/**
 * One-line reach statement built from the cached calendar stats.
 *
 * Returns '' when stats are unavailable, so the page never shows "0 events".
 *
 * @return string
 */
function extrachill_events_submit_landing_reach(): string {
	if ( ! function_exists( 'extrachill_events_get_calendar_stats' ) ) {
		return '';
	}
	$stats  = extrachill_events_get_calendar_stats();
	$events = $stats['events'];
	$cities = $stats['locations'];
	if ( $events <= 0 || $cities <= 0 ) {
		return '';
	}

	return sprintf(
		/* translators: 1: number of upcoming events, 2: number of cities and regions (location terms). */
		__( 'People use Extra Chill to find shows: %1$s upcoming events across %2$s cities and regions, free to browse with no login wall.', 'extrachill-events' ),
		number_format_i18n( $events ),
		number_format_i18n( $cities )
	);
}

/** Anchor for the "How it works" section rendered below the form. */
const EXTRACHILL_EVENTS_SUBMIT_HOW_ANCHOR = 'ec-submit-how';

/**
 * Render the short intro shown above the form.
 *
 * Deliberately brief: most visitors arrive ready to submit (from the header
 * link or a venue/city CTA with the form prefilled), so the form must stay
 * near the top. The explanation lives below the form; see
 * extrachill_events_render_submit_how_it_works().
 *
 * @param string $form_id DOM id of the manual submission form, for the in-page link.
 * @return string
 */
function extrachill_events_render_submit_landing( string $form_id ): string {
	$reach = extrachill_events_submit_landing_reach();

	ob_start();
	?>
	<section class="ec-submit-landing" aria-labelledby="ec-submit-landing-title">
		<h2 id="ec-submit-landing-title" class="ec-submit-landing__title"><?php esc_html_e( 'Get your show on the calendar', 'extrachill-events' ); ?></h2>
		<p class="ec-submit-landing__lede">
			<?php esc_html_e( 'Adding a show is free.', 'extrachill-events' ); ?>
			<?php if ( '' !== $reach ) : ?>
				<?php echo esc_html( $reach ); ?>
			<?php endif; ?>
			<a class="ec-submit-landing__how-link" href="<?php echo esc_url( '#' . EXTRACHILL_EVENTS_SUBMIT_HOW_ANCHOR ); ?>" data-ec-track="<?php echo esc_attr( EXTRACHILL_EVENTS_SUBMIT_LANDING_TRACK ); ?>" data-ec-track-placement="how_it_works"><?php esc_html_e( 'How it works ↓', 'extrachill-events' ); ?></a>
		</p>

		<div class="ec-submit-landing__paths">
			<a class="ec-submit-landing__path" href="<?php echo esc_url( '#' . EXTRACHILL_EVENTS_SUBMIT_IMPORT_ANCHOR ); ?>" data-ec-track="<?php echo esc_attr( EXTRACHILL_EVENTS_SUBMIT_LANDING_TRACK ); ?>" data-ec-track-placement="import">
				<strong><?php esc_html_e( 'Have a whole calendar or tour?', 'extrachill-events' ); ?></strong>
				<span><?php esc_html_e( 'Give us the URL and new shows import automatically.', 'extrachill-events' ); ?></span>
			</a>
			<a class="ec-submit-landing__path" href="<?php echo esc_url( '#' . $form_id ); ?>" data-ec-track="<?php echo esc_attr( EXTRACHILL_EVENTS_SUBMIT_LANDING_TRACK ); ?>" data-ec-track-placement="manual">
				<strong><?php esc_html_e( 'Just one show?', 'extrachill-events' ); ?></strong>
				<span><?php esc_html_e( 'Fill in the form below.', 'extrachill-events' ); ?></span>
			</a>
		</div>
	</section>
	<?php
	return (string) ob_get_clean();
}

/**
 * Render the "How it works" section shown below the form.
 *
 * Every line describes shipped behavior: automated import (data-machine-events
 * flows), required vs optional fields and flyer extraction (event-submission
 * block), human review, venue/city/Near Me placement and the weekly city email
 * (local scene digest), the publish email (#952), account creation with a
 * set-password link, trusted auto-publish (#953), and recurring source import.
 *
 * @return string
 */
function extrachill_events_render_submit_how_it_works(): string {
	$items = array(
		array(
			__( 'Most of the calendar fills itself.', 'extrachill-events' ),
			__( 'We import shows automatically from venue websites and ticketing calendars. Submissions cover what that misses: new venues, DIY and house shows, anything not listed online.', 'extrachill-events' ),
		),
		array(
			__( 'What we need.', 'extrachill-events' ),
			__( 'A title, a date and how to reach you. Venue, lineup, ticket link and a flyer are optional. If you add a flyer, we read it and fill in the details it shows.', 'extrachill-events' ),
		),
		array(
			__( 'A person checks it.', 'extrachill-events' ),
			__( 'Once it’s approved, your show gets its own page and appears on its venue’s page, its city’s page and Near Me. It can also go out in that city’s weekly email to subscribers.', 'extrachill-events' ),
		),
		array(
			__( 'You get the link.', 'extrachill-events' ),
			__( 'We email it when the show is live, so you can share it.', 'extrachill-events' ),
		),
		array(
			__( 'Your account.', 'extrachill-events' ),
			__( 'Submitting connects your show to a free Extra Chill account, and we email you a link to set a password. Once you’ve had a show published, the next ones you add while signed in skip review.', 'extrachill-events' ),
		),
		array(
			__( 'Whole calendars and tours.', 'extrachill-events' ),
			__( 'Give us the URL. We test it, review it once, and new shows from it import automatically after that.', 'extrachill-events' ),
		),
	);

	ob_start();
	?>
	<section id="<?php echo esc_attr( EXTRACHILL_EVENTS_SUBMIT_HOW_ANCHOR ); ?>" class="ec-submit-how" aria-labelledby="ec-submit-how-title">
		<h2 id="ec-submit-how-title" class="ec-submit-how__title"><?php esc_html_e( 'How it works', 'extrachill-events' ); ?></h2>
		<dl class="ec-submit-how__list">
			<?php foreach ( $items as $item ) : ?>
				<dt><?php echo esc_html( $item[0] ); ?></dt>
				<dd><?php echo esc_html( $item[1] ); ?></dd>
			<?php endforeach; ?>
		</dl>
	</section>
	<?php
	return (string) ob_get_clean();
}
