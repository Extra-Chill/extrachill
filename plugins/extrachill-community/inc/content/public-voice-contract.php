<?php
/**
 * Ability schemas for accountable managed public voices.
 *
 * @package ExtraChillCommunity
 */

defined( 'ABSPATH' ) || exit;

/**
 * Return the bounded ability input schema for a public voice reference.
 *
 * @param bool $allow_clear Whether an empty string explicitly clears a voice.
 * @return array<string,mixed>
 */
function extrachill_community_public_voice_input_schema( $allow_clear = false ) {
	$canonical = array(
		'type'      => 'string',
		'pattern'   => '^(artist|venue):[1-9][0-9]*$',
		'maxLength' => 40,
	);

	if ( ! $allow_clear ) {
		$canonical['description'] = 'Optional canonical managed public voice, such as artist:123 or venue:55.';
		return $canonical;
	}

	return array(
		'oneOf'       => array(
			array(
				'type' => 'string',
				'enum' => array( '' ),
			),
			$canonical,
		),
		'description' => 'A canonical managed public voice, or an empty string to clear the current voice.',
	);
}

/**
 * Return the exact nullable public voice envelope emitted by write abilities.
 *
 * @return array<string,mixed>
 */
function extrachill_community_public_voice_output_schema() {
	return array(
		'oneOf' => array(
			array( 'type' => 'null' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'reference'           => array(
						'type'    => 'string',
						'pattern' => '^(artist|venue):[1-9][0-9]*$',
					),
					'type'                => array(
						'type' => 'string',
						'enum' => array( 'artist', 'venue' ),
					),
					'id'                  => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'name'                => array( 'type' => 'string' ),
					'url'                 => array(
						'type'   => 'string',
						'format' => 'uri',
					),
					'accountable_user_id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'automated'           => array( 'type' => 'boolean' ),
				),
				'required'             => array( 'reference', 'type', 'id', 'name', 'url', 'accountable_user_id', 'automated' ),
				'additionalProperties' => false,
			),
		),
	);
}
