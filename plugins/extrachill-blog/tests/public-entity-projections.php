<?php
/** Focused coverage for the public Blog entity projection owner contract. */

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	public $code;

	public function __construct( $code = '' ) {
		$this->code = $code;
	}
}

class WP_Term {
	public $slug;
	public $name;
	public $count;

	public function __construct( $slug, $name, $count ) {
		$this->slug  = $slug;
		$this->name  = $name;
		$this->count = $count;
	}
}

$projection_ability         = array();
$projection_category        = array();
$projection_site_key        = 'main';
$projection_taxonomy_exists = true;
$projection_taxonomy_public = true;
$projection_terms           = array();
$projection_links           = array();

function add_action() {}
function __( $text ) { return $text; }
function __return_true() { return true; }
function wp_has_ability_category() { return false; }
function wp_register_ability_category( $name, $args ) {
	$GLOBALS['projection_category'] = compact( 'name', 'args' );
}
function wp_register_ability( $name, $args ) {
	$GLOBALS['projection_ability'] = compact( 'name', 'args' );
}
function ec_get_current_site_key() { return $GLOBALS['projection_site_key']; }
function get_current_blog_id() { return 1; }
function taxonomy_exists() { return $GLOBALS['projection_taxonomy_exists']; }
function get_taxonomy() {
	return $GLOBALS['projection_taxonomy_exists'] ? (object) array( 'public' => $GLOBALS['projection_taxonomy_public'] ) : false;
}
function get_term_by( $field, $slug, $taxonomy ) {
	return $GLOBALS['projection_terms'][ $slug ] ?? false;
}
function get_term_link( $term ) {
	return $GLOBALS['projection_links'][ $term->slug ] ?? new WP_Error( 'link_failed' );
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }

require dirname( __DIR__ ) . '/inc/core/public-entity-projections.php';

function projection_expect( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

extrachill_blog_register_public_projection_ability_category();
extrachill_blog_register_public_entity_projection_ability();

projection_expect( 'extrachill-blog' === $projection_category['name'], 'The owner category must be registered.' );
projection_expect( 'extrachill/blog-public-entity-projections' === $projection_ability['name'], 'The public owner ability name must remain stable.' );
projection_expect( '__return_true' === $projection_ability['args']['permission_callback'], 'The projection must be publicly readable.' );
projection_expect( true === $projection_ability['args']['meta']['show_in_rest'], 'The projection must use the public core ability runner.' );
projection_expect( true === $projection_ability['args']['meta']['annotations']['readonly'], 'The projection must remain read-only.' );

$input_schema = $projection_ability['args']['input_schema'];
$input_item   = $input_schema['properties']['items']['items'];
projection_expect( array( 'schema_version', 'items' ) === $input_schema['required'], 'Input must require the exact versioned envelope.' );
projection_expect( false === $input_schema['additionalProperties'], 'Input must reject extra envelope fields.' );
projection_expect( 1 === $input_schema['properties']['items']['minItems'], 'Input must require at least one item.' );
projection_expect( 100 === $input_schema['properties']['items']['maxItems'], 'Input must reject more than 100 items.' );
projection_expect( array( 'entity_type', 'slug' ) === $input_item['required'], 'Each request item must require its exact identity.' );
projection_expect( false === $input_item['additionalProperties'], 'Request items must reject extra fields.' );
projection_expect( array( 'festival' ) === $input_item['properties']['entity_type']['enum'], 'Only festival projections are supported.' );
projection_expect( 1 === $input_item['properties']['slug']['minLength'] && 200 === $input_item['properties']['slug']['maxLength'], 'Slug bounds must be exact.' );
projection_expect( '^[a-z0-9]+(?:-[a-z0-9]+)*$' === $input_item['properties']['slug']['pattern'], 'Slugs must already be canonical.' );

$output_schema = $projection_ability['args']['output_schema'];
$variants      = $output_schema['properties']['items']['items']['oneOf'];
$item_keys     = array( 'entity_type', 'slug', 'status', 'name', 'url' );
projection_expect( array( 'schema_version', 'items' ) === $output_schema['required'], 'Output must require the exact versioned envelope.' );
projection_expect( false === $output_schema['additionalProperties'], 'Output must reject extra envelope fields.' );
projection_expect( 1 === $output_schema['properties']['items']['minItems'], 'Output must preserve at least the required input item.' );
projection_expect( 100 === $output_schema['properties']['items']['maxItems'], 'Output must never exceed the batch bound.' );
projection_expect( $item_keys === $variants[0]['required'] && $item_keys === array_keys( $variants[0]['properties'] ), 'Resolved rows must expose exactly the required fields.' );
projection_expect( $item_keys === $variants[1]['required'] && $item_keys === array_keys( $variants[1]['properties'] ), 'Missing rows must expose exactly the required fields.' );
projection_expect( false === $variants[0]['additionalProperties'] && false === $variants[1]['additionalProperties'], 'Every output object must reject extra fields.' );
projection_expect( array( 'resolved' ) === $variants[0]['properties']['status']['enum'], 'Resolved status must be exact.' );
projection_expect( 1 === $variants[0]['properties']['name']['minLength'] && 'uri' === $variants[0]['properties']['url']['format'], 'Resolved presentation must be non-empty and canonical.' );
projection_expect( array( 'not_found' ) === $variants[1]['properties']['status']['enum'], 'Missing status must be exact.' );
projection_expect( 0 === $variants[1]['properties']['name']['maxLength'] && 0 === $variants[1]['properties']['url']['maxLength'], 'Missing presentation must not leak stale data.' );

$projection_terms = array(
	'public-festival'  => new WP_Term( 'public-festival', 'Public &amp; Loud', 3 ),
	'private-festival' => new WP_Term( 'private-festival', 'Private Festival', 0 ),
);
$projection_links = array(
	'public-festival' => 'https://extrachill.com/festival/public-festival/',
);
$result           = extrachill_blog_get_public_entity_projections(
	array(
		'schema_version' => '1',
		'items'          => array(
			array( 'entity_type' => 'festival', 'slug' => 'deleted-festival' ),
			array( 'entity_type' => 'festival', 'slug' => 'public-festival' ),
			array( 'entity_type' => 'festival', 'slug' => 'private-festival' ),
			array( 'entity_type' => 'festival', 'slug' => 'public-festival' ),
		),
	)
);

projection_expect( ! is_wp_error( $result ), 'Available owner infrastructure must return a projection.' );
projection_expect( array( 'schema_version', 'items' ) === array_keys( $result ), 'Runtime output must preserve the exact envelope.' );
projection_expect( array( 'deleted-festival', 'public-festival', 'private-festival', 'public-festival' ) === array_column( $result['items'], 'slug' ), 'Output must preserve every item and exact input order.' );
projection_expect( array( 'not_found', 'resolved', 'not_found', 'resolved' ) === array_column( $result['items'], 'status' ), 'Deleted and non-public terms must fail closed.' );
projection_expect( '' === $result['items'][0]['name'] && '' === $result['items'][0]['url'], 'Deleted terms must not expose stale presentation.' );
projection_expect( '' === $result['items'][2]['name'] && '' === $result['items'][2]['url'], 'Private terms must not expose presentation.' );
projection_expect( 'Public & Loud' === $result['items'][1]['name'], 'Resolved names must decode stored entities for display.' );
projection_expect( 'https://extrachill.com/festival/public-festival/' === $result['items'][1]['url'], 'Canonical URLs must come from the owner permalink API.' );
projection_expect( $item_keys === array_keys( $result['items'][1] ), 'Runtime rows must match the exact required schema.' );

$projection_taxonomy_public = false;
$hidden                     = extrachill_blog_get_public_entity_projections(
	array(
		'schema_version' => '1',
		'items'          => array( array( 'entity_type' => 'festival', 'slug' => 'public-festival' ) ),
	)
);
projection_expect( 'not_found' === $hidden['items'][0]['status'], 'A non-public owner taxonomy must fail closed.' );
projection_expect( '' === $hidden['items'][0]['name'] && '' === $hidden['items'][0]['url'], 'A non-public taxonomy must not leak presentation.' );
$projection_taxonomy_public = true;

$projection_links['public-festival'] = new WP_Error( 'link_failed' );
$link_error                          = extrachill_blog_get_public_entity_projections(
	array(
		'schema_version' => '1',
		'items'          => array( array( 'entity_type' => 'festival', 'slug' => 'public-festival' ) ),
	)
);
projection_expect( is_wp_error( $link_error ) && 'link_failed' === $link_error->code, 'Canonical permalink failures must remain owner errors.' );
$projection_links['public-festival'] = 'https://extrachill.com/festival/public-festival/';

$projection_taxonomy_exists = false;
$unavailable                = extrachill_blog_get_public_entity_projections(
	array(
		'schema_version' => '1',
		'items'          => array( array( 'entity_type' => 'festival', 'slug' => 'public-festival' ) ),
	)
);
projection_expect( is_wp_error( $unavailable ) && 'extrachill_blog_projection_taxonomy_unavailable' === $unavailable->code, 'Missing owner taxonomy infrastructure must remain an error.' );
$projection_taxonomy_exists = true;

$projection_site_key = 'community';
$wrong_owner         = extrachill_blog_get_public_entity_projections(
	array(
		'schema_version' => '1',
		'items'          => array( array( 'entity_type' => 'festival', 'slug' => 'public-festival' ) ),
	)
);
projection_expect( is_wp_error( $wrong_owner ) && 'extrachill_blog_projection_owner_unavailable' === $wrong_owner->code, 'Wrong-site execution must remain an owner availability error.' );

print "Public Blog entity projection tests passed.\n";
