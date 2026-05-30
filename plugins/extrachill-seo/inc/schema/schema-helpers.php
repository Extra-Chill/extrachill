<?php
/**
 * Shared Schema Builders + Registrar
 *
 * Reusable primitives for the schema layer:
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
