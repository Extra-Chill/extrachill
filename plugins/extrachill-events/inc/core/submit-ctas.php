<?php
/**
 * Context-aware "add your show" calls to action.
 *
 * Venue, city and single-event pages are where artists, promoters and
 * venue staff already look at their own scene, yet the only route to
 * /submit/ was a generic header link (#958). These CTAs carry the page's
 * venue/city into the submission form and are tagged for the
 * extrachill-analytics cta_click / form_submit funnel.
 *
 * @package ExtraChillEvents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Analytics id shared by every submit CTA; placement tells them apart. */
const EXTRACHILL_EVENTS_SUBMIT_CTA_TRACK = 'events_submit_cta';

/**
 * Query-string arg names that carry submit prefill, keyed by prefill field.
 *
 * Namespaced on purpose: `venue` (and `location`, `artist`, `festival`, …) are
 * public taxonomy query vars, so a bare `?venue=` makes WordPress resolve
 * /submit/ as a venue archive instead of the submission page (#963).
 */
const EXTRACHILL_EVENTS_SUBMIT_PREFILL_ARGS = array(
	'venue' => 'submit_venue',
	'city'  => 'submit_city',
);

/** Longest prefill value accepted from the query string. */
const EXTRACHILL_EVENTS_SUBMIT_PREFILL_MAX = 120;

/**
 * Build the /submit/ URL carrying optional venue and city context.
 *
 * @param string $venue Venue name, or ''.
 * @param string $city  City name, or ''.
 * @return string
 */
function extrachill_events_submit_url( string $venue = '', string $city = '' ): string {
	$args = array();
	if ( '' !== $venue ) {
		$args[ EXTRACHILL_EVENTS_SUBMIT_PREFILL_ARGS['venue'] ] = $venue;
	}
	if ( '' !== $city ) {
		$args[ EXTRACHILL_EVENTS_SUBMIT_PREFILL_ARGS['city'] ] = $city;
	}

	$url = home_url( '/submit/' );
	return $args ? add_query_arg( array_map( 'rawurlencode', $args ), $url ) : $url;
}

/**
 * Read venue/city prefill values from the current request.
 *
 * Reads the namespaced args from EXTRACHILL_EVENTS_SUBMIT_PREFILL_ARGS and
 * returns them under the plain `venue` / `city` field keys.
 *
 * Values only ever populate visible form fields the submitter can edit, so
 * they are sanitized as plain text and length-capped, never trusted.
 *
 * @return array{venue:string,city:string}
 */
function extrachill_events_submit_prefill(): array {
	$out = array(
		'venue' => '',
		'city'  => '',
	);
	foreach ( EXTRACHILL_EVENTS_SUBMIT_PREFILL_ARGS as $key => $arg ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only form prefill.
		if ( ! isset( $_GET[ $arg ] ) || ! is_scalar( $_GET[ $arg ] ) ) {
			continue;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only form prefill.
		$value       = sanitize_text_field( (string) wp_unslash( $_GET[ $arg ] ) );
		$out[ $key ] = function_exists( 'mb_substr' )
			? mb_substr( $value, 0, EXTRACHILL_EVENTS_SUBMIT_PREFILL_MAX )
			: substr( $value, 0, EXTRACHILL_EVENTS_SUBMIT_PREFILL_MAX );
	}
	return $out;
}

/**
 * Resolve the archive CTA context for the current venue or location archive.
 *
 * @return array{placement:string,venue:string,city:string,title:string,body:string}|null
 */
function extrachill_events_submit_cta_archive_context(): ?array {
	if ( is_tax( 'venue' ) ) {
		$term = get_queried_object();
		if ( ! $term instanceof WP_Term ) {
			return null;
		}
		$city = (string) get_term_meta( (int) $term->term_id, '_venue_city', true );
		return array(
			'placement' => 'venue_archive',
			'venue'     => (string) $term->name,
			'city'      => $city,
			/* translators: %s: venue name. */
			'title'     => sprintf( __( 'Missing a show at %s?', 'extrachill-events' ), $term->name ),
			'body'      => __( 'Add it to the calendar. It’s free, and we’ll email you the link when it’s live.', 'extrachill-events' ),
		);
	}

	if ( is_tax( 'location' ) ) {
		$term = get_queried_object();
		if ( ! $term instanceof WP_Term ) {
			return null;
		}
		return array(
			'placement' => 'location_archive',
			'venue'     => '',
			'city'      => (string) $term->name,
			/* translators: %s: city name. */
			'title'     => sprintf( __( 'Playing in %s?', 'extrachill-events' ), $term->name ),
			'body'      => __( 'Add your show to the calendar. It’s free, and we’ll email you the link when it’s live.', 'extrachill-events' ),
		);
	}

	return null;
}

/**
 * Render the archive CTA below the calendar on venue and location archives.
 *
 * @return void
 */
function extrachill_events_render_submit_cta_archive(): void {
	$context = extrachill_events_submit_cta_archive_context();
	if ( null === $context ) {
		return;
	}
	$url = extrachill_events_submit_url( $context['venue'], $context['city'] );
	?>
	<aside class="events-market-context events-market-context--quiet events-submit-cta" aria-label="<?php esc_attr_e( 'Add a show to the calendar', 'extrachill-events' ); ?>">
		<div class="events-market-context__copy">
			<strong><?php echo esc_html( $context['title'] ); ?></strong>
			<span><?php echo esc_html( $context['body'] ); ?></span>
		</div>
		<div class="events-market-context__actions">
			<a class="button-1 button-small" href="<?php echo esc_url( $url ); ?>" data-ec-track="<?php echo esc_attr( EXTRACHILL_EVENTS_SUBMIT_CTA_TRACK ); ?>" data-ec-track-placement="<?php echo esc_attr( $context['placement'] ); ?>"><?php esc_html_e( 'Add a show', 'extrachill-events' ); ?></a>
		</div>
	</aside>
	<?php
}
add_action( 'extrachill_archive_below_calendar', 'extrachill_events_render_submit_cta_archive', 15 );

/**
 * Render a secondary "add a show here" link in the single-event action row.
 *
 * Renders as its own quiet row after the ticket and share buttons (and
 * before the network bridge), and prefills the event's
 * venue and city, so someone looking at one show can add the next one.
 *
 * @param int    $post_id    Event post ID.
 * @param string $ticket_url Ticket URL (unused).
 * @return void
 */
function extrachill_events_render_submit_cta_single( $post_id, $ticket_url = '' ): void {
	unset( $ticket_url );
	$post_id = (int) $post_id;
	if ( $post_id <= 0 ) {
		return;
	}

	$venue = '';
	$city  = '';
	$terms = get_the_terms( $post_id, 'venue' );
	if ( is_array( $terms ) && isset( $terms[0] ) ) {
		$venue = (string) $terms[0]->name;
		$city  = (string) get_term_meta( (int) $terms[0]->term_id, '_venue_city', true );
	}
	if ( '' === $city ) {
		$locations = get_the_terms( $post_id, 'location' );
		if ( is_array( $locations ) && isset( $locations[0] ) ) {
			$city = (string) $locations[0]->name;
		}
	}

	$label = '' !== $venue
		/* translators: %s: venue name. */
		? sprintf( __( 'Add a show at %s', 'extrachill-events' ), $venue )
		: __( 'Add a show', 'extrachill-events' );
	?>
	<a class="events-submit-cta-link" href="<?php echo esc_url( extrachill_events_submit_url( $venue, $city ) ); ?>" data-ec-track="<?php echo esc_attr( EXTRACHILL_EVENTS_SUBMIT_CTA_TRACK ); ?>" data-ec-track-placement="single_event"><?php echo esc_html( $label ); ?></a>
	<?php
}
add_action( 'data_machine_events_action_buttons', 'extrachill_events_render_submit_cta_single', 25, 2 );
