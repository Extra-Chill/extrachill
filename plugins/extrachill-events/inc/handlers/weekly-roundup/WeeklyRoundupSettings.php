<?php
/**
 * Weekly Roundup Handler Settings
 *
 * Configuration fields for the weekly roundup image generator.
 *
 * @package ExtraChillEvents\Handlers\WeeklyRoundup
 */

namespace ExtraChillEvents\Handlers\WeeklyRoundup;

use DataMachine\Core\Steps\Settings\SettingsHandler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WeeklyRoundupSettings extends SettingsHandler {

	/**
	 * Get handler configuration fields.
	 *
	 * @return array Field definitions
	 */
	public static function get_fields(): array {
		return array(
			'week_start_day'   => array(
				'type'        => 'select',
				'label'       => \__( 'Week Start Day', 'extrachill-events' ),
				'description' => \__( 'First weekday to include in the roundup window', 'extrachill-events' ),
				'required'    => true,
				'options'     => self::get_weekday_options(),
				'default'     => 'monday',
			),
			'week_end_day'     => array(
				'type'        => 'select',
				'label'       => \__( 'Week End Day', 'extrachill-events' ),
				'description' => \__( 'Last weekday to include in the roundup window', 'extrachill-events' ),
				'required'    => true,
				'options'     => self::get_weekday_options(),
				'default'     => 'sunday',
			),
			'location_term_id' => array(
				'type'        => 'select',
				'label'       => \__( 'Location', 'extrachill-events' ),
				'description' => \__( 'Filter events by location (optional)', 'extrachill-events' ),
				'required'    => false,
				'options'     => self::get_location_options(),
				'default'     => '',
			),
			'title'            => array(
				'type'        => 'text',
				'label'       => \__( 'Roundup Title', 'extrachill-events' ),
				'description' => \__( 'Title displayed at top of first slide (e.g., "Charleston Weekend Roundup")', 'extrachill-events' ),
				'required'    => false,
				'default'     => '',
			),
		);
	}

	/**
	 * Check if handler requires authentication.
	 *
	 * @return bool
	 */
	public static function requires_authentication(): bool {
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
