<?php
/**
 * Event Schema for Single Event Posts
 *
 * Outputs schema.org MusicEvent JSON-LD on singular `data_machine_events`
 * posts. Reads structured event data from the `data-machine-events/event-details`
 * block stored in post_content. Builds a fully-populated Event entity with
 * location (Place + PostalAddress), performer, organizer, and offers
 * (Offer or AggregateOffer depending on whether the price is a range).
 *
 * Hooks the shared `extrachill_seo_schema_graph` filter so the entity is
 * appended to the consolidated @graph emitted by inc/schema/schema-output.php.
 *
 * Extension surface: this emitter defaults `@type` to `MusicEvent`. Future
 * work can detect ComedyEvent / TheaterEvent / Festival / DanceEvent /
 * SportsEvent via taxonomy or block-attribute signals once we have data on
 * the event-type histogram; this PR stays scoped to the MusicEvent default.
 *
 * @see https://github.com/Extra-Chill/extrachill-seo/issues/12
 * @package ExtraChill\SEO
 */

namespace ExtraChill\SEO\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Extract the first `data-machine-events/event-details` block's attrs.
 *
 * Walks parsed blocks recursively so a block wrapped in a Group, Columns,
 * or any other container block is still found. Mirrors the extraction
 * approach used by `data_machine_events_sync_datetime_meta()` in
 * data-machine-events/inc/Core/event-dates-sync.php — the canonical reader
 * of this block — but adds recursion into innerBlocks.
 *
 * @param string $post_content Raw post content with block markup.
 * @return array Block attrs array, or empty array if not found.
 */
function ec_seo_extract_event_details_block( string $post_content ): array {
	if ( '' === trim( $post_content ) ) {
		return array();
	}

	if ( false === strpos( $post_content, 'data-machine-events/event-details' ) ) {
		return array();
	}

	$blocks = parse_blocks( $post_content );
	$found  = ec_seo_find_event_details_block( $blocks );

	if ( null === $found ) {
		return array();
	}

	$attrs = $found['attrs'] ?? array();
	return is_array( $attrs ) ? $attrs : array();
}

/**
 * Recursively search a parsed-block tree for the event-details block.
 *
 * @param array $blocks Parsed blocks (output of parse_blocks()).
 * @return array|null Block array or null if not found.
 */
function ec_seo_find_event_details_block( array $blocks ): ?array {
	foreach ( $blocks as $block ) {
		if ( ! is_array( $block ) ) {
			continue;
		}

		if ( 'data-machine-events/event-details' === ( $block['blockName'] ?? '' ) ) {
			return $block;
		}

		if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			$found = ec_seo_find_event_details_block( $block['innerBlocks'] );
			if ( null !== $found ) {
				return $found;
			}
		}
	}

	return null;
}

/**
 * Build an ISO 8601 datetime string for schema.org from date + optional time.
 *
 * When a time is provided, the result includes timezone offset using either
 * the venue's IANA timezone (when known) or the site timezone. When time is
 * omitted, returns the date alone (schema.org accepts bare YYYY-MM-DD).
 *
 * @param string  $date     YYYY-MM-DD date string.
 * @param string  $time     HH:MM or HH:MM:SS time string. Empty allowed.
 * @param string  $timezone IANA timezone string. Empty falls back to wp_timezone().
 * @return string ISO 8601 datetime, or empty string on invalid input.
 */
function ec_seo_format_event_datetime( string $date, string $time, string $timezone ): string {
	$date = trim( $date );
	if ( '' === $date ) {
		return '';
	}

	$time = trim( $time );
	if ( '' === $time ) {
		return $date;
	}

	// Pad HH:MM to HH:MM:SS so DateTime parses consistently.
	$parts = explode( ':', $time );
	if ( 2 === count( $parts ) ) {
		$time .= ':00';
	}

	try {
		$tz = '' !== $timezone ? new \DateTimeZone( $timezone ) : wp_timezone();
		$dt = new \DateTime( $date . ' ' . $time, $tz );
		return $dt->format( 'c' );
	} catch ( \Exception $e ) {
		return $date;
	}
}

/**
 * Parse a free-text price string into a normalized structure.
 *
 * Handles:
 *   "$15"          => [ 'type' => 'single', 'price' => '15.00' ]
 *   "$25-$30"      => [ 'type' => 'range',  'low' => '25.00', 'high' => '30.00' ]
 *   "$25 – $30"    => [ 'type' => 'range',  'low' => '25.00', 'high' => '30.00' ]
 *   "Free", "TBA"  => [ 'type' => 'none' ]
 *   "" / null      => [ 'type' => 'none' ]
 *
 * @param string $price Raw price attribute.
 * @return array Normalized price structure.
 */
function ec_seo_parse_event_price( string $price ): array {
	$price = trim( $price );
	if ( '' === $price ) {
		return array( 'type' => 'none' );
	}

	// Pull all numbers (including decimals) out of the string.
	if ( ! preg_match_all( '/\d+(?:\.\d+)?/', $price, $matches ) ) {
		return array( 'type' => 'none' );
	}

	$numbers = array_map( 'floatval', $matches[0] );
	if ( empty( $numbers ) ) {
		return array( 'type' => 'none' );
	}

	if ( count( $numbers ) >= 2 ) {
		$low  = min( $numbers );
		$high = max( $numbers );
		if ( $low === $high ) {
			return array(
				'type'  => 'single',
				'price' => number_format( $low, 2, '.', '' ),
			);
		}
		return array(
			'type' => 'range',
			'low'  => number_format( $low, 2, '.', '' ),
			'high' => number_format( $high, 2, '.', '' ),
		);
	}

	return array(
		'type'  => 'single',
		'price' => number_format( $numbers[0], 2, '.', '' ),
	);
}

/**
 * Resolution outcomes for Event offers.url resolution.
 *
 * These make degradation observable: the two permalink fallbacks are
 * distinct states, not a silent catch-all, even though both emit the same
 * URL.
 */
const RESOLUTION_VENDOR_URL                   = 'vendor_url';
const RESOLUTION_PASSTHROUGH                  = 'passthrough';
const RESOLUTION_FALLBACK_HELPERS_UNAVAILABLE = 'fallback_helpers_unavailable';
const RESOLUTION_FALLBACK_UNPARSEABLE         = 'fallback_unparseable';

/**
 * Compute the URL to emit as the Event offers.url.
 *
 * Pure resolution step with the domain helpers injected so every branch is
 * unit-testable, including the helpers-absent fallback. Behavior:
 *
 * - Helpers unavailable (data-machine-events not loaded, or a helper not
 *   exported at its global name): `fallback_helpers_unavailable`. Affiliate
 *   wrappers cannot be detected or unwrapped safely, so the raw ticket URL
 *   is never published.
 * - Not an affiliate URL: `passthrough`, URL unchanged.
 * - Affiliate URL that unwraps to a different URL: `vendor_url`, the
 *   de-affiliated vendor destination.
 * - Affiliate URL that cannot be unwrapped (the unwrapper returns its input
 *   unchanged): `fallback_unparseable`.
 *
 * The two fallback states both resolve to `$fallback_url` but are reported
 * separately so callers and tests can distinguish "domain helpers missing"
 * from "wrapper present but unparseable".
 *
 * @param string        $ticket_url   Raw ticket URL from the event-details block.
 * @param string        $fallback_url Event permalink used when the ticket URL
 *                                    cannot be safely published.
 * @param callable|null $is_affiliate `data_machine_events_is_affiliate_ticket_url()`
 *                                    when available, null otherwise.
 * @param callable|null $unwrap       `datamachine_unwrap_affiliate_url()` when
 *                                    available, null otherwise.
 * @return array{ url: string, resolution: string } Emitted URL plus one of
 *                                                  the RESOLUTION_* states.
 */
function ec_seo_compute_offer_url( string $ticket_url, string $fallback_url, ?callable $is_affiliate, ?callable $unwrap ): array {
	if ( null === $is_affiliate || null === $unwrap ) {
		return array(
			'url'        => $fallback_url,
			'resolution' => RESOLUTION_FALLBACK_HELPERS_UNAVAILABLE,
		);
	}

	if ( ! $is_affiliate( $ticket_url ) ) {
		return array(
			'url'        => $ticket_url,
			'resolution' => RESOLUTION_PASSTHROUGH,
		);
	}

	$unwrapped = $unwrap( $ticket_url );

	if ( '' !== $unwrapped && $unwrapped !== $ticket_url ) {
		return array(
			'url'        => $unwrapped,
			'resolution' => RESOLUTION_VENDOR_URL,
		);
	}

	return array(
		'url'        => $fallback_url,
		'resolution' => RESOLUTION_FALLBACK_UNPARSEABLE,
	);
}

/**
 * Resolve the URL to emit as the Event offers.url for a ticket URL.
 *
 * Ticket URLs stored on events may be affiliate wrappers that carry the real
 * vendor destination behind a redirect. Publishing those wrappers in
 * structured data puts an affiliate-tracking URL into raw, machine-readable
 * HTML, which our ticketing affiliate agreements prohibit.
 *
 * Affiliate detection and unwrapping belong to the events domain layer
 * (data-machine-events). This SEO layer only asks whether a URL is
 * affiliate-wrapped and requests its de-affiliated destination; no affiliate
 * host names or wrapper parsing live here.
 *
 * Cross-plugin contract: both helpers are consumed at their GLOBAL names.
 * data-machine-events exports them from inc/public-api.php with
 * function_exists() guards (data-machine-events#820). Never reach into the
 * DataMachineEvents namespaces directly. Until data-machine-events is
 * loaded and both globals exist, this resolver safely degrades to the event
 * permalink — a valid offers.url — and the raw ticket URL is never
 * published. Full de-affiliated-vendor-URL resolution therefore depends on
 * data-machine-events#820 shipping that global export.
 *
 * @param string $ticket_url   Raw ticket URL from the event-details block.
 * @param string $fallback_url Event permalink used when the ticket URL
 *                             cannot be safely published.
 * @return string URL safe to emit as offers.url.
 *
 * @see https://github.com/Extra-Chill/extrachill-seo/issues/57
 */
function ec_seo_resolve_offer_url( string $ticket_url, string $fallback_url ): string {
	$is_affiliate = function_exists( 'data_machine_events_is_affiliate_ticket_url' )
		? 'data_machine_events_is_affiliate_ticket_url'
		: null;
	$unwrap       = function_exists( 'datamachine_unwrap_affiliate_url' )
		? 'datamachine_unwrap_affiliate_url'
		: null;

	$resolved = ec_seo_compute_offer_url( $ticket_url, $fallback_url, $is_affiliate, $unwrap );

	return $resolved['url'];
}

/**
 * Build the Event schema entity for a single event post.
 *
 * @param \WP_Post $post  Event post.
 * @param array    $attrs Event-details block attributes.
 * @return array|null Schema entity array, or null if data is insufficient.
 */
function ec_seo_build_event_schema( \WP_Post $post, array $attrs ): ?array {
	$start_date = isset( $attrs['startDate'] ) ? (string) $attrs['startDate'] : '';
	if ( '' === $start_date ) {
		return null;
	}

	$permalink = get_permalink( $post );
	if ( ! $permalink ) {
		return null;
	}

	// Tags stripped here; entity decoding happens once at the serialization
	// boundary (ec_seo_get_schema_graph()) so this value is emitted the same
	// way as every other text value in the document.
	$name = wp_strip_all_tags( $post->post_title );

	// Resolve venue term + venue data (city/state/zip/country/timezone)
	// via the data-machine-events public integration API.
	$venue_term = null;
	$venue_data = null;
	$terms      = get_the_terms( $post->ID, 'venue' );
	if ( is_array( $terms ) && ! empty( $terms ) && $terms[0] instanceof \WP_Term ) {
		$venue_term = $terms[0];
		if ( function_exists( 'data_machine_events_get_venue_data' ) ) {
			$venue_data = data_machine_events_get_venue_data( (int) $venue_term->term_id );
		}
	}

	$timezone = '';
	if ( is_array( $venue_data ) && ! empty( $venue_data['timezone'] ) ) {
		$timezone = (string) $venue_data['timezone'];
	}

	$schema = array(
		'@type'               => 'MusicEvent',
		'@id'                 => $permalink . '#event',
		'name'                => $name,
		'url'                 => $permalink,
		'startDate'           => ec_seo_format_event_datetime(
			$start_date,
			isset( $attrs['startTime'] ) ? (string) $attrs['startTime'] : '',
			$timezone
		),
		'eventStatus'         => 'https://schema.org/' . ( ! empty( $attrs['eventStatus'] ) ? (string) $attrs['eventStatus'] : 'EventScheduled' ),
		'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
	);

	// endDate (optional).
	$end_date = isset( $attrs['endDate'] ) ? (string) $attrs['endDate'] : '';
	$end_time = isset( $attrs['endTime'] ) ? (string) $attrs['endTime'] : '';
	if ( '' !== $end_date || '' !== $end_time ) {
		$effective_end_date = '' !== $end_date ? $end_date : $start_date;
		$end_iso            = ec_seo_format_event_datetime( $effective_end_date, $end_time, $timezone );
		if ( '' !== $end_iso ) {
			$schema['endDate'] = $end_iso;
		}
	}

	// description: prefer excerpt, fall back to trimmed content.
	$description = '';
	if ( ! empty( $post->post_excerpt ) ) {
		$description = wp_strip_all_tags( $post->post_excerpt );
	} else {
		$stripped = wp_strip_all_tags( $post->post_content );
		if ( '' !== trim( $stripped ) ) {
			$description = wp_trim_words( $stripped, 40, '...' );
		}
	}
	if ( '' !== $description ) {
		$schema['description'] = $description;
	}

	// image: featured image first, then OG fallback via the same resolver
	// inc/core/open-graph.php uses (the site icon ultimately).
	$image = get_the_post_thumbnail_url( $post, 'full' );
	if ( ! $image && function_exists( '\\ExtraChill\\SEO\\OpenGraph\\ec_seo_get_og_image' ) ) {
		$image = \ExtraChill\SEO\OpenGraph\ec_seo_get_og_image( $post );
	}
	if ( $image ) {
		$schema['image'] = $image;
	}

	// location: Place + PostalAddress.
	$venue_name = isset( $attrs['venue'] ) ? (string) $attrs['venue'] : '';
	if ( ! $venue_name && $venue_term instanceof \WP_Term ) {
		$venue_name = $venue_term->name;
	}
	if ( '' !== $venue_name ) {
		$place = array(
			'@type' => 'Place',
			'name'  => $venue_name,
		);

		// Prefer venue term meta for structured address; block attr `address`
		// is the street fallback when no venue term exists.
		$street = '';
		if ( is_array( $venue_data ) && ! empty( $venue_data['address'] ) ) {
			$street = (string) $venue_data['address'];
		} elseif ( ! empty( $attrs['address'] ) ) {
			$street = (string) $attrs['address'];
		}

		$address = ec_seo_build_postal_address(
			array(
				'street'  => $street,
				'city'    => is_array( $venue_data ) ? ( $venue_data['city'] ?? '' ) : '',
				'state'   => is_array( $venue_data ) ? ( $venue_data['state'] ?? '' ) : '',
				'zip'     => is_array( $venue_data ) ? ( $venue_data['zip'] ?? '' ) : '',
				'country' => is_array( $venue_data ) ? ( $venue_data['country'] ?? '' ) : '',
			)
		);

		if ( ! empty( $address ) ) {
			$place['address'] = $address;
		}

		$schema['location'] = $place;
	}

	// performer: skip when empty.
	$performer_name = isset( $attrs['performer'] ) ? trim( (string) $attrs['performer'] ) : '';
	if ( '' !== $performer_name ) {
		$performer_type = ! empty( $attrs['performerType'] ) ? (string) $attrs['performerType'] : 'MusicGroup';
		// Only allow the two canonical schema.org performer types.
		if ( ! in_array( $performer_type, array( 'MusicGroup', 'Person' ), true ) ) {
			$performer_type = 'MusicGroup';
		}
		$schema['performer'] = array(
			'@type' => $performer_type,
			'name'  => $performer_name,
		);
	}

	// organizer: skip when empty.
	$organizer_name = isset( $attrs['organizer'] ) ? trim( (string) $attrs['organizer'] ) : '';
	if ( '' !== $organizer_name ) {
		$organizer_type = ! empty( $attrs['organizerType'] ) ? (string) $attrs['organizerType'] : 'Organization';
		if ( ! in_array( $organizer_type, array( 'Organization', 'Person' ), true ) ) {
			$organizer_type = 'Organization';
		}
		$organizer = array(
			'@type' => $organizer_type,
			'name'  => $organizer_name,
		);
		if ( ! empty( $attrs['organizerUrl'] ) ) {
			$organizer['url'] = esc_url_raw( (string) $attrs['organizerUrl'] );
		}
		$schema['organizer'] = $organizer;
	}

	// offers: skip entirely when no ticketUrl AND no price.
	$ticket_url = isset( $attrs['ticketUrl'] ) ? trim( (string) $attrs['ticketUrl'] ) : '';
	$price_raw  = isset( $attrs['price'] ) ? (string) $attrs['price'] : '';
	$parsed     = ec_seo_parse_event_price( $price_raw );

	if ( '' !== $ticket_url || 'none' !== $parsed['type'] ) {
		$currency     = ! empty( $attrs['priceCurrency'] ) ? (string) $attrs['priceCurrency'] : 'USD';
		$availability = 'https://schema.org/' . ( ! empty( $attrs['offerAvailability'] ) ? (string) $attrs['offerAvailability'] : 'InStock' );

		if ( 'range' === $parsed['type'] ) {
			$offers = array(
				'@type'         => 'AggregateOffer',
				'lowPrice'      => $parsed['low'],
				'highPrice'     => $parsed['high'],
				'priceCurrency' => $currency,
				'availability'  => $availability,
			);
		} else {
			$offers = array(
				'@type'         => 'Offer',
				'priceCurrency' => $currency,
				'availability'  => $availability,
			);
			if ( 'single' === $parsed['type'] ) {
				$offers['price'] = $parsed['price'];
			}
		}

		if ( '' !== $ticket_url ) {
			$offers['url'] = esc_url_raw( ec_seo_resolve_offer_url( $ticket_url, $permalink ) );
		}

		$schema['offers'] = $offers;
	}

	return $schema;
}

/**
 * Append MusicEvent schema to the graph on single event posts.
 *
 * @param array $graph Current schema graph.
 * @return array Graph with event entity appended when applicable.
 */
function ec_seo_emit_event_schema( $graph ) {
	if ( ! is_singular( 'data_machine_events' ) ) {
		return $graph;
	}

	$post = get_queried_object();
	if ( ! ( $post instanceof \WP_Post ) ) {
		return $graph;
	}

	$attrs = ec_seo_extract_event_details_block( $post->post_content );
	if ( empty( $attrs ) || empty( $attrs['startDate'] ) ) {
		return $graph;
	}

	$event = ec_seo_build_event_schema( $post, $attrs );
	if ( null === $event ) {
		return $graph;
	}

	$graph[] = $event;
	return $graph;
}
add_filter( 'extrachill_seo_schema_graph', __NAMESPACE__ . '\\ec_seo_emit_event_schema', 10 );
