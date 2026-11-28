<?php
/**
 * Journey fixture mu-plugin: forum-activity-fuzz.
 *
 * Evidence sinks for the exploratory forum journey. Browser steps POST
 * either a "reached" marker (all of a task's interactions completed) or a
 * JSON page observation (layout overflow, broken images, error notices, PHP
 * noise, editor shape). grade.php prints everything it collected.
 *
 * @package ExtraChillNetwork
 */

add_action(
	'init',
	static function () {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- sandbox-only evidence sink.
		if ( isset( $_GET['forum_fuzz_reached'] ) ) {
			$id              = sanitize_key( wp_unslash( $_GET['forum_fuzz_reached'] ) );
			$log             = (array) get_site_option( 'ec_rig_forum_fuzz_reached', array() );
			$log[ $id ]      = time();
			update_site_option( 'ec_rig_forum_fuzz_reached', $log );
			status_header( 204 );
			exit;
		}
		if ( isset( $_GET['forum_fuzz_observe'] ) ) {
			$id   = sanitize_text_field( wp_unslash( $_GET['forum_fuzz_observe'] ) );
			$body = json_decode( (string) file_get_contents( 'php://input' ), true );
			$log  = (array) get_site_option( 'ec_rig_forum_fuzz_observations', array() );
			$log[ $id ] = is_array( $body ) ? $body : array( 'unparseable' => true );
			update_site_option( 'ec_rig_forum_fuzz_observations', $log );
			status_header( 204 );
			exit;
		}
		// phpcs:enable
	},
	0
);
