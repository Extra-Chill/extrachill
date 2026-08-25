<?php

define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['promoter_link_page_abilities'] = array();

class WP_Error {
	private $code;
	public function __construct( $code ) {
		$this->code = $code;
	}
	public function get_error_code() {
		return $this->code;
	}
}

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
	if ( 'extrachill/save-promoter-link-page-links' === $name ) {
		$input_links                               = $args['input_schema']['properties']['links'];
		$input_section                             = $input_links['items'];
		$input_link                                = $input_section['properties']['links']['items'];
		$output_page                               = $args['output_schema']['properties']['link_page']['properties'];
		$contracts[ $name ]['limits']              = array(
			'sections'          => $input_links['maxItems'],
			'links_per_section' => $input_section['properties']['links']['maxItems'],
			'id'                => $input_section['properties']['id']['maxLength'],
			'section_title'     => $input_section['properties']['section_title']['maxLength'],
			'link_text'         => $input_link['properties']['link_text']['maxLength'],
			'link_url'          => $input_link['properties']['link_url']['maxLength'],
			'expires_at'        => $input_link['properties']['expires_at']['maxLength'],
			'output_links'      => $output_page['links']['maxItems'],
			'output_sections'   => $output_page['link_sections']['maxItems'],
		);
		$too_many                                  = array_fill( 0, ExtraChillEvents\Abilities\PromoterLinkPageAbilities::MAX_LINK_SECTIONS + 1, array( 'links' => array() ) );
		$rejected                                  = $registrar->save_links(
			array(
				'promoter_term_id' => 1,
				'links'            => $too_many,
			)
		);
		$contracts[ $name ]['direct_error']        = $rejected instanceof WP_Error ? $rejected->get_error_code() : '';
		$too_long                                  = array(
			array(
				'links' => array(
					array(
						'link_text' => str_repeat( 'x', ExtraChillEvents\Abilities\PromoterLinkPageAbilities::MAX_LINK_TEXT_LENGTH + 1 ),
						'link_url'  => 'https://example.com',
					),
				),
			),
		);
		$length_rejected                           = $registrar->save_links(
			array(
				'promoter_term_id' => 1,
				'links'            => $too_long,
			)
		);
		$contracts[ $name ]['direct_length_error'] = $length_rejected instanceof WP_Error ? $length_rejected->get_error_code() : '';
	}
}
echo json_encode( $contracts );
