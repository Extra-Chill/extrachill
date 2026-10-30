<?php
/**
 * Tolerant admission for optional display preferences, with strict storage.
 *
 * @package ExtraChill\Analytics
 */

defined( 'ABSPATH' ) || exit;

/** Pageviews must survive malformed optional display preferences. */
class ExtraChill_Analytics_Page_View_Ability extends WP_Ability {
	/**
	 * Remove invalid preferences before Core validates (or REST coerces) input.
	 *
	 * @param mixed $input Raw input.
	 * @return mixed
	 */
	public function normalize_input( $input = null ) {
		if ( is_array( $input ) ) {
			$valid = extrachill_analytics_valid_display_preferences( $input );
			unset( $input['color_scheme'], $input['reduced_motion'], $input['contrast'] );
			$input = array_merge( $input, $valid );
		}
		return parent::normalize_input( $input );
	}

	/**
	 * REST also validates directly, before execute() normalizes the input.
	 *
	 * @param mixed $input Raw input.
	 * @return true|WP_Error
	 */
	public function validate_input( $input = null ) {
		return parent::validate_input( $this->normalize_input( $input ) );
	}
}
