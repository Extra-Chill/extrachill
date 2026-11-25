<?php
/**
 * Partner venue events payload (Extra-Chill/extrachill#1802).
 *
 * Builds the keyed, venue-scoped upcoming-events document a partner venue's
 * website renders server-side. The partner key alone selects the venue; there
 * is no venue parameter to tamper with. Every field is composed from existing
 * owners instead of re-derived here:
 *
 * - listing + dates: `data-machine-events/query-events` (publish-only, upcoming)
 * - performer/price/status: data-machine-events' public event-data hydrator
 * - JSON-LD: `extrachill/event-schema` (extrachill-seo's page entity)
 * - first-party checkout: `extrachill/get-event-ticket-offer`
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

defined( 'ABSPATH' ) || exit;

/** Composes the partner events response contract. */
final class PartnerVenueEvents {

	public const DEFAULT_LIMIT = 20;
	public const MAX_LIMIT     = 50;
	public const CACHE_TTL     = 300;

	/**
	 * Build the partner document for the venue the key belongs to.
	 *
	 * @param string $key   Raw partner key from the transport.
	 * @param int    $limit Maximum events, 1-50.
	 * @return array|\WP_Error
	 */
	public static function for_key( string $key, int $limit = self::DEFAULT_LIMIT ) {
		if ( $limit < 1 || $limit > self::MAX_LIMIT ) {
			return new \WP_Error( 'extrachill_partner_events_limit', 'limit must be an integer from 1 to 50.', array( 'status' => 400 ) );
		}

		$venue_id = VenuePartnerKey::verify( $key );
		if ( is_wp_error( $venue_id ) ) {
			return $venue_id;
		}

		$events_blog = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'events' ) : 0;
		$build       = static function () use ( $venue_id, $limit ) {
			return self::build( (int) $venue_id, $limit );
		};

		return function_exists( 'ec_with_blog' ) ? ec_with_blog( $events_blog, $build ) : $build();
	}

	/**
	 * Build the document for an already-authenticated venue.
	 *
	 * @param int $venue_id Venue term ID resolved from the key.
	 * @param int $limit    Maximum events.
	 * @return array|\WP_Error
	 */
	private static function build( int $venue_id, int $limit ) {
		$venue = get_term( $venue_id, 'venue' );
		if ( ! $venue || is_wp_error( $venue ) ) {
			// A key for a deleted venue is unusable, not a server fault.
			return new \WP_Error( 'extrachill_partner_key_invalid', 'Invalid partner key.', array( 'status' => 401 ) );
		}

		$result = self::execute(
			'data-machine-events/query-events',
			array(
				'scope'       => 'upcoming',
				'tax_filters' => array( 'venue' => array( $venue_id ) ),
				'per_page'    => $limit,
				'fields'      => 'all',
				'order'       => 'ASC',
			)
		);
		if ( ! is_array( $result ) ) {
			return new \WP_Error( 'extrachill_partner_events_unavailable', 'Events are temporarily unavailable.', array( 'status' => 503 ) );
		}

		$timezone = self::timezone( $venue_id );
		$events   = array();
		foreach ( array_slice( (array) ( $result['posts'] ?? array() ), 0, $limit ) as $row ) {
			$event = is_array( $row ) ? self::event( $row, $timezone ) : null;
			if ( null !== $event ) {
				$events[] = $event;
			}
		}

		return array(
			'venue'        => self::venue( $venue ),
			'events'       => $events,
			'generated_at' => gmdate( 'c' ),
			'cache_ttl'    => self::CACHE_TTL,
		);
	}

	/**
	 * Venue block of the contract.
	 *
	 * @param \WP_Term $venue Venue term.
	 */
	private static function venue( $venue ): array {
		$url = get_term_link( $venue );
		$url = is_wp_error( $url ) ? '' : (string) $url;

		return array(
			'id'                => (int) $venue->term_id,
			'name'              => self::text( (string) $venue->name ),
			'slug'              => (string) $venue->slug,
			'url'               => $url,
			'booking_embed_url' => self::booking_embed_url( (int) $venue->term_id, $url ),
		);
	}

	/**
	 * Hosted booking iframe URL, without a parent origin.
	 *
	 * The partner appends `parent-origin`; the booking embed admits it only
	 * when that exact origin is on the venue's allowlist. Null unless booking
	 * is enabled and at least one parent origin is allowlisted.
	 *
	 * @param int    $venue_id Venue term ID.
	 * @param string $url      Venue page URL.
	 */
	private static function booking_embed_url( int $venue_id, string $url ): ?string {
		if ( '' === $url || ! class_exists( VenueBookingConfig::class ) ) {
			return null;
		}

		$config = ( new VenueBookingConfig() )->get( $venue_id );
		if ( ! is_array( $config ) || empty( $config['enabled'] ) || empty( $config['embed']['allowed_parent_origins'] ) ) {
			return null;
		}

		return add_query_arg( 'booking-embed', '1', $url );
	}

	/**
	 * One event in the contract shape.
	 *
	 * @param array  $row      Public query-events row.
	 * @param string $timezone IANA venue timezone.
	 */
	private static function event( array $row, string $timezone ): ?array {
		$post_id = (int) ( $row['event_id'] ?? 0 );
		$post    = $post_id > 0 ? get_post( $post_id ) : null;
		$start   = self::iso( (string) ( $row['start_datetime'] ?? '' ), $timezone );
		if ( ! $post || '' === $start ) {
			return null;
		}

		$attrs   = function_exists( 'data_machine_events_parse_event_data' ) ? (array) data_machine_events_parse_event_data( $post ) : array();
		$url     = (string) ( $row['permalink'] ?? get_permalink( $post ) );
		$end     = self::iso( (string) ( $row['end_datetime'] ?? '' ), $timezone );
		$status  = self::status( (string) ( $attrs['eventStatus'] ?? '' ) );
		$offer   = self::execute( 'extrachill/get-event-ticket-offer', array( 'event' => $post_id ) );
		$offer   = is_array( $offer ) && ! empty( $offer['enabled'] ) ? $offer : null;
		$ticket  = self::ticket( $url, $status, $attrs, $offer );
		$schema  = self::execute( 'extrachill/event-schema', array( 'post_id' => $post_id ) );
		$schema  = is_array( $schema ) ? self::partner_schema( $schema, $ticket['url'] ) : null;
		$excerpt = self::text( (string) get_the_excerpt( $post ) );

		return array(
			'id'         => $post_id,
			'title'      => self::text( (string) ( $row['title'] ?? $post->post_title ) ),
			'url'        => $url,
			'start'      => $start,
			'end'        => '' === $end || $end === $start ? null : $end,
			'timezone'   => $timezone,
			'performers' => self::performers( $schema, $attrs ),
			'price'      => self::price( $attrs, $offer ),
			'image'      => self::image( $schema, $post_id ),
			'excerpt'    => '' === $excerpt ? null : $excerpt,
			'status'     => $status,
			'ticket'     => $ticket,
			'schema'     => $schema,
		);
	}

	/**
	 * Partner projection of the event page's JSON-LD entity.
	 *
	 * The page entity is reused unchanged except for outbound links. Its
	 * `offers.url` is the seller destination (e.g. an Eventbrite page) because
	 * on our own page that is the correct offer URL; on a partner site the
	 * contract routes buyers and crawlers back through Extra Chill, so every
	 * offer's `url` becomes `ticket.url` (dropped when that is null). Any other
	 * off-network URL (organizer or venue website, remote image) is removed so
	 * the partner payload never links outside Extra Chill. Our own event pages
	 * are unaffected: this runs only on the partner copy.
	 *
	 * @param array       $schema     Event entity from `extrachill/event-schema`.
	 * @param string|null $ticket_url Partner `ticket.url`.
	 */
	private static function partner_schema( array $schema, ?string $ticket_url ): array {
		if ( array_key_exists( 'offers', $schema ) ) {
			$schema['offers'] = self::route_offers( $schema['offers'], $ticket_url );
		}

		return self::strip_offsite_urls( $schema );
	}

	/**
	 * Point an Offer, an AggregateOffer (and its nested offers), or a list of
	 * offers at the partner ticket URL.
	 *
	 * @param mixed       $offers     Schema.org offers value.
	 * @param string|null $ticket_url Partner `ticket.url`.
	 * @return mixed
	 */
	private static function route_offers( $offers, ?string $ticket_url ) {
		if ( ! is_array( $offers ) ) {
			return $offers;
		}

		if ( self::is_list( $offers ) ) {
			foreach ( $offers as $index => $item ) {
				$offers[ $index ] = self::route_offers( $item, $ticket_url );
			}
			return $offers;
		}

		if ( null === $ticket_url ) {
			unset( $offers['url'] );
		} else {
			$offers['url'] = $ticket_url;
		}

		if ( array_key_exists( 'offers', $offers ) ) {
			$offers['offers'] = self::route_offers( $offers['offers'], $ticket_url );
		}

		return $offers;
	}

	/**
	 * Recursively drop string values that are URLs on a non-Extra Chill host.
	 *
	 * @param array $node Schema node.
	 */
	private static function strip_offsite_urls( array $node ): array {
		$list = self::is_list( $node );
		foreach ( $node as $key => $value ) {
			if ( is_array( $value ) ) {
				$node[ $key ] = self::strip_offsite_urls( $value );
			} elseif ( is_string( $value ) && ! self::is_allowed_url( $value ) ) {
				unset( $node[ $key ] );
			}
		}

		return $list ? array_values( $node ) : $node;
	}

	/**
	 * Whether a string is either not a URL or a URL partners may publish.
	 *
	 * Allowed hosts: extrachill.com and its subdomains, the network's mapped
	 * domains, and schema.org (vocabulary IRIs such as eventStatus values are
	 * identifiers, not destinations).
	 *
	 * @param string $value Candidate value.
	 */
	private static function is_allowed_url( string $value ): bool {
		$value = trim( $value );
		if ( 1 !== preg_match( '#^(?:[a-z][a-z0-9+.-]*:)?//#i', $value ) ) {
			return true;
		}

		$host = strtolower( (string) wp_parse_url( $value, PHP_URL_HOST ) );
		if ( '' === $host ) {
			return false;
		}

		foreach ( array( 'extrachill.com', 'schema.org' ) as $domain ) {
			if ( $host === $domain || str_ends_with( $host, '.' . $domain ) ) {
				return true;
			}
		}

		$network = function_exists( 'ec_get_allowed_redirect_hosts' ) ? (array) ec_get_allowed_redirect_hosts() : array();
		return in_array( $host, array_map( 'strtolower', $network ), true );
	}

	/**
	 * Whether an array is a zero-indexed list.
	 *
	 * @param array $value Candidate array.
	 */
	private static function is_list( array $value ): bool {
		return array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	/**
	 * Ticket action. Non-null URLs always stay on Extra Chill.
	 *
	 * First-party checkout lives on the event page. Outside sellers are reached
	 * through the event page's ticket button, never a direct seller URL: the
	 * affiliate redirect route rejects cross-site navigations, so linking it
	 * from a partner site would dead-end.
	 *
	 * @param string     $url    Event page URL.
	 * @param string     $status Schema.org status name.
	 * @param array      $attrs  Hydrated event data.
	 * @param array|null $offer  Enabled first-party ticket offer.
	 */
	private static function ticket( string $url, string $status, array $attrs, ?array $offer ): array {
		if ( 'EventCancelled' === $status ) {
			return array(
				'url'   => null,
				'label' => 'Cancelled',
				'kind'  => 'none',
			);
		}

		if ( null !== $offer ) {
			if ( ! empty( $offer['sale_open'] ) ) {
				return array(
					'url'   => $url,
					'label' => 'Get Tickets',
					'kind'  => 'checkout',
				);
			}
			if ( 0 === ( $offer['remaining'] ?? null ) ) {
				return array(
					'url'   => null,
					'label' => 'Sold Out',
					'kind'  => 'none',
				);
			}
		} elseif ( '' !== trim( (string) ( $attrs['ticketUrl'] ?? '' ) ) ) {
			return array(
				'url'   => $url,
				'label' => 'Get Tickets',
				'kind'  => 'external',
			);
		}

		return array(
			'url'   => $url,
			'label' => 'Event Details',
			'kind'  => 'info',
		);
	}

	/**
	 * Performer names, preferring the emitted JSON-LD so both always agree.
	 *
	 * @param array|null $schema Event entity.
	 * @param array      $attrs  Hydrated event data.
	 * @return string[]
	 */
	private static function performers( ?array $schema, array $attrs ): array {
		$performers = $schema['performer'] ?? null;
		if ( is_array( $performers ) && isset( $performers['name'] ) ) {
			$performers = array( $performers );
		}

		$names = array();
		if ( is_array( $performers ) ) {
			foreach ( $performers as $performer ) {
				$names[] = is_array( $performer ) ? (string) ( $performer['name'] ?? '' ) : '';
			}
		} else {
			$names[] = (string) ( $attrs['performer'] ?? '' );
		}

		return array_values( array_unique( array_filter( array_map( array( self::class, 'text' ), $names ), static fn( string $name ): bool => '' !== $name ) ) );
	}

	/**
	 * Display price: authored price, else the first-party ticket price.
	 *
	 * @param array      $attrs Hydrated event data.
	 * @param array|null $offer Enabled first-party ticket offer.
	 */
	private static function price( array $attrs, ?array $offer ): ?string {
		$price = self::text( (string) ( $attrs['price'] ?? '' ) );
		if ( '' !== $price ) {
			return $price;
		}

		$minor = (int) ( $offer['price_minor'] ?? 0 );
		if ( $minor <= 0 ) {
			return null;
		}

		$amount   = number_format( $minor / 100, 0 === $minor % 100 ? 0 : 2 );
		$currency = strtoupper( (string) ( $offer['currency'] ?? '' ) );
		return 'USD' === $currency || '' === $currency ? '$' . $amount : $amount . ' ' . $currency;
	}

	/**
	 * Event image, matching the JSON-LD image when present.
	 *
	 * @param array|null $schema  Event entity.
	 * @param int        $post_id Event post ID.
	 */
	private static function image( ?array $schema, int $post_id ): ?string {
		$image = $schema['image'] ?? null;
		if ( is_array( $image ) ) {
			$image = $image[0] ?? ( $image['url'] ?? null );
		}
		if ( ! is_string( $image ) || '' === $image ) {
			$image = get_the_post_thumbnail_url( $post_id, 'full' );
		}

		return is_string( $image ) && '' !== $image && self::is_allowed_url( $image ) ? $image : null;
	}

	/**
	 * Schema.org status name, defaulting to scheduled.
	 *
	 * @param string $status Raw status (bare name or schema.org URL).
	 */
	private static function status( string $status ): string {
		$status = basename( trim( $status ) );
		return 1 === preg_match( '/^Event[A-Za-z]+$/', $status ) ? $status : 'EventScheduled';
	}

	/**
	 * IANA timezone for the venue, falling back to the site's.
	 *
	 * @param int $venue_id Venue term ID.
	 */
	private static function timezone( int $venue_id ): string {
		$data     = function_exists( 'data_machine_events_get_venue_data' ) ? data_machine_events_get_venue_data( $venue_id ) : null;
		$timezone = is_array( $data ) ? trim( (string) ( $data['timezone'] ?? '' ) ) : '';
		if ( '' !== $timezone && in_array( $timezone, \DateTimeZone::listIdentifiers(), true ) ) {
			return $timezone;
		}

		$site = function_exists( 'wp_timezone_string' ) ? (string) wp_timezone_string() : 'UTC';
		return '' === $site ? 'UTC' : $site;
	}

	/**
	 * Convert a venue-local `Y-m-d H:i:s` datetime to ISO 8601 with offset.
	 *
	 * @param string $local    Stored local datetime.
	 * @param string $timezone IANA timezone or offset.
	 */
	private static function iso( string $local, string $timezone ): string {
		if ( '' === trim( $local ) ) {
			return '';
		}

		try {
			return ( new \DateTimeImmutable( $local, new \DateTimeZone( $timezone ) ) )->format( 'c' );
		} catch ( \Exception $e ) {
			return '';
		}
	}

	/**
	 * Plain text: no tags, no entities, collapsed whitespace.
	 *
	 * @param string $value Raw value.
	 */
	private static function text( string $value ): string {
		$value = html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( (string) preg_replace( '/\s+/u', ' ', $value ) );
	}

	/**
	 * Execute a registered ability, or return null when absent or failing.
	 *
	 * Reads wp_get_abilities() rather than wp_get_ability(): an absent sibling
	 * ability (e.g. extrachill-seo inactive) is an expected degraded state, not
	 * a _doing_it_wrong().
	 *
	 * @param string $name  Ability name.
	 * @param array  $input Ability input.
	 * @return mixed|null
	 */
	private static function execute( string $name, array $input ) {
		$abilities = function_exists( 'wp_get_abilities' ) ? wp_get_abilities() : array();
		$ability   = $abilities[ $name ] ?? null;
		if ( null === $ability ) {
			return null;
		}

		$result = $ability->execute( $input );
		return is_wp_error( $result ) ? null : $result;
	}
}
