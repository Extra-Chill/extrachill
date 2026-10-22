<?php
/**
 * Photo Contest abilities registration contract tests.
 *
 * Locks the transport-layer boundary established in issue #34: the anonymous
 * public writes (submit, vote) are hidden from the core Abilities REST runner
 * and exposed only through the extrachill/v1 adapter in extrachill-api, which
 * owns per-client admission. The domain takes no caller-supplied IP, so the
 * spoofable `ip` input is gone from every schema. Status-coded domain errors
 * pass through the abilities unchanged.
 *
 * @package ExtraChillContentBlocks
 */

use PHPUnit\Framework\TestCase;

/**
 * Abilities registration contract (REST visibility, schemas, error passthrough).
 */
class PhotoContestAbilityContractTest extends TestCase {

	private const CONTEST_ID = 'contest-1729000000000-123456';

	protected function setUp(): void {
		wp_stubs_reset();
		extrachill_content_blocks_register_photo_contest_submit_ability();
		extrachill_content_blocks_register_photo_contest_vote_ability();
		extrachill_content_blocks_register_photo_contest_list_ability();
		extrachill_content_blocks_register_photo_contest_hide_ability();
		extrachill_content_blocks_register_photo_contest_top_ability();
	}

	/**
	 * Create a contest post with a photo-contest block fixture.
	 *
	 * @return int Post ID.
	 */
	private function createContestPost(): int {
		$post_id = wp_stubs_insert_post( array( 'post_type' => 'post' ) );

		$GLOBALS['wp_test_posts'][ $post_id ]->post_content = 'blocks:' . $post_id;
		$GLOBALS['wp_test_blocks'][ $post_id ]              = array(
			array(
				'blockName' => 'extrachill/photo-contest',
				'attrs'     => array( 'contestId' => self::CONTEST_ID ),
			),
		);

		return $post_id;
	}

	/**
	 * Valid submit-ability input, overridable per test.
	 *
	 * @param int   $post_id   Contest post ID.
	 * @param array $overrides Input overrides.
	 * @return array Submit input.
	 */
	private function submitInput( int $post_id, array $overrides = array() ): array {
		return array_merge(
			array(
				'post_id'      => $post_id,
				'contest_id'   => self::CONTEST_ID,
				'email'        => 'ghoul@example.com',
				'display_name' => 'Ghoul Friend',
				'photo'        => array(
					'name'     => 'entry.jpg',
					'tmp_name' => '/tmp/phpXXXXXX',
					'size'     => 1024,
					'error'    => 0,
				),
			),
			$overrides
		);
	}

	// ─── REST visibility ─────────────────────────────────────────────────────

	public function test_vote_ability_is_hidden_from_core_rest() {
		$meta = wp_get_ability( 'extrachill/photo-contest-vote' )->get_meta();

		$this->assertFalse(
			$meta['show_in_rest'],
			'photo-contest-vote is an anonymous public write: it must be reachable only through the extrachill/v1 adapter, which owns admission.'
		);
	}

	public function test_submit_ability_stays_hidden_from_core_rest() {
		$meta = wp_get_ability( 'extrachill/photo-contest-submit' )->get_meta();

		$this->assertFalse( $meta['show_in_rest'] );
	}

	public function test_readonly_and_moderator_abilities_stay_rest_visible() {
		foreach ( array( 'photo-contest-list', 'photo-contest-hide', 'photo-contest-top' ) as $ability ) {
			$meta = wp_get_ability( "extrachill/{$ability}" )->get_meta();
			$this->assertTrue( $meta['show_in_rest'], "{$ability} stays REST-visible." );
		}
	}

	// ─── No caller-supplied IP ───────────────────────────────────────────────

	public function test_no_ability_schema_accepts_an_ip_input() {
		$abilities = array(
			'photo-contest-submit',
			'photo-contest-vote',
			'photo-contest-list',
			'photo-contest-hide',
			'photo-contest-top',
		);

		foreach ( $abilities as $ability ) {
			$schema = wp_get_ability( "extrachill/{$ability}" )->get_input_schema();
			$this->assertArrayNotHasKey(
				'ip',
				$schema['properties'] ?? array(),
				"{$ability} must not take a caller-supplied ip: admission belongs to extrachill-api."
			);
		}
	}

	// ─── Status-coded error passthrough (submit) ─────────────────────────────

	public function test_submit_success_returns_message_and_entry() {
		$post_id = $this->createContestPost();

		$result = wp_get_ability( 'extrachill/photo-contest-submit' )->execute( $this->submitInput( $post_id ) );

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertArrayHasKey( 'message', $result );
		$this->assertSame( 'Ghoul Friend', $result['entry']['display_name'] );
	}

	public function test_submit_passes_through_already_entered_as_409_with_entry() {
		$post_id = $this->createContestPost();
		$ability = wp_get_ability( 'extrachill/photo-contest-submit' );

		$first = $ability->execute( $this->submitInput( $post_id ) );
		$this->assertFalse( is_wp_error( $first ) );

		$repeat = $ability->execute( $this->submitInput( $post_id ) );

		$this->assertTrue( is_wp_error( $repeat ) );
		$this->assertSame( 'already_entered', $repeat->get_error_code() );
		$data = (array) $repeat->get_error_data();
		$this->assertSame( 409, $data['status'] );
		$this->assertSame(
			$first['entry']['id'],
			$data['entry']['id'],
			'already_entered carries the existing entry so the route can hand the share link back.'
		);
	}

	public function test_submit_passes_through_honeypot_as_403() {
		$post_id = $this->createContestPost();

		$result = wp_get_ability( 'extrachill/photo-contest-submit' )->execute(
			$this->submitInput( $post_id, array( 'website' => 'http://spam.example' ) )
		);

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'invalid_submission', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_submit_passes_through_contest_not_found_as_404() {
		$result = wp_get_ability( 'extrachill/photo-contest-submit' )->execute( $this->submitInput( 999999 ) );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'contest_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_submit_passes_through_validation_errors_as_400() {
		$post_id = $this->createContestPost();

		$result = wp_get_ability( 'extrachill/photo-contest-submit' )->execute(
			$this->submitInput( $post_id, array( 'photo' => null ) )
		);

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'missing_photo', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	// ─── Status-coded error passthrough (hide / list) ────────────────────────

	public function test_hide_passes_through_entry_not_found_as_404() {
		wp_stubs_set_user_caps( array( 'edit_others_posts' ) );

		$result = wp_get_ability( 'extrachill/photo-contest-hide' )->execute( array( 'entry_id' => 424242 ) );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'entry_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_list_returns_contest_not_found_as_404() {
		$result = wp_get_ability( 'extrachill/photo-contest-list' )->execute(
			array(
				'post_id'    => 999999,
				'contest_id' => self::CONTEST_ID,
			)
		);

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'contest_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_list_and_top_validation_errors_carry_400() {
		foreach ( array( 'photo-contest-list' ) as $ability ) {
			$result = wp_get_ability( "extrachill/{$ability}" )->execute(
				array(
					'post_id'    => 0,
					'contest_id' => self::CONTEST_ID,
				)
			);
			$this->assertSame( 'invalid_post_id', $result->get_error_code() );
			$this->assertSame( 400, $result->get_error_data()['status'] );
		}

		wp_stubs_set_user_caps( array( 'edit_others_posts' ) );

		$top = wp_get_ability( 'extrachill/photo-contest-top' )->execute(
			array(
				'post_id'    => 123,
				'contest_id' => '',
			)
		);
		$this->assertSame( 'invalid_contest_id', $top->get_error_code() );
		$this->assertSame( 400, $top->get_error_data()['status'] );

		$hide = wp_get_ability( 'extrachill/photo-contest-hide' )->execute( array( 'entry_id' => 0 ) );
		$this->assertSame( 'invalid_entry_id', $hide->get_error_code() );
		$this->assertSame( 400, $hide->get_error_data()['status'] );
	}
}
