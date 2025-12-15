<?php
/**
 * Weekly Roundup Handler Settings
 *
 * Configuration fields for the weekly roundup image generator.
 *
 * @package ExtraChillEvents\Handlers\WeeklyRoundup
 */

namespace ExtraChillEvents\Handlers\WeeklyRoundup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WeeklyRoundupSettings {

	/**
	 * Get handler configuration fields.
	 *
	 * @param array $current_config Current configuration values
	 * @return array Field definitions
	 */
	public static function get_fields( array $current_config = array() ): array {
		return array(
			'date_range_start' => array(
				'type'        => 'date',
				'label'       => __( 'Start Date', 'extrachill-events' ),
				'description' => __( 'First day of the roundup period', 'extrachill-events' ),
				'required'    => true,
				'default'     => $current_config['date_range_start'] ?? '',
			),
			'date_range_end'   => array(
				'type'        => 'date',
				'label'       => __( 'End Date', 'extrachill-events' ),
				'description' => __( 'Last day of the roundup period', 'extrachill-events' ),
				'required'    => true,
				'default'     => $current_config['date_range_end'] ?? '',
			),
			'location_term_id' => array(
				'type'        => 'select',
				'label'       => __( 'Location', 'extrachill-events' ),
				'description' => __( 'Filter events by location (optional)', 'extrachill-events' ),
				'required'    => false,
				'options'     => self::get_location_options(),
				'default'     => $current_config['location_term_id'] ?? '',
			),
		);
	}

	/**
	 * Sanitize handler settings.
	 *
	 * @param array $raw_settings Raw settings from form
	 * @return array Sanitized settings
	 */
	public static function sanitize( array $raw_settings ): array {
		return array(
			'date_range_start' => \sanitize_text_field( $raw_settings['date_range_start'] ?? '' ),
			'date_range_end'   => \sanitize_text_field( $raw_settings['date_range_end'] ?? '' ),
			'location_term_id' => \absint( $raw_settings['location_term_id'] ?? 0 ),
		);
	}

	/**
	 * Get default settings.
	 *
	 * @return array Default values
	 */
	public static function get_defaults(): array {
		return array(
			'date_range_start' => '',
			'date_range_end'   => '',
			'location_term_id' => 0,
		);
	}

	/**
	 * Check if handler requires authentication.
	 *
	 * @param array $current_config Current configuration
	 * @return bool
	 */
	public static function requires_authentication( array $current_config = array() ): bool {
		return false;
	}

	/**
	 * Build location taxonomy dropdown options.
	 *
	 * @return array Options array for select field
	 */
	private static function get_location_options(): array {
		$options = array(
			'' => __( 'All Locations', 'extrachill-events' ),
		);

		if ( ! \taxonomy_exists( 'location' ) ) {
			return $options;
		}

		$terms = \get_terms(
			array(
				'taxonomy'   => 'location',
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		if ( \is_wp_error( $terms ) || empty( $terms ) ) {
			return $options;
		}

		foreach ( $terms as $term ) {
			$options[ $term->term_id ] = $term->name;
		}

		return $options;
	}
}
