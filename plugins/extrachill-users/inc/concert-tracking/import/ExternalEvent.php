<?php
/**
 * Normalized external event value object.
 *
 * Every ImportSource yields ExternalEvent instances; EventMatcher consumes
 * them. This is the shared shape across all adapters — no source-specific
 * shape leaks into the matcher or orchestrator.
 *
 * @package ExtraChill\Users\Concert_Import
 * @since 0.13.0
 */

namespace ExtraChill\Users\Concert_Import;

defined( 'ABSPATH' ) || exit;

/**
 * Value object representing a single attended event as reported by an
 * external source (setlist.fm, phish.net, etc.).
 */
final class ExternalEvent {

	/**
	 * Event date in YYYY-MM-DD (source-local — caller treats it as the
	 * calendar date the show happened on).
	 *
	 * @var string
	 */
	public string $date;

	/**
	 * Venue name as reported by the source.
	 *
	 * @var string
	 */
	public string $venue_name;

	/**
	 * Venue city (best-effort, may be empty).
	 *
	 * @var string
	 */
	public string $city;

	/**
	 * Venue state or region (best-effort, may be empty).
	 *
	 * @var string
	 */
	public string $state;

	/**
	 * Venue country (best-effort, may be empty).
	 *
	 * @var string
	 */
	public string $country;

	/**
	 * Headliner / primary artist name as reported by the source.
	 *
	 * @var string
	 */
	public string $headliner;

	/**
	 * Stable source-side identifier (e.g. setlist.fm setlist ID or
	 * phish.net showid). Used for logs + audit, not matching.
	 *
	 * @var string
	 */
	public string $source_id;

	/**
	 * Raw payload as returned by the source — kept around for debugging
	 * unmatched events.
	 *
	 * @var array<string, mixed>
	 */
	public array $raw;

	/**
	 * @param array{
	 *   date: string,
	 *   venue_name?: string,
	 *   city?: string,
	 *   state?: string,
	 *   country?: string,
	 *   headliner?: string,
	 *   source_id?: string,
	 *   raw?: array<string, mixed>,
	 * } $args
	 */
	public function __construct( array $args ) {
		$this->date       = (string) ( $args['date'] ?? '' );
		$this->venue_name = (string) ( $args['venue_name'] ?? '' );
		$this->city       = (string) ( $args['city'] ?? '' );
		$this->state      = (string) ( $args['state'] ?? '' );
		$this->country    = (string) ( $args['country'] ?? '' );
		$this->headliner  = (string) ( $args['headliner'] ?? '' );
		$this->source_id  = (string) ( $args['source_id'] ?? '' );
		$this->raw        = isset( $args['raw'] ) && is_array( $args['raw'] ) ? $args['raw'] : array();
	}

	/**
	 * Whether the event has enough data to attempt a match.
	 */
	public function is_matchable(): bool {
		return '' !== $this->date && '' !== $this->venue_name;
	}

	/**
	 * Render a short human-readable label for logs / unmatched reports.
	 */
	public function label(): string {
		$parts = array_filter(
			array(
				$this->date,
				$this->headliner,
				$this->venue_name,
				$this->city,
			),
			static function ( $v ) {
				return '' !== $v;
			}
		);
		return implode( ' · ', $parts );
	}
}
