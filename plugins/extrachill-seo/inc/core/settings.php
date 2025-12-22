<?php
/**
 * Network Settings
 *
 * Stores and retrieves Extra Chill SEO network settings (site options).
 *
 * @package ExtraChill\SEO
 */

namespace ExtraChill\SEO\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EC_SEO_OPTION_DEFAULT_OG_IMAGE_ID = 'extrachill_seo_default_og_image_id';
const EC_SEO_OPTION_INDEXNOW_KEY        = 'extrachill_seo_indexnow_key';

function ec_seo_get_default_og_image_id() {
	return (int) get_site_option( EC_SEO_OPTION_DEFAULT_OG_IMAGE_ID, 0 );
}

function ec_seo_set_default_og_image_id( $attachment_id ) {
	update_site_option( EC_SEO_OPTION_DEFAULT_OG_IMAGE_ID, (int) $attachment_id );
}

function ec_seo_get_indexnow_key() {
	return (string) get_site_option( EC_SEO_OPTION_INDEXNOW_KEY, '' );
}

function ec_seo_set_indexnow_key( $key ) {
	update_site_option( EC_SEO_OPTION_INDEXNOW_KEY, (string) $key );
}
