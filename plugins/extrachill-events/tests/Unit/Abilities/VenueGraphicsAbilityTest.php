<?php
/**
 * Venue graphics ability tests.
 *
 * @package ExtraChillEvents\Tests
 */

declare( strict_types=1 );

use ExtraChillEvents\Abilities\EventRoundupAbilities;
use ExtraChillEvents\Abilities\VenueGraphicsAbilities;
use ExtraChillEvents\Core\VenueAuthorization;

/**
 * extrachill/venue-graphics-build makes the internal roundup self-serve for
 * venue members, so its whole safety story is scope: a member renders their
 * own venue only, the venue tax filter is forced server-side, and the range
 * is capped.
 *
 * Runs in separate processes: the WP shims below must not leak into other
 * plain-unit tests that branch on function_exists().
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class VenueGraphicsAbilityTest extends BookingTestCase {

	/** Install WP shims and load the units under test. */
	private function install_shims(): void {
		$GLOBALS['venue_graphics_test'] = array(
			'user_id' => 7,
			'terms'   => array(
				100 => array(
					'term_id'  => 100,
					'name'     => 'Pour House',
					'taxonomy' => 'venue',
				),
				200 => array(
					'term_id'  => 200,
					'name'     => 'Royal American',
					'taxonomy' => 'venue',
				),
			),
		);

		if ( ! function_exists( 'absint' ) ) {
			eval( 'function absint( $value ) { return abs( (int) $value ); }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Test-only shim.
		}
		if ( ! function_exists( 'get_current_user_id' ) ) {
			eval( 'function get_current_user_id() { return (int) $GLOBALS["venue_graphics_test"]["user_id"]; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Test-only shim.
		}
		if ( ! function_exists( 'get_term' ) ) {
			eval( 'function get_term( $id, $taxonomy = "" ) { $term = $GLOBALS["venue_graphics_test"]["terms"][ (int) $id ] ?? null; return $term ? (object) $term : null; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Test-only shim.
		}
		if ( ! function_exists( 'get_term_meta' ) ) {
			eval( 'function get_term_meta( $id, $key, $single = false ) { return $GLOBALS["venue_graphics_test"]["term_meta"][ (int) $id ][ $key ] ?? ""; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Test-only shim.
		}
		if ( ! function_exists( 'wp_delete_file' ) ) {
			eval( 'function wp_delete_file( $file ) { @unlink( $file ); }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Test-only shim.
		}
		if ( ! function_exists( 'wp_register_ability' ) ) {
			eval( 'function wp_register_ability( $name, $args ) { $GLOBALS["venue_graphics_test"]["registered"][ $name ] = $args; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Test-only shim.
		}

		require_once dirname( __DIR__, 3 ) . '/inc/Core/VenueAuthorization.php';
		require_once dirname( __DIR__, 3 ) . '/inc/Abilities/EventRoundupAbilities.php';
		require_once dirname( __DIR__, 3 ) . '/inc/Abilities/VenueGraphicsAbilities.php';
	}

	/** Authorization double backed by an in-memory "user:venue" membership list. */
	private function authorization( array $memberships ): VenueAuthorization {
		return new class( $memberships ) extends VenueAuthorization {
			/** @var array */
			public $calls = array();

			/** @var array */
			private $memberships;

			// phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod.Found
			public function __construct( array $memberships ) {
				// Deliberately skip the parent constructor: no repository.
				$this->memberships = $memberships;
			}

			public function authorize( int $user_id, int $venue_term_id, string $action ) {
				$this->calls[] = array( $user_id, $venue_term_id, $action );
				return in_array( $user_id . ':' . $venue_term_id, $this->memberships, true )
					? true
					: new \WP_Error( 'venue_action_forbidden', 'You are not authorized to perform this venue action.', array( 'status' => 403 ) );
			}
		};
	}

	/** Roundup double that records build inputs and returns a canned result. */
	private function roundup( $result ): EventRoundupAbilities {
		return new class( $result ) extends EventRoundupAbilities {
			/** @var array */
			public $inputs = array();

			/** @var array|\WP_Error */
			private $result;

			public function __construct( $result ) {
				$this->result = $result;
				parent::__construct();
			}

			public function executeBuild( array $input ): array|\WP_Error {
				$this->inputs[] = $input;
				return $this->result;
			}
		};
	}

	/** Write a throwaway rendered-slide fixture file. */
	private function slide_file( string $contents ): string {
		$path = tempnam( sys_get_temp_dir(), 'ec-slide-' );
		file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture file.
		return $path;
	}

	public function test_member_renders_their_own_venue_and_gets_data_uris(): void {
		$this->install_shims();

		$path_one = $this->slide_file( 'slide-one' );
		$path_two = $this->slide_file( 'slide-two' );
		$roundup  = $this->roundup(
			array(
				'success'      => true,
				'image_paths'  => array( $path_one, $path_two ),
				'total_events' => 5,
			)
		);
		$ability  = new VenueGraphicsAbilities( $this->authorization( array( '7:100' ) ), $roundup );

		$this->assertTrue( $ability->can_access_venue( array( 'venue_term_id' => 100 ) ) );

		$result = $ability->execute(
			array(
				'venue_term_id' => 100,
				'date_start'    => '2026-03-02',
				'date_end'      => '2026-03-08',
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'Pour House', $result['venue_name'] );
		$this->assertSame( 5, $result['total_events'] );
		$this->assertSame( 2, $result['slide_count'] );
		$this->assertSame( 'venue-100-shows-2026-03-02-slide-1.png', $result['slides'][0]['filename'] );
		$this->assertSame( 'data:image/png;base64,' . base64_encode( 'slide-one' ), $result['slides'][0]['data_uri'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Expected data URI payload.
		$this->assertSame( 'data:image/png;base64,' . base64_encode( 'slide-two' ), $result['slides'][1]['data_uri'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Expected data URI payload.

		// Temp files need no storage cleanup because the ability deletes them.
		$this->assertFileDoesNotExist( $path_one );
		$this->assertFileDoesNotExist( $path_two );
	}

	public function test_non_member_is_denied_before_anything_renders(): void {
		$this->install_shims();

		$roundup = $this->roundup( array( 'success' => true ) );
		$ability = new VenueGraphicsAbilities( $this->authorization( array() ), $roundup );

		$gate = $ability->can_access_venue( array( 'venue_term_id' => 100 ) );
		$this->assertInstanceOf( WP_Error::class, $gate );
		$this->assertSame( 'venue_action_forbidden', $gate->get_error_code() );

		$result = $ability->execute(
			array(
				'venue_term_id' => 100,
				'date_start'    => '2026-03-02',
				'date_end'      => '2026-03-08',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'venue_action_forbidden', $result->get_error_code() );
		$this->assertSame( array(), $roundup->inputs, 'A denied request must never reach the renderer.' );
	}

	public function test_member_of_another_venue_is_denied(): void {
		$this->install_shims();

		$roundup = $this->roundup( array( 'success' => true ) );
		$ability = new VenueGraphicsAbilities( $this->authorization( array( '7:100' ) ), $roundup );

		$gate = $ability->can_access_venue( array( 'venue_term_id' => 200 ) );
		$this->assertInstanceOf( WP_Error::class, $gate );

		$result = $ability->execute(
			array(
				'venue_term_id' => 200,
				'date_start'    => '2026-03-02',
				'date_end'      => '2026-03-08',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'venue_action_forbidden', $result->get_error_code() );
		$this->assertSame( array(), $roundup->inputs );
	}

	public function test_venue_filter_is_forced_and_cannot_be_widened(): void {
		$this->install_shims();

		$roundup = $this->roundup(
			array(
				'success'      => true,
				'image_paths'  => array( $this->slide_file( 'slide' ) ),
				'total_events' => 1,
			)
		);
		$ability = new VenueGraphicsAbilities( $this->authorization( array( '7:100' ) ), $roundup );

		// A hostile caller smuggles widening filters; the ability must build
		// its own from the authorized venue_term_id alone.
		$result = $ability->execute(
			array(
				'venue_term_id' => 100,
				'date_start'    => '2026-03-02',
				'date_end'      => '2026-03-08',
				'tax_filters'   => array( 'venue' => array( 200, 'royal-american' ) ),
				'location'      => 'charleston',
			)
		);

		$this->assertIsArray( $result );
		$this->assertCount( 1, $roundup->inputs );
		$this->assertSame( array( 'venue' => array( 100 ) ), $roundup->inputs[0]['tax_filters'] );
		$this->assertArrayNotHasKey( 'location', $roundup->inputs[0] );
		$this->assertArrayNotHasKey( 'location_term_id', $roundup->inputs[0] );
	}

	public function test_input_schema_is_closed_and_offers_no_filter_inputs(): void {
		$this->install_shims();

		$ability = new VenueGraphicsAbilities( $this->authorization( array() ), $this->roundup( array() ) );
		$ability->register();

		$args = $GLOBALS['venue_graphics_test']['registered']['extrachill/venue-graphics-build'];
		$this->assertFalse( $args['input_schema']['additionalProperties'] );
		$this->assertSame(
			array( 'venue_term_id', 'date_start', 'date_end', 'layout', 'title' ),
			array_keys( $args['input_schema']['properties'] )
		);
		$this->assertTrue( $args['meta']['show_in_rest'] );
		$this->assertSame( array( $ability, 'can_access_venue' ), $args['permission_callback'] );
	}

	public function test_range_is_capped_with_a_clear_error(): void {
		$this->install_shims();

		$roundup = $this->roundup(
			array(
				'success'      => true,
				'image_paths'  => array( $this->slide_file( 'slide' ) ),
				'total_events' => 1,
			)
		);
		$ability = new VenueGraphicsAbilities( $this->authorization( array( '7:100' ) ), $roundup );

		$result = $ability->execute(
			array(
				'venue_term_id' => 100,
				'date_start'    => '2026-01-01',
				'date_end'      => '2026-03-10',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'venue_graphics_range_too_long', $result->get_error_code() );
		$this->assertStringContainsString( (string) VenueGraphicsAbilities::MAX_RANGE_DAYS, $result->get_error_message() );
		$this->assertSame( array(), $roundup->inputs );

		// 62 days inclusive is the widest allowed range.
		$ok = $ability->execute(
			array(
				'venue_term_id' => 100,
				'date_start'    => '2026-01-01',
				'date_end'      => '2026-03-03',
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $ok, 'A 62-day range is allowed.' );
	}

	public function test_zero_shows_error_explains_what_happened(): void {
		$this->install_shims();

		$roundup = $this->roundup(
			array(
				'success'      => false,
				'image_paths'  => array(),
				'total_events' => 0,
			)
		);
		$ability = new VenueGraphicsAbilities( $this->authorization( array( '7:100' ) ), $roundup );

		$result = $ability->execute(
			array(
				'venue_term_id' => 100,
				'date_start'    => '2026-03-02',
				'date_end'      => '2026-03-08',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'venue_graphics_no_events', $result->get_error_code() );
		$this->assertStringContainsString( 'No published shows were found for Pour House', $result->get_error_message() );
		$this->assertStringContainsString( '2026-03-02', $result->get_error_message() );
		$this->assertStringContainsString( 'published', $result->get_error_message() );
	}

	public function test_defaults_title_layout_cover_and_closing(): void {
		$this->install_shims();

		$roundup = $this->roundup(
			array(
				'success'      => true,
				'image_paths'  => array( $this->slide_file( 'slide' ) ),
				'total_events' => 1,
			)
		);
		$ability = new VenueGraphicsAbilities( $this->authorization( array( '7:100' ) ), $roundup );

		// A week or less: "This Week at <Venue>", daily layout.
		$ability->execute(
			array(
				'venue_term_id' => 100,
				'date_start'    => '2026-03-02',
				'date_end'      => '2026-03-08',
			)
		);
		$week = $roundup->inputs[0];
		$this->assertSame( 'This Week at Pour House', $week['title'] );
		$this->assertSame( 'daily', $week['layout'] );
		$this->assertTrue( $week['cover'] );
		$this->assertTrue( $week['closing'] );

		// A single month: "<Venue> · <Month>", calendar layout.
		$roundup->inputs = array();
		$ability->execute(
			array(
				'venue_term_id' => 100,
				'date_start'    => '2026-03-01',
				'date_end'      => '2026-03-31',
			)
		);
		$month = $roundup->inputs[0] ?? null;
		$this->assertNotNull( $month, 'Month render reached the builder.' );
		$this->assertSame( 'Pour House · March', $month['title'] );
		$this->assertSame( 'calendar', $month['layout'] );

		// Explicit title and layout win.
		$roundup->inputs = array();
		$ability->execute(
			array(
				'venue_term_id' => 100,
				'date_start'    => '2026-03-01',
				'date_end'      => '2026-03-31',
				'layout'        => 'daily',
				'title'         => 'Spring at the House',
			)
		);
		$this->assertSame( 'Spring at the House', $roundup->inputs[0]['title'] );
		$this->assertSame( 'daily', $roundup->inputs[0]['layout'] );
	}

	public function test_venue_logo_becomes_the_partner_logo_path(): void {
		$this->install_shims();

		$logo = $this->slide_file( 'logo-bytes' );
		$GLOBALS['venue_graphics_test']['term_meta'][100]['_venue_logo_attachment_id'] = 321;
		eval( 'function get_attached_file( $id ) { return 321 === (int) $id ? $GLOBALS["venue_graphics_test"]["logo_path"] : ""; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Test-only shim.
		$GLOBALS['venue_graphics_test']['logo_path'] = $logo;

		$roundup = $this->roundup(
			array(
				'success'      => true,
				'image_paths'  => array( $this->slide_file( 'slide' ) ),
				'total_events' => 1,
			)
		);
		$ability = new VenueGraphicsAbilities( $this->authorization( array( '7:100' ) ), $roundup );

		$ability->execute(
			array(
				'venue_term_id' => 100,
				'date_start'    => '2026-03-02',
				'date_end'      => '2026-03-08',
			)
		);
		$this->assertSame( $logo, $roundup->inputs[0]['partner_logo_path'] );

		// No logo on file: the co-brand input is empty, not invented.
		unset( $GLOBALS['venue_graphics_test']['term_meta'][100]['_venue_logo_attachment_id'] );
		$roundup->inputs = array();
		$ability->execute(
			array(
				'venue_term_id' => 100,
				'date_start'    => '2026-03-02',
				'date_end'      => '2026-03-08',
			)
		);
		$this->assertSame( '', $roundup->inputs[0]['partner_logo_path'] );

		unlink( $logo ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
	}
}
