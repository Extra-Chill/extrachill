<?php
/**
 * Photo Contest business-logic tests.
 *
 * Exercises the canonical domain logic in inc/photo-contest/ against the
 * in-memory WordPress stubs: email-gated submission with dedup/honeypot/
 * window enforcement, status-coded WP_Error failures, the photo storage
 * short-circuit filter seam, the dedicated photo_contest newsletter context,
 * vote keying on the shared image_vote comment backend, moderation
 * (hide/restore), top-N ranking, and OG deep-link overrides.
 *
 * @package ExtraChillContentBlocks
 */

use PHPUnit\Framework\TestCase;

/**
 * Photo contest business logic.
 */
class PhotoContestLogicTest extends TestCase {

	private const CONTEST_ID = 'contest-1729000000000-123456';

	/**
	 * Temp files created for duplicate-photo hashing, cleaned up per test.
	 *
	 * @var string[]
	 */
	private array $tempFiles = array();

	protected function setUp(): void {
		wp_stubs_reset();
	}

	protected function tearDown(): void {
		foreach ( $this->tempFiles as $path ) {
			if ( is_file( $path ) ) {
				unlink( $path );
			}
		}
		$this->tempFiles = array();
	}

	/**
	 * Assert a status-coded domain WP_Error.
	 *
	 * @param mixed  $result Result under test.
	 * @param string $code   Expected error code.
	 * @param int    $status Expected HTTP status in data['status'].
	 */
	private function assertDomainError( $result, string $code, int $status ): void {
		$this->assertTrue( is_wp_error( $result ), "Expected WP_Error({$code})." );
		$this->assertSame( $code, $result->get_error_code() );
		$data = (array) $result->get_error_data();
		$this->assertSame( $status, $data['status'] ?? null, "Error {$code} must carry HTTP status {$status} in data." );
	}

	/**
	 * Create a real temp file with the given content (for md5_file hashing).
	 *
	 * @param string $content File content.
	 * @return string Absolute path.
	 */
	private function createTempPhoto( string $content ): string {
		$path = tempnam( sys_get_temp_dir(), 'ecpc' );
		file_put_contents( $path, $content );
		$this->tempFiles[] = $path;

		return $path;
	}

	/**
	 * Create a contest post whose content contains a photo-contest block.
	 *
	 * @param array $attrs Extra block attributes merged over contestId.
	 * @return int Post ID.
	 */
	private function createContestPost( array $attrs = array() ): int {
		$post_id = wp_stubs_insert_post(
			array(
				'post_type'  => 'post',
				'post_title' => 'Test Photo Contest',
			)
		);

		// Business logic reads settings back out of post_content via
		// parse_blocks; the stub resolves the token to this fixture.
		$GLOBALS['wp_test_posts'][ $post_id ]->post_content = 'blocks:' . $post_id;
		$GLOBALS['wp_test_blocks'][ $post_id ]              = array(
			array(
				'blockName' => 'extrachill/photo-contest',
				'attrs'     => array_merge( array( 'contestId' => self::CONTEST_ID ), $attrs ),
			),
		);

		return $post_id;
	}

	/**
	 * Submit a valid entry, overriding any arg.
	 *
	 * @param int   $post_id   Contest post ID.
	 * @param array $overrides Arg overrides.
	 * @return array|WP_Error Submission result.
	 */
	private function submitEntry( int $post_id, array $overrides = array() ) {
		return extrachill_content_blocks_photo_contest_submit_entry(
			array_merge(
				array(
					'post_id'      => $post_id,
					'contest_id'   => self::CONTEST_ID,
					'email'        => 'ghoul@example.com',
					'display_name' => 'Ghoul Friend',
					'instagram'    => '@ghoulfriend',
					'honeypot'     => '',
					'file'         => array(
						'name'     => 'entry.jpg',
						'tmp_name' => '/tmp/phpXXXXXX',
						'size'     => 1024 * 1024,
						'error'    => 0,
					),
				),
				$overrides
			)
		);
	}

	// ─── Vote keying ─────────────────────────────────────────────────────────

	public function test_entry_instance_id_is_namespaced_per_entry() {
		$this->assertSame(
			self::CONTEST_ID . ':entry-42',
			extrachill_content_blocks_photo_contest_entry_instance_id( self::CONTEST_ID, 42 )
		);
	}

	// ─── Settings parsing ────────────────────────────────────────────────────

	public function test_get_settings_reads_block_attributes_from_post_content() {
		$post_id = $this->createContestPost(
			array(
				'entriesCloseAt' => '2026-10-24 20:00',
				'votingClosesAt' => '2026-10-24 22:00',
				'showFinalists'  => true,
				'finalistCount'  => 10,
			)
		);

		$settings = extrachill_content_blocks_photo_contest_get_settings( $post_id, self::CONTEST_ID );

		$this->assertSame( '2026-10-24 20:00', $settings['entries_close_at'] );
		$this->assertSame( '2026-10-24 22:00', $settings['voting_closes_at'] );
		$this->assertTrue( $settings['show_finalists'] );
		$this->assertSame( 10, $settings['finalist_count'] );
	}

	public function test_get_settings_finds_contest_blocks_nested_in_containers() {
		$post_id = $this->createContestPost();

		// Wrap the contest block inside a core/group container — editors do this.
		$GLOBALS['wp_test_blocks'][ $post_id ] = array(
			array(
				'blockName'   => 'core/group',
				'attrs'       => array(),
				'innerBlocks' => array(
					array(
						'blockName' => 'extrachill/photo-contest',
						'attrs'     => array(
							'contestId'      => self::CONTEST_ID,
							'votingClosesAt' => '2026-10-24 22:00',
						),
					),
				),
			),
		);

		$settings = extrachill_content_blocks_photo_contest_get_settings( $post_id, self::CONTEST_ID );

		$this->assertNotNull( $settings, 'Contest blocks nested inside containers must still be found.' );
		$this->assertSame( '2026-10-24 22:00', $settings['voting_closes_at'] );
	}

	public function test_get_settings_returns_null_for_unknown_contest() {
		$post_id = $this->createContestPost();

		$this->assertNull( extrachill_content_blocks_photo_contest_get_settings( $post_id, 'other-contest' ) );
		$this->assertNull( extrachill_content_blocks_photo_contest_get_settings( 999999, self::CONTEST_ID ) );
	}

	public function test_open_window_logic_uses_site_timezone_and_inclusive_boundary() {
		$GLOBALS['wp_test_timezone'] = 'America/New_York';
		$open = '2026-10-24T19:00';
		$timestamp = strtotime( '2026-10-24T23:00:00Z' );
		$this->assertTrue( extrachill_content_blocks_photo_contest_has_opened( '' ) );
		$this->assertTrue( extrachill_content_blocks_photo_contest_has_opened( 'invalid-date' ) );
		$this->assertFalse( extrachill_content_blocks_photo_contest_has_opened( $open, $timestamp - 1 ) );
		$this->assertTrue( extrachill_content_blocks_photo_contest_has_opened( $open, $timestamp ) );
		$this->assertTrue( extrachill_content_blocks_photo_contest_has_opened( $open, $timestamp + 1 ) );
		$this->assertSame( 'October 24, 2026 7:00 pm EDT', extrachill_content_blocks_photo_contest_format_time( $open ) );

		$config = extrachill_content_blocks_photo_contest_render_config( array( 'entriesOpenAt' => $open, 'votingOpensAt' => $open ), 1 );
		$this->assertSame( $timestamp, strtotime( $config['entriesOpenAt'] ) );
		$this->assertSame( $config['entriesOpenAt'], $config['votingOpensAt'] );
		$this->assertSame( 'October 24, 2026 7:00 pm EDT', $config['entriesOpenAtLabel'] );

		// Close dates resolve in the site timezone too (devices in other
		// timezones must not see a different close time).
		$close  = extrachill_content_blocks_photo_contest_render_config( array( 'entriesCloseAt' => '2026-10-24T22:00', 'votingClosesAt' => '2026-10-24T22:00' ), 1 );
		$this->assertSame( strtotime( '2026-10-25T02:00:00Z' ), strtotime( $close['entriesCloseAt'] ) );
		$this->assertSame( $close['entriesCloseAt'], $close['votingClosesAt'] );
		$this->assertStringEndsWith( '+00:00', $close['votingClosesAt'] );
	}

	public function test_unset_open_settings_preserve_immediate_opening() {
		$post_id = $this->createContestPost();
		$settings = extrachill_content_blocks_photo_contest_get_settings( $post_id, self::CONTEST_ID );
		$this->assertSame( '', $settings['entries_open_at'] );
		$this->assertSame( '', $settings['voting_opens_at'] );
		$config = extrachill_content_blocks_photo_contest_render_config( array(), $post_id );
		$this->assertSame( '', $config['entriesOpenAt'] );
		$this->assertSame( '', $config['votingOpensAt'] );
	}

	public function test_submission_open_and_close_window() {
		$open = '2026-10-24T19:00';
		$close = '2026-10-24T20:00';
		$post_id = $this->createContestPost( array( 'entriesOpenAt' => $open, 'entriesCloseAt' => $close ) );
		$settings = extrachill_content_blocks_photo_contest_get_settings( $post_id, self::CONTEST_ID );
		$this->assertSame( $open, $settings['entries_open_at'] );
		$early = $this->submitEntry( $post_id, array( 'now' => strtotime( $open ) - 1 ) );
		$this->assertDomainError( $early, 'entries_not_open', 403 );
		$this->assertStringContainsString( 'Entries open October 24, 2026', $early->get_error_message() );
		$this->assertSame( array(), extrachill_content_blocks_photo_contest_get_entries( $post_id, self::CONTEST_ID ) );
		$this->assertFalse( is_wp_error( $this->submitEntry( $post_id, array( 'now' => strtotime( $open ) ) ) ) );
		$this->assertFalse( is_wp_error( $this->submitEntry( $post_id, array( 'now' => strtotime( $open ) + 1, 'email' => 'after@example.com' ) ) ) );
		$this->assertDomainError( $this->submitEntry( $post_id, array( 'now' => strtotime( $close ) ) ), 'entries_closed', 403 );
	}

	public function test_voting_open_and_close_window() {
		$open = '2026-10-24T19:00';
		$close = '2026-10-24T22:00';
		$post_id = $this->createContestPost( array( 'votingOpensAt' => $open, 'votingClosesAt' => $close ) );
		$entry_id = $this->submitEntry( $post_id )['entry']['id'];
		$settings = extrachill_content_blocks_photo_contest_get_settings( $post_id, self::CONTEST_ID );
		$this->assertSame( $open, $settings['voting_opens_at'] );
		$vote = fn( $now ) => extrachill_content_blocks_photo_contest_process_vote( $post_id, self::CONTEST_ID, $entry_id, 'voter@example.com', $now );
		$this->assertDomainError( $vote( strtotime( $open ) - 1 ), 'voting_not_open', 403 );
		$this->assertSame( 0, extrachill_content_blocks_photo_contest_entry_data( $entry_id )['vote_count'] );
		$this->assertFalse( is_wp_error( $vote( strtotime( $open ) ) ) );
		$this->assertFalse( is_wp_error( extrachill_content_blocks_photo_contest_process_vote( $post_id, self::CONTEST_ID, $entry_id, 'after@example.com', strtotime( $open ) + 1 ) ) );
		$this->assertDomainError( $vote( strtotime( $close ) ), 'voting_closed', 403 );
	}

	public function test_close_window_logic() {
		$close = '2026-10-24 20:00';
		$this->assertFalse( extrachill_content_blocks_photo_contest_is_closed( '', time() ) );
		$this->assertFalse( extrachill_content_blocks_photo_contest_is_closed( $close, strtotime( $close ) - 60 ) );
		$this->assertTrue( extrachill_content_blocks_photo_contest_is_closed( $close, strtotime( $close ) + 60 ) );
	}

	public function test_manual_close_and_reopen_enforce_both_phases() {
		$post_id = $this->createContestPost( array( 'showFinalists' => true ) );
		$entry_id = $this->submitEntry( $post_id )['entry']['id'];
		extrachill_content_blocks_register_photo_contest_set_phase_ability();
		wp_stubs_set_user_caps( array( 'edit_others_posts' ) );
		$ability = wp_get_ability( 'extrachill/photo-contest-set-phase' );
		foreach ( array( 'entries', 'voting' ) as $phase ) {
			$input = array( 'post_id' => $post_id, 'contest_id' => self::CONTEST_ID, 'phase' => $phase, 'closed' => true );
			$this->assertFalse( is_wp_error( $ability->execute( $input ) ) );
			// Repeated closes preserve the first timestamp.
			$key = extrachill_content_blocks_photo_contest_phase_meta_key( self::CONTEST_ID, $phase );
			update_post_meta( $post_id, $key, (string) ( time() - 60 ) );
			$first = get_post_meta( $post_id, $key, true );
			$ability->execute( $input );
			$this->assertSame( $first, get_post_meta( $post_id, $key, true ) );
		}
		$this->assertDomainError( $this->submitEntry( $post_id, array( 'email' => 'new@example.com' ) ), 'entries_closed', 403 );
		$this->assertDomainError( extrachill_content_blocks_photo_contest_process_vote( $post_id, self::CONTEST_ID, $entry_id, 'voter@example.com' ), 'voting_closed', 403 );
		$settings = extrachill_content_blocks_photo_contest_get_settings( $post_id, self::CONTEST_ID );
		$this->assertTrue( $settings['show_finalists'] );
		$state = extrachill_content_blocks_photo_contest_list_ability_execute( array( 'post_id' => $post_id, 'contest_id' => self::CONTEST_ID ) )['phase'];
		$this->assertTrue( $state['entries_closed'] );
		$this->assertTrue( $state['voting_closed'] );

		foreach ( array( 'entries', 'voting' ) as $phase ) {
			$this->assertFalse( is_wp_error( $ability->execute( array( 'post_id' => $post_id, 'contest_id' => self::CONTEST_ID, 'phase' => $phase, 'closed' => false ) ) ) );
		}
		$this->assertFalse( is_wp_error( $this->submitEntry( $post_id, array( 'email' => 'new@example.com' ) ) ) );
		$this->assertFalse( is_wp_error( extrachill_content_blocks_photo_contest_process_vote( $post_id, self::CONTEST_ID, $entry_id, 'voter@example.com' ) ) );
		$state = extrachill_content_blocks_photo_contest_phase_state( extrachill_content_blocks_photo_contest_get_settings( $post_id, self::CONTEST_ID ) );
		$this->assertNotContains( true, $state );
	}

	public function test_manual_close_uses_earlier_cutoff_and_reopen_keeps_schedule() {
		$GLOBALS['wp_test_timezone'] = 'America/New_York';
		$scheduled = '2026-10-24T20:00';
		$cutoff = extrachill_content_blocks_photo_contest_time( $scheduled );
		$post_id = $this->createContestPost( array( 'entriesCloseAt' => $scheduled, 'votingClosesAt' => $scheduled ) );
		foreach ( array( 'entries' => 'entries_close_at', 'voting' => 'voting_closes_at' ) as $phase => $setting ) {
			$key = extrachill_content_blocks_photo_contest_phase_meta_key( self::CONTEST_ID, $phase );
			foreach ( array( $cutoff - 60, $cutoff + 60 ) as $manual ) {
				update_post_meta( $post_id, $key, (string) $manual );
				$settings = extrachill_content_blocks_photo_contest_get_settings( $post_id, self::CONTEST_ID );
				$effective = min( $cutoff, $manual );
				$this->assertFalse( extrachill_content_blocks_photo_contest_is_closed( $settings[ $setting ], $effective - 1 ) );
				$this->assertTrue( extrachill_content_blocks_photo_contest_is_closed( $settings[ $setting ], $effective ) );
			}
			extrachill_content_blocks_photo_contest_set_phase( $post_id, self::CONTEST_ID, $phase, false );
			$settings = extrachill_content_blocks_photo_contest_get_settings( $post_id, self::CONTEST_ID );
			$this->assertSame( $scheduled, $settings[ $setting ] );
			$this->assertTrue( extrachill_content_blocks_photo_contest_is_closed( $settings[ $setting ], $cutoff ) );
		}
	}

	public function test_phase_ability_permissions_validation_and_contest_isolation() {
		$post_id = $this->createContestPost();
		$other_post = $this->createContestPost();
		$GLOBALS['wp_test_blocks'][ $post_id ][] = array( 'blockName' => 'extrachill/photo-contest', 'attrs' => array( 'contestId' => 'other' ) );
		extrachill_content_blocks_register_photo_contest_set_phase_ability();
		$ability = wp_get_ability( 'extrachill/photo-contest-set-phase' );
		$input = array( 'post_id' => $post_id, 'contest_id' => self::CONTEST_ID, 'phase' => 'entries', 'closed' => true );
		foreach ( array( array(), array( 'edit_posts' ) ) as $caps ) {
			wp_stubs_set_user_caps( $caps );
			$this->assertSame( 'ability_invalid_permissions', $ability->execute( $input )->get_error_code() );
		}
		wp_stubs_set_user_caps( array( 'edit_others_posts' ) );
		$this->assertFalse( is_wp_error( $ability->execute( $input ) ) );
		$this->assertFalse( $ability->get_meta()['annotations']['destructive'] );
		$this->assertFalse( $ability->get_meta()['annotations']['readonly'] );
		$this->assertFalse( extrachill_content_blocks_photo_contest_get_settings( $post_id, 'other' )['entries_manually_closed'] );
		$this->assertFalse( extrachill_content_blocks_photo_contest_get_settings( $other_post, self::CONTEST_ID )['entries_manually_closed'] );
		$this->assertDomainError( $ability->execute( array_merge( $input, array( 'contest_id' => 'missing' ) ) ), 'contest_not_found', 404 );
		$this->assertDomainError( $ability->execute( array_merge( $input, array( 'phase' => 'invalid' ) ) ), 'invalid_phase', 400 );
	}

	// ─── Submission ──────────────────────────────────────────────────────────

	public function test_submit_entry_success_stores_entry_with_meta_and_share_url() {
		$post_id = $this->createContestPost();

		$result = $this->submitEntry( $post_id );

		$this->assertFalse( is_wp_error( $result ) );
		$entry = $result['entry'];
		$this->assertSame( 'Ghoul Friend', $entry['display_name'] );
		$this->assertSame( 'ghoulfriend', $entry['instagram'], 'Leading @ is stripped.' );
		$this->assertSame( 0, $entry['vote_count'], 'Entries start at zero votes.' );
		$this->assertFalse( $entry['hidden'], 'Entries go live immediately.' );
		$this->assertStringContainsString( 'entry=' . $entry['id'], $entry['share_url'], 'Share URL deep-links the entry.' );

		$this->assertSame(
			self::CONTEST_ID,
			get_post_meta( $entry['id'], EXTRACHILL_PHOTO_CONTEST_META_CONTEST, true )
		);
		$this->assertSame(
			'ghoul@example.com',
			get_post_meta( $entry['id'], EXTRACHILL_PHOTO_CONTEST_META_EMAIL, true ),
			'Email is stored lowercased in meta, never in the public payload.'
		);

		$attachment = get_post( $entry['id'] );
		$this->assertSame( 'attachment', $attachment->post_type );
		$this->assertSame( $post_id, (int) $attachment->post_parent, 'Entries are attachments parented to the contest post.' );
	}

	public function test_submit_entry_rejects_honeypot_with_403() {
		$post_id = $this->createContestPost();

		$result = $this->submitEntry( $post_id, array( 'honeypot' => 'http://spam.example' ) );

		$this->assertDomainError( $result, 'invalid_submission', 403 );
		$this->assertSame( array(), extrachill_content_blocks_photo_contest_get_entries( $post_id, self::CONTEST_ID ) );
	}

	public function test_submit_entry_rejects_unknown_contest_with_404() {
		$result = $this->submitEntry( 999999 );

		$this->assertDomainError( $result, 'contest_not_found', 404 );
	}

	public function test_submit_entry_rejects_invalid_email_and_missing_name_with_400() {
		$post_id = $this->createContestPost();

		$bad_email = $this->submitEntry( $post_id, array( 'email' => 'not-an-email' ) );
		$this->assertDomainError( $bad_email, 'invalid_email', 400 );

		$no_name = $this->submitEntry( $post_id, array( 'display_name' => '   ' ) );
		$this->assertDomainError( $no_name, 'missing_name', 400 );
	}

	public function test_guest_needs_email_but_moderator_can_add_entry_without_one() {
		$post_id = $this->createContestPost();

		$guest = $this->submitEntry( $post_id, array( 'email' => '' ) );
		$this->assertDomainError( $guest, 'invalid_email', 400 );

		wp_stubs_set_user_caps( array( 'edit_others_posts' ) );
		$GLOBALS['wp_test_user_id'] = 38;

		$first  = $this->submitEntry( $post_id, array( 'email' => '' ) );
		$second = $this->submitEntry(
			$post_id,
			array(
				'email'        => '',
				'display_name' => 'Second Ghoul',
			)
		);
		unset( $GLOBALS['wp_test_user_id'] );

		$this->assertFalse( is_wp_error( $first ) );
		$this->assertFalse( is_wp_error( $second ), 'Email-less team entries do not dedupe against each other.' );
		$this->assertSame( '', get_post_meta( $first['entry']['id'], EXTRACHILL_PHOTO_CONTEST_META_EMAIL, true ) );
		$this->assertSame( 38, (int) get_post_meta( $first['entry']['id'], '_photo_contest_added_by', true ) );
	}

	/**
	 * The stored handle is rendered verbatim into instagram.com profile links
	 * on the frontend, so normalization must reduce any input shape to
	 * Instagram's valid charset.
	 *
	 * @dataProvider instagramHandleProvider
	 *
	 * @param string $raw      Raw handle input.
	 * @param string $expected Normalized handle.
	 */
	public function test_instagram_handle_normalization( string $raw, string $expected ) {
		$this->assertSame( $expected, extrachill_content_blocks_photo_contest_sanitize_instagram( $raw ) );
	}

	/**
	 * Raw handle => normalized handle fixtures.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function instagramHandleProvider(): array {
		return array(
			'bare handle'          => array( 'ghoulfriend', 'ghoulfriend' ),
			'leading @'            => array( '@ghoul.friend_13', 'ghoul.friend_13' ),
			'pasted profile URL'   => array( 'https://www.instagram.com/ghoulfriend/?igsh=abc', 'ghoulfriend' ),
			'bare URL no www'      => array( 'http://instagram.com/@ghoulfriend', 'ghoulfriend' ),
			'spaces and symbols'   => array( ' ghoul friend!<script> ', 'ghoulfriendscript' ),
			'path traversal junk'  => array( 'ghoul/../../etc', 'ghoul' ),
			'over 30 chars capped' => array( str_repeat( 'a', 40 ), str_repeat( 'a', 30 ) ),
			'nothing valid'        => array( '@/??', '' ),
		);
	}

	public function test_submit_entry_stores_normalized_instagram_handle() {
		$post_id = $this->createContestPost();

		$result = $this->submitEntry(
			$post_id,
			array( 'instagram' => 'https://www.instagram.com/ghoul.friend_13/?utm_source=qr' )
		);

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame(
			'ghoul.friend_13',
			$result['entry']['instagram'],
			'Pasted profile URLs are reduced to the bare handle before storage.'
		);
		$this->assertSame(
			'ghoul.friend_13',
			get_post_meta( $result['entry']['id'], EXTRACHILL_PHOTO_CONTEST_META_INSTAGRAM, true )
		);
	}

	public function test_submit_entry_enforces_one_entry_per_email_with_409_and_existing_entry() {
		$post_id = $this->createContestPost();

		$first = $this->submitEntry( $post_id );
		$this->assertFalse( is_wp_error( $first ) );

		$second = $this->submitEntry( $post_id, array( 'email' => 'GHOUL@example.com' ) );

		$this->assertDomainError( $second, 'already_entered', 409 );
		$this->assertSame(
			$first['entry']['id'],
			$second->get_error_data()['entry']['id'],
			'Duplicate submission returns the existing entry so the entrant can recover their share link.'
		);
		$this->assertCount( 1, extrachill_content_blocks_photo_contest_get_entries( $post_id, self::CONTEST_ID ) );
	}

	public function test_submit_entry_rejects_after_entries_close_with_403() {
		$close   = '2026-10-24 20:00';
		$post_id = $this->createContestPost( array( 'entriesCloseAt' => $close ) );

		$open = $this->submitEntry( $post_id, array( 'now' => strtotime( $close ) - 3600 ) );
		$this->assertFalse( is_wp_error( $open ) );

		$closed = $this->submitEntry(
			$post_id,
			array(
				'email' => 'late@example.com',
				'now'   => strtotime( $close ) + 3600,
			)
		);
		$this->assertDomainError( $closed, 'entries_closed', 403 );
	}

	public function test_submit_entry_validates_the_photo_with_400s() {
		$post_id = $this->createContestPost();

		$missing = $this->submitEntry( $post_id, array( 'file' => null ) );
		$this->assertDomainError( $missing, 'missing_photo', 400 );

		$oversized = $this->submitEntry(
			$post_id,
			array(
				'file' => array(
					'name'     => 'huge.jpg',
					'tmp_name' => '/tmp/phpXXXXXX',
					'size'     => EXTRACHILL_PHOTO_CONTEST_MAX_UPLOAD_BYTES + 1,
					'error'    => 0,
				),
			)
		);
		$this->assertDomainError( $oversized, 'photo_too_large', 400 );

		$bad_type = $this->submitEntry(
			$post_id,
			array(
				'file' => array(
					'name'     => 'malware.exe',
					'tmp_name' => '/tmp/phpXXXXXX',
					'size'     => 1024,
					'error'    => 0,
				),
			)
		);
		$this->assertDomainError( $bad_type, 'invalid_photo_type', 400 );

		$upload_error = $this->submitEntry(
			$post_id,
			array(
				'file' => array(
					'name'     => 'entry.jpg',
					'tmp_name' => '/tmp/phpXXXXXX',
					'size'     => 1024,
					'error'    => 4, // UPLOAD_ERR_NO_FILE.
				),
			)
		);
		$this->assertDomainError( $upload_error, 'upload_failed', 400 );
	}

	// ─── IP handling is gone ─────────────────────────────────────────────────

	public function test_domain_has_no_ip_rate_limiting() {
		$this->assertFalse(
			function_exists( 'extrachill_content_blocks_photo_contest_rate_limit_ok' ),
			'The transient submission limiter is removed; extrachill-api admission owns rate limiting.'
		);
		$this->assertFalse(
			function_exists( 'extrachill_content_blocks_photo_contest_vote_rate_limit_ok' ),
			'The transient vote limiter is removed; extrachill-api admission owns rate limiting.'
		);
		$this->assertFalse( defined( 'EXTRACHILL_PHOTO_CONTEST_RATE_LIMIT' ) );
		$this->assertFalse( defined( 'EXTRACHILL_PHOTO_CONTEST_RATE_WINDOW' ) );
		$this->assertFalse( defined( 'EXTRACHILL_PHOTO_CONTEST_VOTE_RATE_LIMIT' ) );
	}

	// ─── Storage filter seam ─────────────────────────────────────────────────

	public function test_store_photo_filter_short_circuits_storage() {
		$post_id = $this->createContestPost();

		// An earlier (lower-priority) filter wins over the default test store.
		$fixture_id = wp_stubs_insert_post(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'post_parent' => $post_id,
			)
		);
		add_filter(
			'extrachill_content_blocks_photo_contest_store_photo',
			static fn( $stored ) => $stored ?? $fixture_id,
			5
		);

		$result = $this->submitEntry( $post_id );

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( $fixture_id, $result['entry']['id'], 'A non-null filter return short-circuits photo storage.' );
	}

	public function test_store_photo_filter_can_short_circuit_with_a_wp_error() {
		$post_id = $this->createContestPost();

		$result = $this->submitEntry(
			$post_id,
			array(
				'file' => array(
					'name'                => 'entry.jpg',
					'tmp_name'            => '/tmp/phpXXXXXX',
					'size'                => 1024,
					'error'               => 0,
					'force_store_failure' => true,
				),
			)
		);

		$this->assertDomainError( $result, 'upload_failed', 400 );
		$this->assertSame(
			array(),
			extrachill_content_blocks_photo_contest_get_entries( $post_id, self::CONTEST_ID, true ),
			'A storage failure stores no entry.'
		);
	}

	public function test_store_photo_is_not_pluggable() {
		// The old test seam was a function_exists-guarded pluggable function;
		// the filter replaced it. The real function must exist unguarded.
		$this->assertTrue( function_exists( 'extrachill_content_blocks_photo_contest_store_photo' ) );
	}

	// ─── Newsletter context ──────────────────────────────────────────────────

	public function test_submit_entry_subscribes_with_the_photo_contest_context() {
		$post_id = $this->createContestPost();

		$result = $this->submitEntry( $post_id );

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame(
			array(
				'email'   => 'ghoul@example.com',
				'context' => 'photo_contest',
			),
			end( $GLOBALS['wp_test_subscriptions'] ),
			'Entry subscribe uses the dedicated photo_contest integration, not image_voting.'
		);
	}

	public function test_vote_subscribes_with_the_photo_contest_context() {
		$post_id  = $this->createContestPost();
		$entry_id = $this->submitEntry( $post_id )['entry']['id'];

		$result = extrachill_content_blocks_photo_contest_process_vote(
			$post_id,
			self::CONTEST_ID,
			$entry_id,
			'voter@example.com'
		);

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame(
			array(
				'email'   => 'voter@example.com',
				'context' => 'photo_contest',
			),
			end( $GLOBALS['wp_test_subscriptions'] ),
			'Vote subscribe uses the dedicated photo_contest integration.'
		);
	}

	public function test_plain_image_voting_block_keeps_the_image_voting_context() {
		$post_id = $this->createContestPost();

		$result = extrachill_content_blocks_process_image_vote( $post_id, 'block-123-456', 'voter@example.com' );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'image_voting', end( $GLOBALS['wp_test_subscriptions'] )['context'] );
	}

	public function test_photo_contest_newsletter_integration_is_registered() {
		$integrations = extrachill_content_blocks_photo_contest_register_newsletter_integration( array() );

		$this->assertArrayHasKey( 'photo_contest', $integrations );
		$this->assertSame( 'photo_contest_list_id', $integrations['photo_contest']['list_id_key'] );
		$this->assertSame( 'extrachill-content-blocks', $integrations['photo_contest']['plugin'] );
	}

	// ─── Duplicate photos ────────────────────────────────────────────────────

	public function test_submit_entry_rejects_exact_duplicate_photos_with_409() {
		$post_id = $this->createContestPost();
		$photo   = $this->createTempPhoto( 'identical-photo-bytes' );

		$first = $this->submitEntry(
			$post_id,
			array(
				'file' => array(
					'name'     => 'entry.jpg',
					'tmp_name' => $photo,
					'size'     => 1024,
					'error'    => 0,
				),
			)
		);
		$this->assertFalse( is_wp_error( $first ) );
		$this->assertSame(
			md5_file( $photo ),
			get_post_meta( $first['entry']['id'], EXTRACHILL_PHOTO_CONTEST_META_HASH, true ),
			'The content hash is stored as entry meta.'
		);

		// Same bytes in a different file, from a different entrant.
		$copy      = $this->createTempPhoto( 'identical-photo-bytes' );
		$duplicate = $this->submitEntry(
			$post_id,
			array(
				'email' => 'copycat@example.com',
				'file'  => array(
					'name'     => 'copy.jpg',
					'tmp_name' => $copy,
					'size'     => 1024,
					'error'    => 0,
				),
			)
		);

		$this->assertDomainError( $duplicate, 'duplicate_photo', 409 );
		$this->assertCount(
			1,
			extrachill_content_blocks_photo_contest_get_entries( $post_id, self::CONTEST_ID, true ),
			'The duplicate is rejected before any entry is stored.'
		);

		// Different bytes pass.
		$fresh  = $this->createTempPhoto( 'different-photo-bytes' );
		$result = $this->submitEntry(
			$post_id,
			array(
				'email' => 'original@example.com',
				'file'  => array(
					'name'     => 'fresh.jpg',
					'tmp_name' => $fresh,
					'size'     => 1024,
					'error'    => 0,
				),
			)
		);
		$this->assertFalse( is_wp_error( $result ), 'A different photo from a new email is accepted.' );
	}

	public function test_duplicate_photo_check_includes_hidden_entries() {
		$post_id = $this->createContestPost();
		$photo   = $this->createTempPhoto( 'hidden-photo-bytes' );

		$entry_id = $this->submitEntry(
			$post_id,
			array(
				'file' => array(
					'name'     => 'entry.jpg',
					'tmp_name' => $photo,
					'size'     => 1024,
					'error'    => 0,
				),
			)
		)['entry']['id'];

		extrachill_content_blocks_photo_contest_set_entry_hidden( $entry_id, true );

		$copy  = $this->createTempPhoto( 'hidden-photo-bytes' );
		$retry = $this->submitEntry(
			$post_id,
			array(
				'email' => 'copycat@example.com',
				'file'  => array(
					'name'     => 'copy.jpg',
					'tmp_name' => $copy,
					'size'     => 1024,
					'error'    => 0,
				),
			)
		);

		$this->assertDomainError( $retry, 'duplicate_photo', 409 );
	}

	public function test_unreadable_upload_skips_duplicate_check_instead_of_blocking() {
		$post_id = $this->createContestPost();

		// The default fixture tmp_name does not exist; both entries submit.
		$first = $this->submitEntry( $post_id );
		$this->assertFalse( is_wp_error( $first ) );
		$this->assertSame(
			'',
			get_post_meta( $first['entry']['id'], EXTRACHILL_PHOTO_CONTEST_META_HASH, true ),
			'No hash meta is stored when the upload cannot be hashed.'
		);

		$second = $this->submitEntry( $post_id, array( 'email' => 'second@example.com' ) );
		$this->assertFalse( is_wp_error( $second ), 'Unhashable uploads never trip the duplicate rejection.' );
	}

	// ─── Voting ──────────────────────────────────────────────────────────────

	public function test_vote_uses_entry_keyed_image_vote_backend_with_dedup() {
		$post_id  = $this->createContestPost();
		$entry_id = $this->submitEntry( $post_id )['entry']['id'];

		$first = extrachill_content_blocks_photo_contest_process_vote(
			$post_id,
			self::CONTEST_ID,
			$entry_id,
			'voter@example.com'
		);

		$this->assertFalse( is_wp_error( $first ) );
		$this->assertSame( 1, $first['vote_count'] );

		// The vote landed in the shared image_vote comment backend, keyed by
		// the entry-derived instance id.
		$instance_id = extrachill_content_blocks_photo_contest_entry_instance_id( self::CONTEST_ID, $entry_id );
		$this->assertSame( 1, extrachill_content_blocks_image_vote_count( $post_id, $instance_id ) );

		$repeat = extrachill_content_blocks_photo_contest_process_vote(
			$post_id,
			self::CONTEST_ID,
			$entry_id,
			'voter@example.com'
		);
		$this->assertDomainError( $repeat, 'already_voted', 409 );
	}

	public function test_same_email_can_vote_on_different_entries_but_not_twice_on_one() {
		$post_id = $this->createContestPost();
		$entry_a = $this->submitEntry( $post_id, array( 'email' => 'a@example.com' ) )['entry']['id'];
		$entry_b = $this->submitEntry( $post_id, array( 'email' => 'b@example.com' ) )['entry']['id'];

		$this->assertFalse(
			is_wp_error( extrachill_content_blocks_photo_contest_process_vote( $post_id, self::CONTEST_ID, $entry_a, 'voter@example.com' ) )
		);
		$this->assertFalse(
			is_wp_error( extrachill_content_blocks_photo_contest_process_vote( $post_id, self::CONTEST_ID, $entry_b, 'voter@example.com' ) ),
			'A voter may vote once per entry, across every entry.'
		);
	}

	public function test_vote_keys_do_not_collide_with_plain_image_voting_instances() {
		$post_id  = $this->createContestPost();
		$entry_id = $this->submitEntry( $post_id )['entry']['id'];

		// A plain image-voting block on the same post keeps its own bucket.
		$plain = extrachill_content_blocks_process_image_vote( $post_id, 'block-123-456', 'voter@example.com' );
		$this->assertTrue( $plain['success'] );

		$contest = extrachill_content_blocks_photo_contest_process_vote( $post_id, self::CONTEST_ID, $entry_id, 'voter@example.com' );
		$this->assertFalse( is_wp_error( $contest ), 'Same email, same post, different instance namespaces.' );

		$this->assertSame( 1, extrachill_content_blocks_image_vote_count( $post_id, 'block-123-456' ) );
	}

	public function test_vote_rejected_when_voting_closed_with_403() {
		$close    = '2026-10-24 22:00';
		$post_id  = $this->createContestPost( array( 'votingClosesAt' => $close ) );
		$entry_id = $this->submitEntry( $post_id )['entry']['id'];

		$result = extrachill_content_blocks_photo_contest_process_vote(
			$post_id,
			self::CONTEST_ID,
			$entry_id,
			'voter@example.com',
			strtotime( $close ) + 60
		);

		$this->assertDomainError( $result, 'voting_closed', 403 );
	}

	public function test_vote_rejected_for_unknown_contest_with_404() {
		$result = extrachill_content_blocks_photo_contest_process_vote( 999999, self::CONTEST_ID, 1, 'voter@example.com' );

		$this->assertDomainError( $result, 'contest_not_found', 404 );
	}

	public function test_vote_rejected_for_hidden_or_foreign_entries_with_404() {
		$post_id  = $this->createContestPost();
		$entry_id = $this->submitEntry( $post_id )['entry']['id'];

		extrachill_content_blocks_photo_contest_set_entry_hidden( $entry_id, true );

		$hidden = extrachill_content_blocks_photo_contest_process_vote( $post_id, self::CONTEST_ID, $entry_id, 'voter@example.com' );
		$this->assertDomainError( $hidden, 'entry_not_found', 404 );

		$unknown = extrachill_content_blocks_photo_contest_process_vote( $post_id, self::CONTEST_ID, 424242, 'voter@example.com' );
		$this->assertDomainError( $unknown, 'entry_not_found', 404 );
	}

	// ─── Moderation ──────────────────────────────────────────────────────────

	public function test_hide_entry_removes_it_from_public_list_and_unhide_restores() {
		$post_id  = $this->createContestPost();
		$entry_id = $this->submitEntry( $post_id )['entry']['id'];

		$this->assertCount( 1, extrachill_content_blocks_photo_contest_get_entries( $post_id, self::CONTEST_ID ) );

		$hide = extrachill_content_blocks_photo_contest_set_entry_hidden( $entry_id, true );
		$this->assertFalse( is_wp_error( $hide ) );
		$this->assertCount( 0, extrachill_content_blocks_photo_contest_get_entries( $post_id, self::CONTEST_ID ) );
		$this->assertCount(
			1,
			extrachill_content_blocks_photo_contest_get_entries( $post_id, self::CONTEST_ID, true ),
			'Admin views still see hidden entries.'
		);

		$restore = extrachill_content_blocks_photo_contest_set_entry_hidden( $entry_id, false );
		$this->assertFalse( is_wp_error( $restore ) );
		$this->assertCount( 1, extrachill_content_blocks_photo_contest_get_entries( $post_id, self::CONTEST_ID ) );
	}

	public function test_hide_rejects_non_entry_attachments_with_404() {
		$attachment_id = wp_stubs_insert_post(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			)
		);

		$result = extrachill_content_blocks_photo_contest_set_entry_hidden( $attachment_id, true );
		$this->assertDomainError( $result, 'entry_not_found', 404 );
	}

	public function test_hidden_entrant_cannot_resubmit_with_same_email() {
		$post_id  = $this->createContestPost();
		$entry_id = $this->submitEntry( $post_id )['entry']['id'];

		extrachill_content_blocks_photo_contest_set_entry_hidden( $entry_id, true );

		$retry = $this->submitEntry( $post_id );
		$this->assertDomainError( $retry, 'already_entered', 409 );
	}

	// ─── Entries list / top-N ────────────────────────────────────────────────

	public function test_public_entry_payload_never_contains_the_email() {
		$post_id = $this->createContestPost();
		$this->submitEntry( $post_id );

		$entries = extrachill_content_blocks_photo_contest_get_entries( $post_id, self::CONTEST_ID );

		$this->assertArrayNotHasKey( 'email', $entries[0] );
		$this->assertStringNotContainsString(
			'ghoul@example.com',
			(string) json_encode( $entries ),
			'Entrant emails never leave the server.'
		);
	}

	public function test_top_entries_ranks_by_votes_with_earliest_entry_winning_ties() {
		$post_id = $this->createContestPost();

		$entry_a = $this->submitEntry( $post_id, array( 'email' => 'a@example.com', 'display_name' => 'A' ) )['entry']['id'];
		$entry_b = $this->submitEntry( $post_id, array( 'email' => 'b@example.com', 'display_name' => 'B' ) )['entry']['id'];
		$entry_c = $this->submitEntry( $post_id, array( 'email' => 'c@example.com', 'display_name' => 'C' ) )['entry']['id'];

		// B: 2 votes, C: 1 vote, A: 1 vote (A entered before C → wins the tie).
		extrachill_content_blocks_photo_contest_process_vote( $post_id, self::CONTEST_ID, $entry_b, 'v1@example.com' );
		extrachill_content_blocks_photo_contest_process_vote( $post_id, self::CONTEST_ID, $entry_b, 'v2@example.com' );
		extrachill_content_blocks_photo_contest_process_vote( $post_id, self::CONTEST_ID, $entry_c, 'v1@example.com' );
		extrachill_content_blocks_photo_contest_process_vote( $post_id, self::CONTEST_ID, $entry_a, 'v2@example.com' );

		$top = extrachill_content_blocks_photo_contest_top_entries( $post_id, self::CONTEST_ID, 10 );
		$this->assertSame( array( $entry_b, $entry_a, $entry_c ), array_column( $top, 'id' ) );

		$top_two = extrachill_content_blocks_photo_contest_top_entries( $post_id, self::CONTEST_ID, 2 );
		$this->assertCount( 2, $top_two );
		$this->assertSame( $entry_b, $top_two[0]['id'] );
	}

	public function test_top_entries_excludes_hidden_by_default() {
		$post_id = $this->createContestPost();

		$entry_a = $this->submitEntry( $post_id, array( 'email' => 'a@example.com' ) )['entry']['id'];
		$entry_b = $this->submitEntry( $post_id, array( 'email' => 'b@example.com' ) )['entry']['id'];

		extrachill_content_blocks_photo_contest_process_vote( $post_id, self::CONTEST_ID, $entry_a, 'v1@example.com' );
		extrachill_content_blocks_photo_contest_set_entry_hidden( $entry_a, true );

		$top = extrachill_content_blocks_photo_contest_top_entries( $post_id, self::CONTEST_ID, 10 );
		$this->assertSame( array( $entry_b ), array_column( $top, 'id' ) );

		$with_hidden = extrachill_content_blocks_photo_contest_top_entries( $post_id, self::CONTEST_ID, 10, true );
		$this->assertSame( array( $entry_a, $entry_b ), array_column( $with_hidden, 'id' ) );
	}

	// ─── Render config ───────────────────────────────────────────────────────

	public function test_render_config_for_anonymous_visitors_has_no_moderator_data() {
		$post_id = $this->createContestPost();

		$config = extrachill_content_blocks_photo_contest_render_config(
			array( 'contestId' => self::CONTEST_ID ),
			$post_id
		);

		// Anonymous pages are full-page cached: the config must never vary
		// per visitor, so moderator keys are absent entirely (not false).
		$this->assertArrayNotHasKey( 'canModerate', $config );
		$this->assertArrayNotHasKey( 'restNonce', $config );
		$this->assertStringNotContainsString( 'canModerate', (string) json_encode( $config ) );
		$this->assertStringNotContainsString( 'restNonce', (string) json_encode( $config ) );

		$this->assertSame( $post_id, $config['postId'] );
		$this->assertSame( self::CONTEST_ID, $config['contestId'] );
		$this->assertFalse( $config['isAdmin'] );
	}

	public function test_render_config_adds_flag_and_nonce_for_moderators_only() {
		$post_id = $this->createContestPost();

		wp_stubs_set_user_caps( array( 'edit_others_posts' ) );
		$moderator = extrachill_content_blocks_photo_contest_render_config(
			array( 'contestId' => self::CONTEST_ID ),
			$post_id
		);

		$this->assertTrue( $moderator['canModerate'] );
		$this->assertNotSame( '', $moderator['restNonce'] );

		// A logged-in non-moderator gets the anonymous config too.
		wp_stubs_set_user_caps( array( 'read' ) );
		$subscriber = extrachill_content_blocks_photo_contest_render_config(
			array( 'contestId' => self::CONTEST_ID ),
			$post_id
		);
		$this->assertArrayNotHasKey( 'canModerate', $subscriber );
		$this->assertArrayNotHasKey( 'restNonce', $subscriber );
	}

	// ─── OG deep links ───────────────────────────────────────────────────────

	public function test_og_overrides_swap_image_title_and_url_for_entry_deep_links() {
		$post_id  = $this->createContestPost();
		$entry_id = $this->submitEntry( $post_id )['entry']['id'];

		$og = array(
			'og:title'        => 'Test Photo Contest',
			'og:image'        => 'https://example.com/featured.jpg',
			'og:image:width'  => 1200,
			'og:image:height' => 630,
		);

		$result = extrachill_content_blocks_photo_contest_og_overrides( $og, $post_id, $entry_id );

		$this->assertStringContainsString( 'entry-' . $entry_id, $result['og:image'], 'OG image becomes the entrant photo.' );
		$this->assertSame( 'Vote for Ghoul Friend!', $result['og:title'] );
		$this->assertStringContainsString( 'entry=' . $entry_id, $result['og:url'] );
		$this->assertArrayNotHasKey( 'og:image:width', $result, 'Stale featured-image dimensions are dropped.' );
	}

	public function test_og_overrides_ignore_hidden_or_foreign_entries() {
		$post_id  = $this->createContestPost();
		$entry_id = $this->submitEntry( $post_id )['entry']['id'];
		$og       = array( 'og:image' => 'https://example.com/featured.jpg' );

		extrachill_content_blocks_photo_contest_set_entry_hidden( $entry_id, true );
		$this->assertSame( $og, extrachill_content_blocks_photo_contest_og_overrides( $og, $post_id, $entry_id ) );

		$other_post = wp_stubs_insert_post( array( 'post_type' => 'post' ) );
		$this->assertSame( $og, extrachill_content_blocks_photo_contest_og_overrides( $og, $other_post, $entry_id ) );

		$this->assertSame( $og, extrachill_content_blocks_photo_contest_og_overrides( $og, $post_id, 999999 ) );
	}

	private function participantEvents(): array {
		return array_values(
			array_map(
				static fn( $call ) => $call['args'][0],
				array_filter( $GLOBALS['wp_test_actions'] ?? array(), static fn( $call ) => 'extrachill_content_blocks_photo_contest_participant' === $call['hook'] )
			)
		);
	}

	public function test_entry_and_first_vote_fire_participant_event_once_per_email() {
		$post_id = $this->createContestPost();
		$a       = $this->submitEntry( $post_id );
		$b       = $this->submitEntry( $post_id, array( 'email' => 'other@example.com', 'file' => array( 'name' => 'b.jpg', 'tmp_name' => '/tmp/phpB', 'size' => 2048, 'error' => 0 ) ) );
		$a_id    = (int) $a['entry']['id'];
		$b_id    = (int) $b['entry']['id'];

		extrachill_content_blocks_photo_contest_process_vote( $post_id, self::CONTEST_ID, $a_id, 'fan@example.com' );
		extrachill_content_blocks_photo_contest_process_vote( $post_id, self::CONTEST_ID, $b_id, 'fan@example.com' );

		$events = $this->participantEvents();
		$this->assertSame( array( 'entrant', 'entrant', 'voter' ), array_column( $events, 'role' ) );
		$this->assertSame( 'fan@example.com', $events[2]['email'] );
		$this->assertSame( $a_id, $events[2]['entry_id'] );
		$this->assertSame( self::CONTEST_ID, $events[2]['contest_id'] );
	}

	public function test_team_entry_without_email_fires_no_event() {
		wp_stubs_set_user_caps( array( 'edit_others_posts' => true ) );
		$post_id = $this->createContestPost();
		$this->submitEntry( $post_id, array( 'email' => '' ) );
		$this->assertSame( array(), $this->participantEvents() );
	}

	public function test_welcome_message_per_role_and_off_when_empty() {
		$settings = array(
			'welcome_subject'      => 'Thanks for joining 🎃',
			'welcome_entrant_body' => "Hi {name}! Your entry: {entry_link}\n\nCalendar: https://events.extrachill.com",
			'welcome_voter_body'   => 'You voted for {costume} <b>. More: {contest_link}',
		);
		$entry    = array( 'display_name' => 'Vampire <Elvis>', 'share_url' => 'https://example.com/c/?entry=5' );
		$event    = array( 'role' => 'entrant', 'email' => 'a@example.com' );

		$m = extrachill_content_blocks_photo_contest_welcome_message( $settings, $event, $entry, 'https://example.com/c/' );
		$this->assertSame( 'a@example.com', $m['to'] );
		$this->assertSame( 'Thanks for joining 🎃', $m['subject'] );
		$this->assertSame( 'extrachill/branded', $m['template'] );
		$this->assertSame( 'https://example.com/c/?entry=5', $m['context']['cta_url'] );
		$this->assertSame( 'Vampire <Elvis>', $m['context']['recipient_name'] );
		$this->assertStringContainsString( 'Hi Vampire &lt;Elvis&gt;!', $m['context']['body_html'] );
		$this->assertStringContainsString( '<a href="https://example.com/c/?entry=5">', $m['context']['body_html'] );
		$this->assertSame( 2, substr_count( $m['context']['body_html'], '<p>' ) );

		$v = extrachill_content_blocks_photo_contest_welcome_message( $settings, array( 'role' => 'voter', 'email' => 'v@example.com' ), $entry, 'https://example.com/c/' );
		$this->assertSame( 'https://example.com/c/', $v['context']['cta_url'] );
		$this->assertSame( '', $v['context']['recipient_name'] );
		$this->assertStringContainsString( '&lt;b&gt;', $v['context']['body_html'] );

		$settings['welcome_voter_body'] = '  ';
		$this->assertNull( extrachill_content_blocks_photo_contest_welcome_message( $settings, array( 'role' => 'voter', 'email' => 'v@example.com' ), $entry, 'https://example.com/c/' ) );
	}

	public function test_welcome_message_includes_claim_link_for_unclaimed_accounts() {
		$settings = array( 'welcome_voter_body' => 'Thanks for voting for {costume}.' );
		$entry    = array( 'display_name' => 'Mothman', 'share_url' => 'https://example.com/c/?entry=5' );
		$event    = array( 'role' => 'voter', 'email' => 'v@example.com' );

		$m = extrachill_content_blocks_photo_contest_welcome_message( $settings, $event, $entry, 'https://example.com/c/', 'https://community.example.com/claim?key=abc' );
		$this->assertStringContainsString( 'Set your password</a>', $m['context']['body_html'] );
		$this->assertStringContainsString( 'href="https://community.example.com/claim?key=abc"', $m['context']['body_html'] );

		// Copy placing {claim_link} itself gets no extra paragraph.
		$settings['welcome_voter_body'] = 'Claim: {claim_link}';
		$m = extrachill_content_blocks_photo_contest_welcome_message( $settings, $event, $entry, 'https://example.com/c/', 'https://community.example.com/claim?key=abc' );
		$this->assertStringNotContainsString( 'Set your password', $m['context']['body_html'] );

		// Claimed accounts (no claim link) get nothing extra.
		$m = extrachill_content_blocks_photo_contest_welcome_message( array( 'welcome_voter_body' => 'Hi.' ), $event, $entry, 'https://example.com/c/' );
		$this->assertStringNotContainsString( 'password', $m['context']['body_html'] );
	}

	public function test_participants_resolve_to_an_account_through_the_platform_primitive() {
		$calls = array();
		wp_register_ability(
			'extrachill/resolve-account-by-email',
			array(
				'execute_callback'    => static function ( $input ) use ( &$calls ) {
					$calls[] = $input;
					return array( 'user_id' => 9, 'claim_url' => 'https://community.example.com/claim?key=k' );
				},
				'permission_callback' => '__return_true',
			)
		);
		$this->assertSame( 'https://community.example.com/claim?key=k', extrachill_content_blocks_photo_contest_resolve_account( 'new@example.com' ) );
		extrachill_content_blocks_photo_contest_ensure_account( array( 'email' => 'new@example.com' ) );
		$this->assertCount( 1, $calls, 'cached per request' );
		$this->assertSame( 'photo_contest', $calls[0]['registration_source'] );
		unset( $GLOBALS['wp_test_abilities']['extrachill/resolve-account-by-email'] );
	}

	public function test_entrant_who_votes_gets_no_second_participant_event() {
		$post_id = $this->createContestPost();
		$a       = $this->submitEntry( $post_id );
		$b       = $this->submitEntry( $post_id, array( 'email' => 'other@example.com', 'file' => array( 'name' => 'b.jpg', 'tmp_name' => '/tmp/phpB', 'size' => 2048, 'error' => 0 ) ) );

		// ghoul@example.com entered, then votes (case differs): no voter event.
		$vote = extrachill_content_blocks_photo_contest_process_vote( $post_id, self::CONTEST_ID, (int) $b['entry']['id'], 'Ghoul@Example.com' );
		$this->assertFalse( is_wp_error( $vote ) );
		$this->assertSame( array( 'entrant', 'entrant' ), array_column( $this->participantEvents(), 'role' ) );
	}

	public function test_welcome_markdown_links_claim_placement_and_dropped_claim_copy() {
		$settings = array(
			'welcome_entrant_body' => "Your costume is in! Share [your entry]({entry_link}).\n\nCalendar: [Charleston shows](https://events.example.com/charleston)\n\nBye.",
		);
		$entry = array( 'display_name' => 'Mothman', 'share_url' => 'https://example.com/c/?entry=5' );
		$event = array( 'role' => 'entrant', 'email' => 'a@example.com' );

		$m    = extrachill_content_blocks_photo_contest_welcome_message( $settings, $event, $entry, 'https://example.com/c/', 'https://community.example.com/reset?key=k&login=a' );
		$html = $m['context']['body_html'];
		$this->assertStringContainsString( '<a href="https://example.com/c/?entry=5">your entry</a>', $html );
		$this->assertStringContainsString( '<a href="https://events.example.com/charleston">Charleston shows</a>', $html );
		$this->assertStringNotContainsString( '>https://', $html, 'no raw URL text' );
		$paras = explode( '</p>', $html );
		$this->assertStringContainsString( 'Set your password</a>', $paras[1], 'claim line is the second paragraph' );
		$this->assertStringContainsString( 'key=k&amp;login=a', $html );

		// Copy that places {claim_link} is dropped when there is no claim link.
		$settings['welcome_entrant_body'] = "Hi.\n\n[Set a password]({claim_link}) to keep it.\n\nBye.";
		$m = extrachill_content_blocks_photo_contest_welcome_message( $settings, $event, $entry, 'https://example.com/c/', '' );
		$this->assertSame( '<p>Hi.</p><p>Bye.</p>', $m['context']['body_html'] );
	}
}
