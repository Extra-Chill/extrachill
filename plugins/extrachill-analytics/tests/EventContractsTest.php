<?php
/**
 * Drift tests for canonical analytics event contracts.
 *
 * @package ExtraChill\Analytics
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/inc/core/event-types.php';
require_once dirname( __DIR__ ) . '/inc/core/assets.php';
require_once dirname( __DIR__ ) . '/inc/core/experiment-reporting.php';
require_once dirname( __DIR__ ) . '/inc/core/experiment-integration.php';
require_once dirname( __DIR__ ) . '/inc/core/write-integrity.php';
require_once dirname( __DIR__ ) . '/inc/core/abilities.php';

/**
 * Keep active emitters and report readers on canonical names.
 */
final class EventContractsTest extends TestCase {
	/**
	 * Establish identified, privacy-eligible experiment fixtures.
	 */
	protected function setUp(): void {
		$_COOKIE[ EXTRACHILL_ANALYTICS_VISITOR_COOKIE ] = '123e4567-e89b-42d3-a456-426614174000';
		unset( $_SERVER['HTTP_SEC_GPC'], $_SERVER['HTTP_DNT'] );
		$GLOBALS['extrachill_analytics_test_events'] = array();
	}

	/**
	 * Restore privacy and identity fixtures.
	 */
	protected function tearDown(): void {
		unset( $_COOKIE[ EXTRACHILL_ANALYTICS_VISITOR_COOKIE ], $_SERVER['HTTP_SEC_GPC'], $_SERVER['HTTP_DNT'] );
	}

	/**
	 * Active cross-plugin and report-critical names remain exact.
	 */
	public function test_canonical_active_event_names(): void {
		$this->assertSame( 'user_registration', EC_ANALYTICS_EVENT_USER_REGISTRATION );
		$this->assertSame( 'newsletter_signup', EC_ANALYTICS_EVENT_NEWSLETTER_SIGNUP );
		$this->assertSame( 'search', EC_ANALYTICS_EVENT_SEARCH );
		$this->assertSame( 'search_attack', EC_ANALYTICS_EVENT_SEARCH_ATTACK );
		$this->assertSame( 'share_click', EC_ANALYTICS_EVENT_SHARE_CLICK );
		$this->assertSame( 'bridge_click', EC_ANALYTICS_EVENT_BRIDGE_CLICK );
		$this->assertSame( 'bridge_impression', EC_ANALYTICS_EVENT_BRIDGE_IMPRESSION );
		$this->assertSame( 'outbound_click', EC_ANALYTICS_EVENT_OUTBOUND_CLICK );
		$this->assertSame( '404_error', EC_ANALYTICS_EVENT_404_ERROR );
		$this->assertSame( 'email_sent', EC_ANALYTICS_EVENT_EMAIL_SENT );
		$this->assertSame( 'email_failed', EC_ANALYTICS_EVENT_EMAIL_FAILED );
		$this->assertSame( 'redirect_fire', EC_ANALYTICS_EVENT_REDIRECT_FIRE );
		$this->assertSame( 'experiment_assignment', EC_ANALYTICS_EVENT_EXPERIMENT_ASSIGNMENT );
		$this->assertSame( 'experiment_exposure', EC_ANALYTICS_EVENT_EXPERIMENT_EXPOSURE );
		$this->assertNotSame( EC_ANALYTICS_EVENT_EXPERIMENT_ASSIGNMENT, EC_ANALYTICS_EVENT_EXPERIMENT_EXPOSURE );
		$this->assertSame( 'geo-bridge-holdout', EC_ANALYTICS_EXPERIMENT_GEO_BRIDGE_HOLDOUT );
		$this->assertSame( 'single-post-bridge', EC_ANALYTICS_EXPERIMENT_SURFACE_SINGLE_POST_BRIDGE );
	}

	/**
	 * Experiment events never enter the generic public browser event boundary.
	 */
	public function test_experiment_events_are_not_public_browser_events(): void {
		$this->assertNotContains( EC_ANALYTICS_EVENT_EXPERIMENT_ASSIGNMENT, extrachill_analytics_public_browser_event_types() );
		$this->assertNotContains( EC_ANALYTICS_EVENT_EXPERIMENT_EXPOSURE, extrachill_analytics_public_browser_event_types() );
		$result = extrachill_analytics_ability_track_event(
			array(
				'event_type' => EC_ANALYTICS_EVENT_EXPERIMENT_ASSIGNMENT,
				'event_data' => array(
					'experiment_key'     => 'copy-test',
					'definition_version' => 1,
					'assignment_policy'  => 'weighted_random',
					'variant'            => 'control',
					'surface'            => 'hero',
				),
			)
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'protected_event_type', $result->code );
	}

	/**
	 * The flexible event ability remains internal and absent from public REST.
	 */
	public function test_flexible_event_ability_remains_private(): void {
		$GLOBALS['extrachill_analytics_registered_abilities'] = array();
		extrachill_analytics_register_abilities();

		$ability = $GLOBALS['extrachill_analytics_registered_abilities']['extrachill/track-analytics-event'];
		$this->assertFalse( $ability['meta']['show_in_rest'] );
		$this->assertSame( '__return_true', $ability['permission_callback'] );
	}

	/**
	 * Analytics contributes the existing read-only visitor subject.
	 */
	public function test_subject_provider_preserves_authenticated_subject_and_falls_back_to_ec_vid(): void {
		$this->assertSame( 'wp-user:42', extrachill_analytics_experiment_subject_key( 'wp-user:42' ) );
		$this->assertSame(
			'ec-vid:123e4567-e89b-42d3-a456-426614174000',
			extrachill_analytics_experiment_subject_key( '' )
		);
	}

	/**
	 * Analytics' provider denies measurement under either established opt-out.
	 *
	 * @dataProvider privacy_header_provider
	 *
	 * @param string $header Privacy header.
	 */
	public function test_measurement_provider_honors_privacy_headers( $header ): void {
		$this->assertTrue( extrachill_analytics_experiment_measurement_eligible( false ) );
		$_SERVER[ $header ] = '1';
		$this->assertFalse( extrachill_analytics_experiment_measurement_eligible( true ) );
		$this->assertSame( '', extrachill_analytics_experiment_subject_key( '' ) );
	}

	/**
	 * Supported privacy headers.
	 *
	 * @return array<string,array{string}>
	 */
	public function privacy_header_provider() {
		return array(
			'global privacy control' => array( 'HTTP_SEC_GPC' ),
			'do not track'           => array( 'HTTP_DNT' ),
		);
	}

	/**
	 * Network hooks persist distinct canonical events with exact bounded fields.
	 */
	public function test_network_hooks_persist_exact_assignment_and_exposure_payloads(): void {
		extrachill_analytics_record_experiment_assignment(
			array(
				'experiment_key'     => 'geo-bridge-holdout',
				'definition_version' => 1,
				'assignment_policy'  => 'weighted_random',
				'variant'            => 'control',
				'surface'            => 'single-post-bridge',
			)
		);
		extrachill_analytics_record_experiment_exposure(
			array(
				'experiment_key'     => 'artist-cta-copy',
				'definition_version' => 3,
				'assignment_policy'  => 'weighted_random',
				'variant'            => 'challenger-b',
				'surface'            => 'artist-link-page',
			)
		);

		$this->assertCount( 2, $GLOBALS['extrachill_analytics_test_events'] );
		$this->assertSame( EC_ANALYTICS_EVENT_EXPERIMENT_ASSIGNMENT, $GLOBALS['extrachill_analytics_test_events'][0][0] );
		$this->assertSame( EC_ANALYTICS_EVENT_EXPERIMENT_EXPOSURE, $GLOBALS['extrachill_analytics_test_events'][1][0] );
		$this->assertSame(
			array(
				'experiment_key'     => 'geo-bridge-holdout',
				'definition_version' => 1,
				'assignment_policy'  => 'weighted_random',
				'variant'            => 'control',
				'surface'            => 'single-post-bridge',
			),
			$GLOBALS['extrachill_analytics_test_events'][0][1]
		);
		$this->assertSame( array( 'experiment_key', 'definition_version', 'assignment_policy', 'variant', 'surface' ), array_keys( $GLOBALS['extrachill_analytics_test_events'][1][1] ) );
	}

	/**
	 * Analytics stays aligned to Network's exact one-array action contract.
	 */
	public function test_network_hook_names_and_accepted_payload_shape_do_not_drift(): void {
		$experiment_actions = array_values(
			array_filter(
				$GLOBALS['extrachill_analytics_test_registered_actions'],
				static function ( $registration ) {
					return isset( $registration[0] ) && 0 === strpos( $registration[0], 'extrachill_experiment_' );
				}
			)
		);

		$this->assertContains(
			array( 'extrachill_experiment_assignment', 'extrachill_analytics_record_experiment_assignment', 10, 1 ),
			$experiment_actions
		);
		$this->assertContains(
			array( 'extrachill_experiment_exposure', 'extrachill_analytics_record_experiment_exposure', 10, 1 ),
			$experiment_actions
		);
		$this->assertNotContains( 'extrachill_experiment_assignment_recorded', array_column( $experiment_actions, 0 ) );
		$this->assertNotContains( 'extrachill_experiment_exposure_recorded', array_column( $experiment_actions, 0 ) );

		$this->assertFalse(
			extrachill_analytics_record_experiment_event(
				EC_ANALYTICS_EVENT_EXPERIMENT_ASSIGNMENT,
				array(
					'experiment_key'     => 'geo-bridge-holdout',
					'definition_version' => 1,
					'assignment_policy'  => 'weighted_random',
					'variant'            => 'control',
					'surface'            => 'single-post-bridge',
					'extra'              => 'rejected',
				)
			)
		);
	}

	/**
	 * The persistence listener rejects drift and privacy exclusion.
	 */
	public function test_experiment_listener_rejects_unbounded_contract_values(): void {
		$this->assertFalse(
			extrachill_analytics_record_experiment_event(
				EC_ANALYTICS_EVENT_EXPERIMENT_ASSIGNMENT,
				array(
					'experiment_key'     => 'invalid key',
					'definition_version' => 1,
					'assignment_policy'  => 'weighted_random',
					'variant'            => 'control',
					'surface'            => 'single-post-bridge',
				)
			)
		);
		$this->assertFalse(
			extrachill_analytics_record_experiment_event(
				EC_ANALYTICS_EVENT_EXPERIMENT_ASSIGNMENT,
				array(
					'experiment_key'     => 'geo-bridge-holdout',
					'definition_version' => 0,
					'assignment_policy'  => 'weighted_random',
					'variant'            => 'challenger',
					'surface'            => 'single-post-bridge',
				)
			)
		);
		$this->assertFalse(
			extrachill_analytics_record_experiment_event(
				EC_ANALYTICS_EVENT_EXPERIMENT_EXPOSURE,
				array(
					'experiment_key'     => 'geo-bridge-holdout',
					'definition_version' => 1,
					'assignment_policy'  => str_repeat( 'x', 65 ),
					'variant'            => 'treatment',
					'surface'            => 'other',
				)
			)
		);

		$_SERVER['HTTP_SEC_GPC'] = '1';
		$this->assertFalse(
			extrachill_analytics_record_experiment_event(
				EC_ANALYTICS_EVENT_EXPERIMENT_EXPOSURE,
				array(
					'experiment_key'     => 'geo-bridge-holdout',
					'definition_version' => 1,
					'assignment_policy'  => 'weighted_random',
					'variant'            => 'treatment',
					'surface'            => 'single-post-bridge',
				)
			)
		);
		$this->assertSame( array(), $GLOBALS['extrachill_analytics_test_events'] );
	}
}
