<?php
/**
 * Real-registry contract tests for extrachill/regenerate-og-card (#254).
 *
 * tests/og-card-regeneration-ability-smoke.php covers the ability's rich
 * behavioral surface (dry-run, force, bulk pagination, per-card old/new URL
 * reporting) against a hand-rolled standalone stub of wp_get_ability() —
 * necessary because that file has to run in the standalone-php environment,
 * where no real WordPress boots. That stub replaces WP_Ability entirely, so
 * it performs NO input/output schema validation — which is exactly why
 * #253 shipped: `next_offset => null` against an output schema declaring
 * `'type' => 'integer'` sailed through every assertion because there was no
 * real WP_Ability::validate_output() in the loop to catch it.
 *
 * This file is the fix for that structural gap. It resolves the ability
 * through the REAL registry (wp_get_ability(), no stub — `data-machine` is
 * mounted as a validation dependency so the ability actually registers) and
 * calls the REAL WP_Ability::execute(), which validates output against the
 * ability's own registered schema before returning. A schema violation here
 * surfaces as `execute()` returning `WP_Error( 'ability_invalid_output' )`,
 * not as a silently-wrong array that only a hand-written assertion might
 * happen to catch.
 *
 * @package ExtraChillNetwork\Tests
 */

declare( strict_types=1 );

use ExtraChillNetwork\OgCards\OgCardGenerationTask;

/**
 * @group og-cards
 * @group abilities
 */
class OgCardRegenerationAbilityContractTest extends WP_UnitTestCase {

	private const TEMPLATE_MAP_FILTER = 'extrachill_og_card_template_map';
	private const TEST_POST_TYPE      = 'ogr_contract_test';

	public function set_up() {
		parent::set_up();

		register_post_type(
			self::TEST_POST_TYPE,
			array(
				'public' => true,
				'label'  => 'OG Regen Contract Test Type',
			)
		);

		add_filter(
			self::TEMPLATE_MAP_FILTER,
			static function ( array $map ): array {
				$map[ self::TEST_POST_TYPE ] = 'smoke_template';
				return $map;
			}
		);

		// The data resolver requires a data collector to be registered for
		// the mapped template ID, or the ability treats the post as having
		// no data and skips it rather than reaching buildResponse() with a
		// populated card. See inc/og-cards/data-resolver.php.
		add_filter(
			'extrachill_og_card_data_' . self::TEST_POST_TYPE,
			static function ( array $data ): array {
				$data['title'] = 'Contract Test Post';
				return $data;
			}
		);
	}

	public function tear_down() {
		remove_all_filters( self::TEMPLATE_MAP_FILTER );
		remove_all_filters( 'extrachill_og_card_data_' . self::TEST_POST_TYPE );
		unregister_post_type( self::TEST_POST_TYPE );
		parent::tear_down();
	}

	/**
	 * The ability registers for real under the real WordPress abilities API
	 * — no wp_get_ability() stub, no hand-constructed fake ability object.
	 */
	public function test_ability_resolves_through_the_real_registry(): void {
		$this->assertTrue( wp_has_ability( 'extrachill/regenerate-og-card' ) );

		$ability = wp_get_ability( 'extrachill/regenerate-og-card' );
		$this->assertInstanceOf( WP_Ability::class, $ability );
		$this->assertNotEmpty( $ability->get_output_schema(), 'The ability must declare an output schema for validate_output() to have anything to check.' );
	}

	/**
	 * Permission gating runs through the real permission_callback wired
	 * into WP_Ability::execute(), not a hand-called method in isolation.
	 */
	public function test_execute_denies_an_unprivileged_user_through_the_real_permission_gate(): void {
		wp_set_current_user( 0 );

		$ability = wp_get_ability( 'extrachill/regenerate-og-card' );
		$result  = $ability->execute( array( 'post_id' => 1 ) );

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Missing-target input is caught by the real input path (execute()
	 * still runs the ability's own validation before permission checks
	 * reach the manage_options-gated callback), not a fatal.
	 */
	public function test_execute_reports_missing_target_through_the_real_ability(): void {
		$administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $administrator );

		$ability = wp_get_ability( 'extrachill/regenerate-og-card' );
		$result  = $ability->execute( array() );

		$this->assertWPError( $result );
		$this->assertSame( 'missing_target', $result->get_error_code() );
	}

	/**
	 * The happy path: a bulk dry-run call that still has more candidates
	 * beyond the requested page produces a real integer next_offset. This
	 * path is unaffected by #253 (has_more is true, so next_offset is
	 * always a genuine computed integer in both the pre- and post-#253
	 * code), so it stays green regardless of #253's landing state and
	 * proves the harness passes real, schema-conformant output cleanly —
	 * not just that it fails loud on a violation.
	 */
	public function test_execute_output_validates_against_schema_when_more_candidates_remain(): void {
		$administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $administrator );

		foreach ( array( 0, 1 ) as $i ) {
			$post_id = self::factory()->post->create( array( 'post_type' => self::TEST_POST_TYPE ) );
			update_post_meta( $post_id, OgCardGenerationTask::META_URL, 'https://example.test/wp-content/uploads/og-cards/contract-' . $i . '.png' );
		}

		$ability = wp_get_ability( 'extrachill/regenerate-og-card' );
		$result  = $ability->execute(
			array(
				'post_type' => self::TEST_POST_TYPE,
				'dry_run'   => true,
				'limit'     => 1,
				'offset'    => 0,
			)
		);

		// A WP_Error here means either the ability rejected the input/permission
		// (a bug in this test's setup) or — the case this harness exists to
		// catch — validate_output() rejected the return value against the
		// ability's own declared schema. Fail loud either way, but only expect
		// the ability_invalid_output signature to originate from the
		// deliberately-invalid-output test below.
		$this->assertNotWPError( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertTrue( $result['has_more'] );
		$this->assertIsInt( $result['next_offset'] );
		$this->assertSame( 1, $result['next_offset'] );
	}

	/**
	 * The exact #253 regression, now fixed: once the bulk candidate set is
	 * exhausted (has_more === false), executeBulk() used to report
	 * next_offset as `null` — but the ability's own output_schema declares
	 * next_offset as a plain `'type' => 'integer'`, with no null variant.
	 * WP_Ability::execute() runs validate_output() against that schema
	 * before returning, so this harness (unlike the old wp_get_ability()
	 * stub) actually caught it: before #253 landed, this exact call
	 * returned `WP_Error( 'ability_invalid_output' )`. #253 changed the
	 * exhausted-pagination value from `null` to `0`, which conforms to the
	 * schema, so this now asserts the corrected output.
	 *
	 * This uses a bulk call over a post_type with zero real candidates, so
	 * it never reaches OgCardGenerationTask::render_for_post() (no GD
	 * rendering, no `datamachine/render-image-template` ability call) —
	 * the case is reachable purely through buildResponse()'s
	 * has_more=false branch, independent of the rendering pipeline.
	 *
	 * Overlaps with tests/OgCardRegenerationAbilityOutputSchemaTest.php
	 * (added by #253/#258), which covers the same has_more=false /
	 * next_offset=0 case for both the single-post and bulk-final-page
	 * paths with a more detailed rationale docblock. Kept here rather than
	 * removed — see the #254 PR description for the proposed disposition
	 * of that overlap.
	 */
	public function test_execute_output_validates_against_schema_when_candidates_are_exhausted(): void {
		$administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $administrator );

		$ability = wp_get_ability( 'extrachill/regenerate-og-card' );
		$result  = $ability->execute(
			array(
				'post_type' => 'ogr_contract_test_no_candidates_' . uniqid(),
				'limit'     => 5,
			)
		);

		$this->assertNotWPError( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertFalse( $result['has_more'] );
		$this->assertIsInt( $result['next_offset'] );
		$this->assertSame( 0, $result['next_offset'] );
	}
}
