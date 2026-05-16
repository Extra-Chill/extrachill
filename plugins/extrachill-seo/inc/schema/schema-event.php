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
			return array( 'type' => 'single', 'price' => number_format( $low, 2, '.', '' ) );
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

	$name = html_entity_decode( wp_strip_all_tags( $post->post_title ), ENT_QUOTES, 'UTF-8' );

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

		$address = array();
		if ( '' !== $street ) {
			$address['streetAddress'] = $street;
		}
		if ( is_array( $venue_data ) ) {
			if ( ! empty( $venue_data['city'] ) ) {
				$address['addressLocality'] = (string) $venue_data['city'];
			}
			if ( ! empty( $venue_data['state'] ) ) {
				$address['addressRegion'] = (string) $venue_data['state'];
			}
			if ( ! empty( $venue_data['zip'] ) ) {
				$address['postalCode'] = (string) $venue_data['zip'];
			}
			if ( ! empty( $venue_data['country'] ) ) {
				$address['addressCountry'] = (string) $venue_data['country'];
			}
		}

		if ( ! empty( $address ) ) {
			$address['@type']   = 'PostalAddress';
			$place['address']   = $address;
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
			$offers['url'] = esc_url_raw( $ticket_url );
		}

		$schema['offers'] = $offers;
	}

	return $schema;
}

add_filter(
	'extrachill_seo_schema_graph',
	function ( $graph ) {
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
	},
	10
);
