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

ec_seo_migrate_indexnow_key();

function ec_seo_get_default_og_image_id() {
	return (int) get_site_option( EC_SEO_OPTION_DEFAULT_OG_IMAGE_ID, 0 );
}

function ec_seo_set_default_og_image_id( $attachment_id ) {
	update_site_option( EC_SEO_OPTION_DEFAULT_OG_IMAGE_ID, (int) $attachment_id );
}

function ec_seo_get_indexnow_key() {
	$settings = get_option( 'datamachine_settings', array() );

	return isset( $settings['indexnow_api_key'] ) ? (string) $settings['indexnow_api_key'] : '';
}

function ec_seo_set_indexnow_key( $key ) {
	$settings                         = get_option( 'datamachine_settings', array() );
	$settings['indexnow_api_key']     = (string) $key;
	$settings['indexnow_enabled']     = '' !== (string) $key;

	update_option( 'datamachine_settings', $settings );
	ec_seo_clear_datamachine_settings_cache();
}

function ec_seo_is_indexnow_enabled() {
	$settings = get_option( 'datamachine_settings', array() );

	return ! empty( $settings['indexnow_enabled'] );
}

function ec_seo_clear_datamachine_settings_cache() {
	if ( class_exists( '\DataMachine\Core\PluginSettings' ) ) {
		\DataMachine\Core\PluginSettings::clearCache();
	}
}

/**
 * Moves the retired network key into Data Machine's canonical site settings.
 */
function ec_seo_migrate_indexnow_key() {
	$legacy_key = (string) get_site_option( EC_SEO_OPTION_INDEXNOW_KEY, '' );
	if ( '' === $legacy_key ) {
		return;
	}

	$site_ids        = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
	$current_blog_id = get_current_blog_id();

	foreach ( $site_ids as $site_id ) {
		$switched = (int) $site_id !== $current_blog_id;
		if ( $switched ) {
			switch_to_blog( $site_id );
		}

		$settings = get_option( 'datamachine_settings', array() );
		if ( empty( $settings['indexnow_api_key'] ) ) {
			$settings['indexnow_api_key'] = $legacy_key;
			update_option( 'datamachine_settings', $settings );
			ec_seo_clear_datamachine_settings_cache();
		}

		if ( $switched ) {
			restore_current_blog();
		}
	}

	delete_site_option( EC_SEO_OPTION_INDEXNOW_KEY );
}
