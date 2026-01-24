<?php
/**
 * Config Ability Callbacks
 *
 * Execute callbacks for SEO configuration abilities.
 *
 * @package ExtraChill\SEO\Abilities
 */

namespace ExtraChill\SEO\Abilities;

use function ExtraChill\SEO\Core\ec_seo_get_default_og_image_id;
use function ExtraChill\SEO\Core\ec_seo_set_default_og_image_id;
use function ExtraChill\SEO\Core\ec_seo_get_indexnow_key;
use function ExtraChill\SEO\Core\ec_seo_set_indexnow_key;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Execute callback for get-seo-config ability.
 *
 * @param array $input Input parameters (unused).
 * @return array Configuration data.
 */
function extrachill_seo_ability_get_config( $input = array() ) {
	$og_image_id  = ec_seo_get_default_og_image_id();
	$og_image_url = '';

	if ( $og_image_id > 0 ) {
		$og_image_url = wp_get_attachment_url( $og_image_id );
		if ( ! $og_image_url ) {
			$og_image_url = '';
		}
	}

	$indexnow_key = ec_seo_get_indexnow_key();

	return array(
		'default_og_image_id'  => $og_image_id,
		'default_og_image_url' => $og_image_url,
		'indexnow_key'         => $indexnow_key,
		'indexnow_enabled'     => ! empty( $indexnow_key ),
	);
}

/**
 * Execute callback for update-seo-config ability.
 *
 * @param array $input Input parameters.
 * @return array Update result.
 */
function extrachill_seo_ability_update_config( $input = array() ) {
	$updated = array();

	if ( isset( $input['default_og_image_id'] ) ) {
		$og_image_id = (int) $input['default_og_image_id'];
		ec_seo_set_default_og_image_id( $og_image_id );
		$updated[] = 'default_og_image_id';
	}

	if ( isset( $input['indexnow_key'] ) ) {
		$indexnow_key = sanitize_text_field( $input['indexnow_key'] );
		ec_seo_set_indexnow_key( $indexnow_key );
		$updated[] = 'indexnow_key';
	}

	$config = extrachill_seo_ability_get_config();

	return array(
		'success' => true,
		'updated' => $updated,
		'config'  => $config,
	);
}
