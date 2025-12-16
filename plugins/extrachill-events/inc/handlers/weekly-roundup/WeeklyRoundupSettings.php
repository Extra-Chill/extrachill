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
			'week_start_day'   => array(
				'type'        => 'select',
				'label'       => \__( 'Week Start Day', 'extrachill-events' ),
				'description' => \__( 'First weekday to include in the roundup window', 'extrachill-events' ),
				'required'    => true,
				'options'     => self::get_weekday_options(),
				'default'     => $current_config['week_start_day'] ?? 'monday',
			),
			'week_end_day'     => array(
				'type'        => 'select',
				'label'       => \__( 'Week End Day', 'extrachill-events' ),
				'description' => \__( 'Last weekday to include in the roundup window', 'extrachill-events' ),
				'required'    => true,
				'options'     => self::get_weekday_options(),
				'default'     => $current_config['week_end_day'] ?? 'sunday',
			),
			'location_term_id' => array(
				'type'        => 'select',
				'label'       => \__( 'Location', 'extrachill-events' ),
				'description' => \__( 'Filter events by location (optional)', 'extrachill-events' ),
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
		$allowed_days = array_keys( self::get_weekday_options() );

		$start_day = \sanitize_text_field( $raw_settings['week_start_day'] ?? '' );
		$end_day   = \sanitize_text_field( $raw_settings['week_end_day'] ?? '' );

		return array(
			'week_start_day'   => in_array( $start_day, $allowed_days, true ) ? $start_day : '',
			'week_end_day'     => in_array( $end_day, $allowed_days, true ) ? $end_day : '',
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
			'week_start_day'   => 'monday',
			'week_end_day'     => 'sunday',
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
			'' => \__( 'All Locations', 'extrachill-events' ),
		);

		if ( ! \taxonomy_exists( 'location' ) ) {
			return $options;
		}

		if ( ! class_exists( '\\DataMachineEvents\\Blocks\\Calendar\\Calendar_Query' ) ) {
			return $options;
		}

		$query_args = \DataMachineEvents\Blocks\Calendar\Calendar_Query::build_query_args(
			array(
				'show_past' => false,
			)
		);

		$query_args['fields']                 = 'ids';
		$query_args['no_found_rows']          = true;
		$query_args['update_post_meta_cache'] = false;
		$query_args['update_post_term_cache'] = false;

		$query = new \WP_Query( $query_args );
		if ( empty( $query->posts ) ) {
			return $options;
		}

		$terms = \wp_get_object_terms(
			$query->posts,
			'location',
			array(
				'orderby' => 'name',
				'order'   => 'ASC',
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

	private static function get_weekday_options(): array {
		return array(
			'monday'    => \__( 'Monday', 'extrachill-events' ),
			'tuesday'   => \__( 'Tuesday', 'extrachill-events' ),
			'wednesday' => \__( 'Wednesday', 'extrachill-events' ),
			'thursday'  => \__( 'Thursday', 'extrachill-events' ),
			'friday'    => \__( 'Friday', 'extrachill-events' ),
			'saturday'  => \__( 'Saturday', 'extrachill-events' ),
			'sunday'    => \__( 'Sunday', 'extrachill-events' ),
		);
	}
}
