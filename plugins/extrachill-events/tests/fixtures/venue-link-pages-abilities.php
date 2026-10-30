<?php
/** Coordinated ability registration fixture. */

define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['venue_link_page_abilities'] = array();

function __( $text ) {
	return $text; }
function add_action( $hook, $callback ) {
	$GLOBALS['venue_link_page_ability_hook'] = array( $hook, $callback ); }
function wp_register_ability( $name, $args ) {
	$GLOBALS['venue_link_page_abilities'][ $name ] = $args; }

require_once dirname( __DIR__, 2 ) . '/inc/Abilities/VenueLinkPageAbilities.php';
$registrar = new ExtraChillEvents\Abilities\VenueLinkPageAbilities();
$registrar->register();

$schema_is_closed = static function ( $schema ) use ( &$schema_is_closed ) {
	if ( ! is_array( $schema ) ) {
		return true;
	}
	if ( 'object' === ( $schema['type'] ?? null ) && false !== ( $schema['additionalProperties'] ?? null ) ) {
		return false;
	}
	foreach ( array( 'properties', 'items', 'oneOf' ) as $child_key ) {
		foreach ( isset( $schema[ $child_key ] ) && is_array( $schema[ $child_key ] ) ? $schema[ $child_key ] : array() as $child ) {
			if ( ! $schema_is_closed( $child ) ) {
				return false;
			}
		}
	}
	return true;
};

$contracts = array();
foreach ( $GLOBALS['venue_link_page_abilities'] as $name => $args ) {
	$contracts[ $name ] = array(
		'input_closed'  => $schema_is_closed( $args['input_schema'] ),
		'output_closed' => $schema_is_closed( $args['output_schema'] ),
		'show_in_rest'  => true === $args['meta']['show_in_rest'],
	);
	if ( 'extrachill/save-venue-link-page-links' === $name ) {
		$sections = $args['input_schema']['properties']['links'];
		$section  = $sections['items'];
		$link     = $section['properties']['links']['items'];
		$contracts[ $name ]['limits'] = array(
			'sections'          => $sections['maxItems'],
			'links_per_section' => $section['properties']['links']['maxItems'],
			'id'                => $section['properties']['id']['maxLength'],
			'section_title'     => $section['properties']['section_title']['maxLength'],
			'link_text'         => $link['properties']['link_text']['maxLength'],
			'link_url'          => $link['properties']['link_url']['maxLength'],
			'expires_at'        => $link['properties']['expires_at']['maxLength'],
		);
	}
}
echo json_encode( $contracts );
