<?php
/**
 * Register Events-owned Data Machine settings.
 *
 * @package ExtraChillEvents
 */

defined( 'ABSPATH' ) || exit;

/** Handle boolean settings owned by Extra Chill Events. */
function extrachill_events_filter_datamachine_settings( array $filtered, array $input ): array {
	$settings     = $filtered['settings'] ?? array();
	$handled_keys = $filtered['handled_keys'] ?? array();

	foreach ( array( 'dme_qualify_digest_enabled', 'extrachill_local_scene_digest_enabled' ) as $key ) {
		if ( ! array_key_exists( $key, $input ) || ! is_bool( $input[ $key ] ) ) {
			continue;
		}

		$settings[ $key ] = $input[ $key ];
		$handled_keys[]   = $key;
	}

	$filtered['settings']     = $settings;
	$filtered['handled_keys'] = array_values( array_unique( $handled_keys ) );

	return $filtered;
}
add_filter( 'datamachine_update_settings', 'extrachill_events_filter_datamachine_settings', 10, 2 );
