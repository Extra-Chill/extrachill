<?php
/**
 * Roundup Publish Handler Settings
 *
 * Minimal configuration for the roundup image publish handler.
 *
 * @package ExtraChillEvents\Handlers\WeeklyRoundup
 */

namespace ExtraChillEvents\Handlers\WeeklyRoundup;

use DataMachine\Core\Steps\Publish\Handlers\PublishHandlerSettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RoundupPublishSettings extends PublishHandlerSettings {

	/**
	 * Get handler configuration fields.
	 *
	 * @param array $current_config Current configuration values.
	 * @return array Field definitions.
	 */
	public static function get_fields( array $current_config = array() ): array {
		return array(
			'post_status' => array(
				'type'        => 'select',
				'label'       => __( 'Post Status', 'extrachill-events' ),
				'description' => __( 'Status for the created post', 'extrachill-events' ),
				'required'    => true,
				'options'     => array(
					'draft'   => __( 'Draft', 'extrachill-events' ),
					'publish' => __( 'Published', 'extrachill-events' ),
					'pending' => __( 'Pending Review', 'extrachill-events' ),
				),
				'default'     => $current_config['post_status'] ?? 'draft',
			),
		);
	}

	/**
	 * Sanitize handler settings.
	 *
	 * @param array $raw_settings Raw settings from form.
	 * @return array Sanitized settings.
	 */
	public static function sanitize( array $raw_settings ): array {
		$allowed_statuses = array( 'draft', 'publish', 'pending' );
		$status           = \sanitize_text_field( $raw_settings['post_status'] ?? 'draft' );

		return array(
			'post_status' => in_array( $status, $allowed_statuses, true ) ? $status : 'draft',
		);
	}

	/**
	 * Get default settings.
	 *
	 * @return array Default values.
	 */
	public static function get_defaults(): array {
		return array(
			'post_status' => 'draft',
		);
	}

	/**
	 * Check if handler requires authentication.
	 *
	 * @param array $current_config Current configuration.
	 * @return bool
	 */
	public static function requires_authentication( array $current_config = array() ): bool {
		return false;
	}
}
