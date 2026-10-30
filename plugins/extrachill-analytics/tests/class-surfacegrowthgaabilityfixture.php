<?php
/**
 * GA ability fixture for Surface Growth tests.
 *
 * @package ExtraChill\Analytics
 */

/**
 * Minimal GA ability fixture that records requests.
 */
final class SurfaceGrowthGaAbilityFixture {
	/**
	 * Recorded ability requests.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public $requests = array();

	/**
	 * Whether traffic_sources requests should fail.
	 *
	 * @var bool
	 */
	public $fail_traffic = false;

	/**
	 * Return deterministic date and source rows.
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public function execute( $input ) {
		$this->requests[] = $input;
		if ( 'date_stats' === $input['action'] ) {
			return array(
				'success' => true,
				'results' => array_fill( 0, 7, array( 'sessions' => 10 ) ),
			);
		}
		if ( $this->fail_traffic ) {
			return new WP_Error( 'ga_failed', 'Traffic sources unavailable.' );
		}

		$is_current = gmdate( 'Y-m-d', strtotime( '-7 days' ) ) === $input['start_date'];
		return array(
			'success' => true,
			'results' => array(
				array(
					'sessions'      => $is_current ? 40 : 20,
					'sessionMedium' => 'organic',
				),
				array(
					'sessions'      => $is_current ? 60 : 30,
					'sessionMedium' => '(none)',
				),
			),
		);
	}
}
