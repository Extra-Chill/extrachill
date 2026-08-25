<?php

define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['promoter_link_page_abilities'] = array();

function __( $text ) {
	return $text; }
function add_action( $hook, $callback ) {
	$GLOBALS['promoter_link_page_ability_hook'] = array( $hook, $callback ); }
function wp_register_ability( $name, $args ) {
	$GLOBALS['promoter_link_page_abilities'][ $name ] = $args; }

require_once dirname( __DIR__, 2 ) . '/inc/Abilities/PromoterLinkPageAbilities.php';
$registrar = new ExtraChillEvents\Abilities\PromoterLinkPageAbilities();
$registrar->register();

$closed = static function ( $schema ) use ( &$closed ) {
	if ( ! is_array( $schema ) ) {
		return true;
	}
	if ( 'object' === ( $schema['type'] ?? null ) && false !== ( $schema['additionalProperties'] ?? null ) ) {
		return false;
	}
	foreach ( array( 'properties', 'items', 'oneOf' ) as $key ) {
		foreach ( is_array( $schema[ $key ] ?? null ) ? $schema[ $key ] : array() as $child ) {
			if ( ! $closed( $child ) ) {
				return false;
			}
		}
	}
	return true;
};

$contracts = array();
foreach ( $GLOBALS['promoter_link_page_abilities'] as $name => $args ) {
	$contracts[ $name ] = array(
		'input_closed'  => $closed( $args['input_schema'] ),
		'output_closed' => $closed( $args['output_schema'] ),
		'show_in_rest'  => true === $args['meta']['show_in_rest'],
	);
}
echo json_encode( $contracts );
