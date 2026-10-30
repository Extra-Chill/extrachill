<?php
/**
 * Real-ability-boundary regression test for #253.
 *
 * tests/og-card-regeneration-ability-smoke.php exercises
 * OgCardRegenerationAbility::execute() directly — fast, and correct for what
 * it covers — but it defines its own local wp_get_ability()/wp_register_ability()
 * stand-ins (see that file) that never construct a real WP_Ability, so it never
 * runs WP_Ability::execute()'s own output-schema validation. That's precisely
 * where #253 lived: next_offset was returned as null while the output schema
 * declared a plain `integer`, and no test caught it because nothing in this
 * repo ever called the ability through the real registry.
 *
 * This file closes that gap for THIS ability specifically. It registers
 * extrachill/regenerate-og-card with the real, core `wp_register_ability()`
 * and calls it through the real `wp_get_ability(...)->execute()` — the exact
 * call path extrachill-cli's RegenerateOgCardCommand uses — so
 * WP_Ability::validate_output() (wp-includes/abilities-api/class-wp-ability.php)
 * actually runs against the real registered output_schema. If next_offset
 * regresses to null (or any other output field drifts from its schema), this
 * test fails the same way the CLI does in production, not silently.
 *
 * Third instance of the stubbed-wp_get_ability() blind spot in this org this
 * month: data-machine-business#134, extrachill-events#868, and this one. See
 * extrachill-network#253 for the pattern write-up.
 *
 * Isolation note: this component's PHPUnit harness activates only
 * extrachill-network (Data Machine is not mounted — see
 * inc/FeatureProviders.php's `defined( 'DATAMACHINE_VERSION' )` gate around
 * OgCardRegenerationAbility's registration), so OgCardGenerationTask's real
 * parent class — DataMachine\Engine\AI\System\Tasks\SystemTask — isn't
 * loadable here. That class is registered but never invoked by the scenarios
 * below (a plain 'post' post has no OG card template mapped, so processPost()
 * short-circuits to "skipped" before touching any Data Machine surface), so a
 * minimal structural stand-in is declared only if the real one isn't already
 * loaded. That stand-in is inert scaffolding for an unrelated base class, not
 * a stand-in for wp_get_ability()/WP_Ability/rest_validate_value_from_schema()
 * — the actual boundary under test here stays 100% real WordPress core.
 *
 * @package ExtraChillNetwork\Abilities
 */

declare( strict_types=1 );

namespace DataMachine\Engine\AI\System\Tasks {
	if ( ! class_exists( __NAMESPACE__ . '\\SystemTask' ) ) {
		abstract class SystemTask {
			abstract public function executeTask( int $jobId, array $params ): void;
			abstract public function getTaskType(): string;
		}
	}
}

namespace {

	use ExtraChillNetwork\Abilities\OgCardRegenerationAbility;

	/**
	 * @group og-cards
	 * @group abilities
	 */
	class OgCardRegenerationAbilityOutputSchemaTest extends \WP_UnitTestCase {

		public function set_up() {
			parent::set_up();

			if ( ! class_exists( \ExtraChillNetwork\OgCards\OgCardGenerationTask::class ) ) {
				require_once dirname( __DIR__ ) . '/inc/og-cards/data-resolver.php';
				require_once dirname( __DIR__ ) . '/inc/og-cards/og-card-task.php';
			}
			if ( ! class_exists( OgCardRegenerationAbility::class ) ) {
				require_once dirname( __DIR__ ) . '/inc/Abilities/OgCardRegenerationAbility.php';
			}

			// FeatureProviders.php normally hooks OgCardRegenerationAbility::register()
			// onto wp_abilities_api_init when Data Machine is active — it isn't, in
			// this isolated harness (see class docblock), so that hook never fired
			// during bootstrap. Register it here instead, through the exact same
			// wp_register_ability() the real bootstrap uses. wp_register_ability()
			// asserts doing_action('wp_abilities_api_init') itself (by design — see
			// wp-includes/abilities-api.php), so that action is opened around the
			// call rather than skipping/faking the guard.
			if ( ! wp_has_ability( 'extrachill/regenerate-og-card' ) ) {
				global $wp_current_filter;
				$wp_current_filter[] = 'wp_abilities_api_init';
				try {
					( new OgCardRegenerationAbility() )->register();
				} finally {
					array_pop( $wp_current_filter );
				}
			}
		}

		/**
		 * Grants the ability's permission gate to the current user, following the
		 * documented sandbox technique for super-admin status (see QRCodeAbilityTest).
		 */
		private function grant_manage_network(): void {
			$administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
			$user          = get_userdata( $administrator );
			$GLOBALS['super_admins'] = array( $user->user_login );
			wp_set_current_user( $administrator );
		}

		public function tear_down() {
			unset( $GLOBALS['super_admins'] );
			parent::tear_down();
		}

		/**
		 * The exact regression from #253: every single-post call has
		 * has_more === false, and the ability used to return next_offset as
		 * null for that case while its own output_schema declared a plain
		 * `integer` — so WP_Ability::execute() rejected its own output on
		 * every single-post call, dry-run or not.
		 */
		public function test_single_post_execute_passes_real_output_schema_validation(): void {
			$this->grant_manage_network();

			$post_id = self::factory()->post->create( array( 'post_type' => 'post' ) );

			$ability = wp_get_ability( 'extrachill/regenerate-og-card' );
			$this->assertInstanceOf( \WP_Ability::class, $ability );

			// Crosses the real boundary: input normalization, input schema
			// validation, permission check, execute_callback, THEN output
			// schema validation — in that order, inside WP_Ability::execute().
			$result = $ability->execute(
				array(
					'post_id' => $post_id,
					'dry_run' => true,
				)
			);

			$this->assertIsArray(
				$result,
				is_wp_error( $result )
					? 'wp_get_ability(...)->execute() rejected its own output: ' . $result->get_error_message()
					: ''
			);
			$this->assertFalse( $result['has_more'] );
			$this->assertIsInt( $result['next_offset'], 'next_offset must satisfy the integer output schema even when has_more is false.' );
			$this->assertSame( 0, $result['next_offset'] );
		}

		/**
		 * Same regression, reached through the bulk/pagination code path
		 * (executeBulk()'s independent next_offset assignment) rather than
		 * the single-post path — the exhausted-final-page case for any bulk
		 * or CLI pass.
		 */
		public function test_bulk_final_page_execute_passes_real_output_schema_validation(): void {
			$this->grant_manage_network();

			$ability = wp_get_ability( 'extrachill/regenerate-og-card' );

			$result = $ability->execute(
				array(
					// A post type with no candidates: has_more is false on the
					// very first (and only) page, exercising executeBulk()'s
					// next_offset assignment at its "no next page" branch.
					'post_type' => 'ogr_schema_test_empty',
					'limit'     => 5,
				)
			);

			$this->assertIsArray(
				$result,
				is_wp_error( $result )
					? 'wp_get_ability(...)->execute() rejected its own output: ' . $result->get_error_message()
					: ''
			);
			$this->assertFalse( $result['has_more'] );
			$this->assertIsInt( $result['next_offset'] );
			$this->assertSame( 0, $result['next_offset'] );
		}
	}
}
