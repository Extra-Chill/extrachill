<?php
/**
 * Format-aware archive titles for event_type (calendar format) archives.
 *
 * The events site was built as a live-music calendar, so every archive title
 * says "Live Music". Comedy, karaoke, trivia and open mic are first-class
 * calendar formats now (#1816), so an event_type archive — or a location
 * archive filtered to one format via `?event_type=<slug>` — titles itself by
 * format instead.
 *
 * Music wording is decided by the term's `_schema_type` meta (stamped by
 * data-machine-events from the vocabulary in event-type-vocabulary.php), with
 * the vocabulary entry as the fallback when the meta is missing. A term whose
 * schema type is `MusicEvent` keeps the existing "Live Music" wording unless
 * its vocabulary entry declares otherwise below.
 *
 * Every title string (H1, document title, breadcrumb) resolves through
 * {@see extrachill_events_event_type_title_profile()}, so the three agree.
 *
 * @package ExtraChillEvents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Editorial title overrides keyed by event_type vocabulary slug.
 *
 * Only formats whose wording differs from the schema-derived default appear
 * here. Keys:
 * - `music`         (bool)   Override the `_schema_type === MusicEvent` rule.
 * - `archive_title` (string) H1/document/breadcrumb title of the format archive.
 * - `location_noun` (string) Noun used in "<noun> in <City>" titles.
 *
 * Open Mic is stamped `MusicEvent` for JSON-LD, but open mics mix music,
 * comedy and spoken word, so archive wording treats it as a non-music format.
 *
 * @return array<string,array<string,mixed>>
 */
function extrachill_events_event_type_title_overrides(): array {
	return array(
		'comedy'   => array(
			'archive_title' => 'Comedy Shows',
			'location_noun' => 'Comedy',
		),
		'open-mic' => array(
			'music'         => false,
			'archive_title' => 'Open Mic Nights',
			'location_noun' => 'Open Mics',
		),
		'other'    => array(
			'archive_title' => 'Other Events',
			'location_noun' => 'Other Events',
		),
	);
}

/**
 * Resolve the Schema.org type for an event_type term.
 *
 * Reads the `_schema_type` term meta first, then falls back to the Extra Chill
 * vocabulary entry for the slug. Returns '' when neither is known.
 *
 * @param int    $term_id Term ID (0 when unknown).
 * @param string $slug    Term slug.
 * @return string
 */
function extrachill_events_event_type_schema_type( int $term_id, string $slug ): string {
	if ( $term_id > 0 && function_exists( 'get_term_meta' ) ) {
		$meta = trim( (string) get_term_meta( $term_id, '_schema_type', true ) );
		if ( '' !== $meta ) {
			return $meta;
		}
	}

	if ( function_exists( 'extrachill_events_event_type_vocabulary' ) ) {
		foreach ( extrachill_events_event_type_vocabulary( array() ) as $entry ) {
			if ( isset( $entry['slug'] ) && $entry['slug'] === $slug ) {
				return (string) ( $entry['schema_type'] ?? '' );
			}
		}
	}

	return '';
}

/**
 * Build the title profile for an event_type format.
 *
 * @param string $name        Term display name (e.g. "Comedy").
 * @param string $slug        Term slug (e.g. "comedy").
 * @param string $schema_type Schema.org type (e.g. "ComedyEvent").
 * @return array{music:bool,archive_title:string,location_noun:string}
 */
function extrachill_events_event_type_title_profile( string $name, string $slug, string $schema_type ): array {
	$overrides = extrachill_events_event_type_title_overrides();
	$override  = $overrides[ $slug ] ?? array();

	$music = array_key_exists( 'music', $override )
		? (bool) $override['music']
		: 'MusicEvent' === $schema_type;

	if ( $music ) {
		return array(
			'music'         => true,
			'archive_title' => $name . ' Live Music Calendar',
			'location_noun' => 'Live Music',
		);
	}

	return array(
		'music'         => false,
		'archive_title' => (string) ( $override['archive_title'] ?? $name . ' Events' ),
		'location_noun' => (string) ( $override['location_noun'] ?? $name ),
	);
}

/**
 * Title profile for an event_type term object.
 *
 * @param object $term Term with `term_id`, `name`, `slug`.
 * @return array{music:bool,archive_title:string,location_noun:string}
 */
function extrachill_events_event_type_term_title_profile( $term ): array {
	$term_id = (int) ( $term->term_id ?? 0 );
	$slug    = (string) ( $term->slug ?? '' );
	$name    = (string) ( $term->name ?? '' );

	return extrachill_events_event_type_title_profile(
		$name,
		$slug,
		extrachill_events_event_type_schema_type( $term_id, $slug )
	);
}

/**
 * Title for a location archive, optionally filtered to one event_type.
 *
 * Unfiltered (or music-filtered) archives keep "Live Music in <City>";
 * a non-music filter yields "<Format> in <City>", e.g. "Comedy in Charleston".
 *
 * @param string      $city            City display name.
 * @param object|null $event_type_term Active single event_type filter term, or null.
 * @return string
 */
function extrachill_events_location_archive_title( string $city, $event_type_term = null ): string {
	$noun = 'Live Music';
	if ( is_object( $event_type_term ) ) {
		$noun = extrachill_events_event_type_term_title_profile( $event_type_term )['location_noun'];
	}

	return sprintf( '%s in %s', $noun, $city );
}

/**
 * The single event_type term a location archive is filtered to, if any.
 *
 * Reads the public `event_type` query var (the taxonomy registers
 * `query_var => true`, so `?event_type=comedy` lands there). Multiple
 * formats (comma/plus separated or array) are not a single format and
 * return null, keeping the generic "Live Music" title.
 *
 * @return object|null Term object, or null when unfiltered / ambiguous.
 */
function extrachill_events_get_single_event_type_filter() {
	$raw = function_exists( 'get_query_var' ) ? get_query_var( 'event_type' ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only public archive filter.
	if ( ( '' === $raw || null === $raw ) && isset( $_GET['event_type'] ) ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below per value.
		$raw = wp_unslash( $_GET['event_type'] );
	}

	if ( is_array( $raw ) ) {
		if ( 1 !== count( $raw ) ) {
			return null;
		}
		$raw = reset( $raw );
	}

	$raw = trim( (string) $raw );
	if ( '' === $raw || false !== strpbrk( $raw, ',+ ' ) ) {
		return null;
	}

	$slug = sanitize_title( $raw );
	if ( '' === $slug || ! function_exists( 'get_term_by' ) ) {
		return null;
	}

	$term = get_term_by( 'slug', $slug, 'event_type' );

	return is_object( $term ) ? $term : null;
}

/**
 * Document title for event_type archives on the events site.
 *
 * @hook document_title_parts
 * @param array $title_parts Document title parts.
 * @return array
 */
function extrachill_events_event_type_document_title( array $title_parts ): array {
	if ( ! function_exists( 'is_tax' ) || ! is_tax( 'event_type' ) ) {
		return $title_parts;
	}
	if ( function_exists( 'ec_is_events_site' ) && ! ec_is_events_site() ) {
		return $title_parts;
	}

	$term = get_queried_object();
	if ( is_object( $term ) && isset( $term->name ) ) {
		$title_parts['title'] = extrachill_events_event_type_term_title_profile( $term )['archive_title'];
	}

	return $title_parts;
}

/**
 * Register event_type title hooks.
 */
function extrachill_events_init_event_type_titles() {
	add_filter( 'document_title_parts', 'extrachill_events_event_type_document_title', 1000 );
}
