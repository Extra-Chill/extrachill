<?php
/**
 * Drift tests for canonical analytics event contracts.
 *
 * @package ExtraChill\Analytics
 */

require_once __DIR__ . '/class-extrachill-analytics-test-case.php';

/**
 * Keep active emitters and report readers on canonical names.
 */
final class EventContractsTest extends Extrachill_Analytics_TestCase {
	/**
	 * Establish identified, privacy-eligible experiment fixtures.
	 */
	public function set_up(): void {
		parent::set_up();

		$_COOKIE[ EXTRACHILL_ANALYTICS_VISITOR_COOKIE ] = '123e4567-e89b-42d3-a456-426614174000';
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
		$this->assertSame( 'artist_access_granted', EC_ANALYTICS_EVENT_ARTIST_ACCESS_GRANTED );
		$this->assertSame( 'onboarding', EC_ANALYTICS_ARTIST_ACCESS_GRANTED_SOURCE_ONBOARDING );
		$this->assertSame(
			array( 'artist', 'professional', 'artist_and_professional' ),
			EC_ANALYTICS_ARTIST_ACCESS_GRANTED_METHODS
		);
		$this->assertContains( EC_ANALYTICS_EVENT_ARTIST_ACCESS_GRANTED, EC_ANALYTICS_ARTIST_FUNNEL_EVENTS );
		$this->assertSame(
			array(
				EC_ANALYTICS_EVENT_ARTIST_ACCESS_REQUESTED,
				EC_ANALYTICS_EVENT_ARTIST_ACCESS_APPROVED,
				EC_ANALYTICS_EVENT_ARTIST_ACCESS_GRANTED,
			),
			EC_ANALYTICS_ARTIST_ACCESS_EVENTS
		);
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
		$this->assertSame( 'protected_event_type', $result->get_error_code() );
		$this->assertSame( 0, $this->event_count() );
	}

	/**
	 * The flexible event ability remains internal and absent from public REST.
	 */
	public function test_flexible_event_ability_remains_private(): void {
		$ability = wp_get_ability( 'extrachill/track-analytics-event' );
		$this->assertInstanceOf( WP_Ability::class, $ability );
		$this->assertFalse( $ability->get_meta_item( 'show_in_rest' ) );
		$this->assertTrue( $ability->check_permissions( array() ) );
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

		$this->assertSame( 2, $this->event_count() );
		$rows            = $this->event_rows();
		$assignment_data = $this->event_data( $rows[0] );
		$exposure_data   = $this->event_data( $rows[1] );
		// The real writer stamps is_bot at write time; the recorder contract
		// under test is the payload the recorder itself built.
		unset( $assignment_data['is_bot'], $exposure_data['is_bot'] );
		$this->assertSame( EC_ANALYTICS_EVENT_EXPERIMENT_ASSIGNMENT, $rows[0]->event_type );
		$this->assertSame( EC_ANALYTICS_EVENT_EXPERIMENT_EXPOSURE, $rows[1]->event_type );
		$this->assertSame(
			array(
				'experiment_key'     => 'geo-bridge-holdout',
				'definition_version' => 1,
				'assignment_policy'  => 'weighted_random',
				'variant'            => 'control',
				'surface'            => 'single-post-bridge',
			),
			$assignment_data
		);
		$this->assertSame( array( 'experiment_key', 'definition_version', 'assignment_policy', 'variant', 'surface' ), array_keys( $exposure_data ) );
	}

	/**
	 * Analytics stays aligned to Network's exact one-array action contract.
	 */
	public function test_network_hook_names_and_accepted_payload_shape_do_not_drift(): void {
		$this->assertSame(
			10,
			has_action( 'extrachill_experiment_assignment', 'extrachill_analytics_record_experiment_assignment' )
		);
		$this->assertSame(
			10,
			has_action( 'extrachill_experiment_exposure', 'extrachill_analytics_record_experiment_exposure' )
		);
		$this->assertFalse(
			has_action( 'extrachill_experiment_assignment_recorded', 'extrachill_analytics_record_experiment_assignment' )
		);
		$this->assertFalse(
			has_action( 'extrachill_experiment_exposure_recorded', 'extrachill_analytics_record_experiment_exposure' )
		);

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
		$this->assertSame( 0, $this->event_count() );
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
		$this->assertSame( 0, $this->event_count() );
	}
}
