<?php
/**
 * Journey fixture mu-plugin: community-member-blocks.
 *
 * Each browser step ends by pinging this sink only after every assertion in
 * that step passed (a failed assertion ends the step first). grade.php turns
 * the recorded markers into graded cases, so a broken block is a finding
 * rather than an unjudged browser-step failure.
 *
 * @package ExtraChillNetwork
 */

add_action(
	'init',
	static function () {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- sandbox-only evidence sink.
		$reached = isset( $_GET['community_member_blocks_reached'] ) ? sanitize_key( wp_unslash( $_GET['community_member_blocks_reached'] ) ) : '';
		if ( '' === $reached ) {
			return;
		}
		$log             = get_site_option( 'ec_rig_community_member_blocks_reached', array() );
		$log[ $reached ] = time();
		update_site_option( 'ec_rig_community_member_blocks_reached', $log );
		status_header( 204 );
		exit;
	},
	0
);
