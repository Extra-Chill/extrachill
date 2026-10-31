<?php
/**
 * Shared Schema Builders + Registrar + Value Normalizer
 *
 * Reusable primitives for the schema layer:
 *   - ec_seo_decode_schema_text(): decodes HTML entities in a single string
 *     value destined for JSON-LD.
 *   - ec_seo_normalize_schema_graph(): recursively normalizes every string
 *     value in a schema graph. Applied at the serialization boundary.
 *   - ec_seo_build_postal_address(): builds a schema.org PostalAddress entity
 *     from a normalized venue/address data array. Used by both the venue
 *     taxonomy emitter and the single-event location builder.
 *   - ec_seo_register_taxonomy_schema(): registers a taxonomy-archive schema
 *     emitter on the shared `extrachill_seo_schema_graph` filter, handling the
 *     identical skeleton (is_tax guard -> queried-object resolution -> append).
 *
 * @package ExtraChill\SEO
 */

namespace ExtraChill\SEO\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decode HTML entities in a single string value destined for JSON-LD.
 *
 * JSON-LD is parsed as JSON, not HTML: any entity that survives into a value
 * (`&#038;`, `&amp;`, `&#8211;`, ...) is indexed literally by search engines.
 * WordPress stores titles, term names, and generated copy entity-encoded, so
 * every text value must be decoded exactly once before serialization.
 *
 * Decoding happens ONLY at the serialization boundary (see
 * ec_seo_get_schema_graph() in inc/schema/schema-output.php). Individual
 * emitters must NOT decode their values — a second pass over an already
 * decoded string is usually harmless but is not guaranteed to be, and the
 * single boundary keeps that guarantee checkable in one place.
 *
 * @param string $text Raw string value.
 * @return string Entity-decoded string.
 */
function ec_seo_decode_schema_text( string $text ): string {
	if ( false === strpos( $text, '&' ) ) {
		return $text;
	}

	return html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
}

/**
 * Recursively decode HTML entities in every string value of a schema graph.
 *
 * Walks entities, nested arrays (location, offers, itemListElement, ...), and
 * string lists (sameAs, ...). Keys are structural property names and are
 * preserved as-is; non-string scalars (numbers, bools, null) pass through.
 * URL values are decoded too: entities in URLs are always accidental
 * encoding, and uniform decoding keeps `@id` reference integrity across the
 * graph intact.
 *
 * @param mixed $value Schema graph, entity, or nested value.
 * @return mixed Normalized value.
 */
function ec_seo_normalize_schema_values( $value ) {
	if ( is_string( $value ) ) {
		return ec_seo_decode_schema_text( $value );
	}

	if ( ! is_array( $value ) ) {
		return $value;
	}

	$normalized = array();
	foreach ( $value as $key => $child ) {
		$normalized[ $key ] = ec_seo_normalize_schema_values( $child );
	}

	return $normalized;
}

/**
 * Normalize a full schema graph at the serialization boundary.
 *
 * @param array $graph Schema graph (list of entities).
 * @return array Graph with every string value entity-decoded.
 */
function ec_seo_normalize_schema_graph( array $graph ): array {
	return ec_seo_normalize_schema_values( $graph );
}

/**
 * Build a schema.org PostalAddress entity from venue/address data.
 *
 * Only includes properties that are present (non-empty). When no address
 * fields are available, returns an empty array so callers can skip the
 * `address` property entirely.
 *
 * The `@type` key is appended last to preserve the historical key order of
 * the JSON-LD output (street/locality/region/postal/country, then @type).
 *
 * @param array $data {
 *     Normalized address fields. All optional.
 *
 *     @type string $street  Street address (streetAddress).
 *     @type string $city    Locality (addressLocality).
 *     @type string $state   Region (addressRegion).
 *     @type string $zip     Postal code (postalCode).
 *     @type string $country Country (addressCountry).
 * }
 * @return array PostalAddress entity, or empty array when no fields present.
 */
function ec_seo_build_postal_address( array $data ): array {
	$address = array();

	if ( ! empty( $data['street'] ) ) {
		$address['streetAddress'] = (string) $data['street'];
	}
	if ( ! empty( $data['city'] ) ) {
		$address['addressLocality'] = (string) $data['city'];
	}
	if ( ! empty( $data['state'] ) ) {
		$address['addressRegion'] = (string) $data['state'];
	}
	if ( ! empty( $data['zip'] ) ) {
		$address['postalCode'] = (string) $data['zip'];
	}
	if ( ! empty( $data['country'] ) ) {
		$address['addressCountry'] = (string) $data['country'];
	}

	if ( empty( $address ) ) {
		return array();
	}

	$address['@type'] = 'PostalAddress';

	return $address;
}

/**
 * Append a taxonomy-archive entity to the schema graph.
 *
 * Captures the skeleton repeated by every taxonomy-archive emitter:
 *   1. Bail unless we're on the target taxonomy archive (`is_tax( $taxonomy )`).
 *   2. Resolve the queried object and verify it is a WP_Term.
 *   3. Invoke the builder with the term; append a returned entity to the graph.
 *
 * The builder owns all entity-specific logic (data-source guards, entity
 * shape, conditional properties). Returning null/empty skips the entity, so
 * data-availability guards live in the builder, not here.
 *
 * Each taxonomy schema file calls this from its own NAMED emitter function
 * (hooked by name onto `extrachill_seo_schema_graph`), so the emitter stays
 * removable via `remove_filter()` while the skeleton lives in one place.
 *
 * @param array    $graph    Current schema graph.
 * @param string   $taxonomy Taxonomy slug to match (e.g. 'venue').
 * @param callable $data_fn  Builder: `function( \WP_Term $term ): ?array`.
 *                           Returns a schema entity array, or null/empty to skip.
 * @return array Graph, with the entity appended when applicable.
 */
function ec_seo_register_taxonomy_schema( array $graph, string $taxonomy, callable $data_fn ): array {
	if ( ! is_tax( $taxonomy ) ) {
		return $graph;
	}

	$term = get_queried_object();
	if ( ! ( $term instanceof \WP_Term ) ) {
		return $graph;
	}

	$entity = call_user_func( $data_fn, $term );
	if ( empty( $entity ) ) {
		return $graph;
	}

	$graph[] = $entity;

	return $graph;
}
