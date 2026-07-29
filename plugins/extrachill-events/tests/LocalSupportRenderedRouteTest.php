<?php
/**
 * Rendered Local Support route and form integration tests.
 *
 * @package ExtraChillEvents\Tests
 */

use ExtraChillEvents\Core\LocalSupportService;
use ExtraChillEvents\Core\LocalSupportWorkspace;

// phpcs:disable -- Plain-PHP integration harness intentionally declares WordPress stubs alongside its test class.

define( 'EXTRACHILL_EVENTS_LOCAL_SUPPORT_SKIP_HOOKS', true );

require_once __DIR__ . '/Support/BookingTestHarness.php';

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $value ) {
		return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $value ) {
		return esc_html( $value );
	}
}
if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( $value ) {
		echo esc_html( $value );
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $value ) {
		return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $value ) {
		return (string) $value;
	}
}
if ( ! function_exists( 'wp_nonce_field' ) ) {
	function wp_nonce_field( $action, $name = '_wpnonce' ) {
		printf( '<input type="hidden" name="%s" value="nonce-%s" />', esc_attr( $name ), esc_attr( $action ) );
	}
}
if ( ! function_exists( 'wp_get_current_user' ) ) {
	function wp_get_current_user() {
		return (object) array( 'display_name' => 'Artist Manager', 'user_email' => 'manager@example.com' );
	}
}
if ( ! function_exists( 'get_home_url' ) ) {
	function get_home_url( $blog_id = null, $path = '' ) {
		unset( $blog_id );
		return 'https://events.example' . $path;
	}
}

require_once dirname( __DIR__ ) . '/inc/core/local-support-workspace.php';

/** Exercises actual rendered forms and canonical domain actions. */
final class LocalSupportRenderedRouteTest extends BookingTestCase {

	/** @var LocalSupportMemoryRepository */
	private $repository;

	/** @var LocalSupportTestAuthorization */
	private $authorization;

	/** @var LocalSupportService */
	private $service;

	protected function setUp(): void {
		$GLOBALS['ec_artist_test'] = array(
			'blog_id'       => 7,
			'uuid'          => 0,
			'options'       => array(),
			'abilities'     => array(),
			'actions'       => array(),
			'fired_actions' => array(),
		);
		$GLOBALS['wpdb']      = new BookingWpdb();
		$this->repository     = new LocalSupportMemoryRepository();
		$this->authorization  = new LocalSupportTestAuthorization();
		$this->service        = new LocalSupportService( $this->repository, $this->authorization );
	}

	/** Public event pages must never expose organizer workflow controls. */
	public function test_public_event_action_is_not_registered(): void {
		$this->assertFalse( function_exists( 'extrachill_events_local_support_event_action' ) );
		$this->assertNotContains( 'extrachill_events_local_support_event_action', $GLOBALS['ec_artist_test']['actions']['data_machine_events_action_buttons'] ?? array() );
	}

	/** Prove organizer selection and consent grant/revoke execute from rendered nonce forms. */
	public function test_rendered_forms_execute_selection_and_consent_lifecycle(): void {
		$request   = $this->open_request();
		$interest  = $this->service->express_interest( $request['id'], 202, 'render-interest', 20 );
		$workspace = $this->workspace( true );

		$organizer_model = $workspace->read( $request['id'], 0, 12 );
		$html            = $this->render( 'extrachill_events_render_local_support_organizer', $organizer_model );
		$selection       = $this->form_values( $html, 'Shortlist' );
		$this->assertSame( 'nonce-extrachill_events_local_support', $selection['_wpnonce'] );
		$selected = extrachill_events_process_local_support_action( $selection, 12, $workspace );
		$this->assertSame( 'shortlisted', $selected['status'] );

		$this->authorization->organizer_allowed = false;
		$artist_model = $workspace->read( $request['id'], 202, 20 );
		$html         = $this->render( 'extrachill_events_render_local_support_artist', $artist_model );
		$grant        = array_merge(
			$this->form_values( $html, 'Share selected contact' ),
			array(
				'fields'       => array( 'email' ),
				'contact_email' => 'manager@example.com',
			)
		);
		$granted = extrachill_events_process_local_support_action( $grant, 20, $workspace );
		$this->assertSame( array( 'email' => 'manager@example.com' ), $granted['contact'] );

		$this->authorization->organizer_allowed = true;
		$organizer_model = $workspace->read( $request['id'], 0, 12 );
		$this->assertSame( 'manager@example.com', $organizer_model['interests'][0]['contact']['email'] );
	}

	/** Prove a valid request-A form cannot grant consent to a request-B interest row. */
	public function test_consent_grant_requires_exact_rendered_interest_id(): void {
		$request_a  = $this->open_request( 900, 'open-request-a' );
		$interest_a = $this->service->express_interest( $request_a['id'], 202, 'interest-request-a', 20 );
		$request_b  = $this->repository->create_request( array( 'event_id' => 901, 'venue_term_id' => 56, 'organizer_type' => 'venue', 'organizer_id' => 56, 'actor_id' => 12 ) );
		$interest_b = $this->repository->create_interest( $request_b['id'], 202, 20 );
		$declined_b = $this->repository->update_interest( $interest_b['id'], 1, array( 'status' => 'declined' ) );
		$workspace  = $this->workspace( true );

		$this->authorization->organizer_allowed = false;
		$model = $workspace->read( $request_a['id'], 202, 20 );
		$html  = $this->render( 'extrachill_events_render_local_support_artist', $model );
		$grant = array_merge(
			$this->form_values( $html, 'Share selected contact' ),
			array(
				'interest_id'  => $interest_b['id'],
				'expected_version' => $declined_b['version'],
				'fields'       => array( 'email' ),
				'contact_email' => 'must-not-share@example.com',
			)
		);
		$this->assertSame( 'nonce-extrachill_events_local_support', $grant['_wpnonce'] );

		$denied = extrachill_events_process_local_support_action( $grant, 20, $workspace );
		$this->assertSame( 'local_support_forbidden', $denied->get_error_code() );
		$this->assertNull( $this->repository->get_interest( $interest_a['id'] )['contact'] );
		$this->assertNull( $this->repository->get_interest( $interest_b['id'] )['contact'] );
	}

	/**
	 * Prove existing ownership preserves revoke access after eligibility changes.
	 *
	 * @dataProvider ineligible_consent_scenarios
	 * @param string $scenario Eligibility-loss scenario.
	 */
	public function test_existing_interest_can_revoke_after_eligibility_or_status_change( string $scenario ): void {
		$request   = $this->open_request();
		$interest  = $this->service->express_interest( $request['id'], 202, 'interest-' . $scenario, 20 );
		$granted   = $this->service->set_contact_consent( $interest['id'], true, array( 'email' => 'private@example.com' ), array( 'email' ), 1, 'grant-' . $scenario, 20 );
		$eligible  = true;
		$workspace = $this->workspace_by_reference( $eligible );

		if ( 'declined' === $scenario ) {
			$this->service->transition_interest( $interest['id'], 'declined', $granted['version'], 'decline-active-consent', 12 );
		} else {
			$eligible = false;
		}

		$this->authorization->organizer_allowed = false;
		$model = $workspace->read( $request['id'], 202, 20 );
		$this->assertSame( 'artist', $model['role'] );
		$this->assertSame( 'private@example.com', $model['interest']['contact']['email'] );
		$html = $this->render( 'extrachill_events_render_local_support_artist', $model );
		$this->assertStringContainsString( 'Revoke contact access', $html );
		if ( ! $eligible ) {
			$this->assertStringNotContainsString( 'Withdraw interest', $html );
			$this->assertStringNotContainsString( 'Share selected contact', $html );
		}

		$revoke  = $this->form_values( $html, 'Revoke contact access' );
		$revoked = extrachill_events_process_local_support_action( $revoke, 20, $workspace );
		$this->assertNull( $revoked['contact'] );

		$this->authorization->organizer_allowed = true;
		$organizer_model = $workspace->read( $request['id'], 0, 12 );
		$this->assertNull( $organizer_model['interests'][0]['contact'] );
	}

	/** Scenario names map to opt-out, scene change, and organizer decline. */
	public function ineligible_consent_scenarios(): array {
		return array( 'opt-out' => array( 'opt-out' ), 'scene-change' => array( 'scene-change' ), 'declined' => array( 'declined' ) );
	}

	/** Prove rendered unauthorized and stale-version states are accessible alerts. */
	public function test_rendered_unauthorized_and_conflict_states(): void {
		$unauthorized = $this->render( 'extrachill_events_render_local_support_unavailable' );
		$conflict     = $this->render( 'extrachill_events_local_support_notice', 'conflict' );
		$this->assertStringContainsString( 'role="alert"', $unauthorized );
		$this->assertStringContainsString( 'Workspace unavailable', $unauthorized );
		$this->assertStringContainsString( 'latest version is shown', $conflict );
		$this->assertStringContainsString( 'role="status"', $conflict );
	}

	private function open_request( int $event_id = 900, string $key = 'open-render' ): array {
		return $this->service->open_request( array( 'event_id' => $event_id, 'organizer_type' => 'venue', 'organizer_id' => 55, 'idempotency_key' => $key ), 12 );
	}

	private function workspace( bool $eligible ): LocalSupportWorkspace {
		return new LocalSupportWorkspace( $this->repository, $this->authorization, $this->service, static function () use ( $eligible ): array {
			return $eligible ? array( array( 'artist_term_id' => 202, 'name' => 'Managed Artist' ) ) : array();
		} );
	}

	private function workspace_by_reference( bool &$eligible ): LocalSupportWorkspace {
		return new LocalSupportWorkspace( $this->repository, $this->authorization, $this->service, static function () use ( &$eligible ): array {
			return $eligible ? array( array( 'artist_term_id' => 202, 'name' => 'Managed Artist' ) ) : array();
		} );
	}

	private function render( string $callback, $model = null ): string {
		ob_start();
		null === $model ? $callback() : $callback( $model );
		return (string) ob_get_clean();
	}

	private function form_values( string $html, string $button_label ): array {
		$document = new DOMDocument();
		$document->loadHTML( '<!doctype html><html><body>' . $html . '</body></html>', LIBXML_NOERROR | LIBXML_NOWARNING );
		$xpath = new DOMXPath( $document );
		$button = $xpath->query( '//button[normalize-space()="' . $button_label . '"]' )->item( 0 );
		$this->assertInstanceOf( DOMElement::class, $button, $button_label . ' form was not rendered.' );
		$values = array();
		foreach ( $xpath->query( './/ancestor::form[1]//input[@name]', $button ) as $input ) {
			$values[ $input->getAttribute( 'name' ) ] = $input->getAttribute( 'value' );
		}
		return $values;
	}
}
