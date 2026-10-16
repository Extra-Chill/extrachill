<?php
/**
 * Photo Contest business-logic tests.
 *
 * Exercises the canonical logic in src/blocks/photo-contest/index.php against
 * the in-memory WordPress stubs: email-gated submission with dedup/honeypot/
 * rate-limit/window enforcement, vote keying on the shared image_vote comment
 * backend, moderation (hide/restore), top-N ranking, and OG deep-link
 * overrides.
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
				'post_title' => 'Skinful Halloween Costume Contest',
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
	 * @return array Submission result.
	 */
	private function submitEntry( int $post_id, array $overrides = array() ): array {
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
						'name'     => 'costume.jpg',
						'tmp_name' => '/tmp/phpXXXXXX',
						'size'     => 1024 * 1024,
						'error'    => 0,
					),
					'ip'           => '',
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

	public function test_close_window_logic() {
		$close = '2026-10-24 20:00';
		$this->assertFalse( extrachill_content_blocks_photo_contest_is_closed( '', time() ) );
		$this->assertFalse( extrachill_content_blocks_photo_contest_is_closed( $close, strtotime( $close ) - 60 ) );
		$this->assertTrue( extrachill_content_blocks_photo_contest_is_closed( $close, strtotime( $close ) + 60 ) );
	}

	// ─── Submission ──────────────────────────────────────────────────────────

	public function test_submit_entry_success_stores_entry_with_meta_and_share_url() {
		$post_id = $this->createContestPost();

		$result = $this->submitEntry( $post_id );

		$this->assertTrue( $result['success'] );
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

	public function test_submit_entry_rejects_honeypot() {
		$post_id = $this->createContestPost();

		$result = $this->submitEntry( $post_id, array( 'honeypot' => 'http://spam.example' ) );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'invalid_submission', $result['code'] );
		$this->assertSame( array(), extrachill_content_blocks_photo_contest_get_entries( $post_id, self::CONTEST_ID ) );
	}

	public function test_submit_entry_rejects_invalid_email_and_missing_name() {
		$post_id = $this->createContestPost();

		$bad_email = $this->submitEntry( $post_id, array( 'email' => 'not-an-email' ) );
		$this->assertSame( 'invalid_email', $bad_email['code'] );

		$no_name = $this->submitEntry( $post_id, array( 'display_name' => '   ' ) );
		$this->assertSame( 'missing_name', $no_name['code'] );
	}

	/**
	 * The stored handle is rendered verbatim into instagram.com profile links
	 * on the frontend and into CLI output, so normalization must reduce any
	 * input shape to Instagram's valid charset.
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

		$this->assertTrue( $result['success'] );
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

	public function test_submit_entry_enforces_one_entry_per_email_case_insensitively() {
		$post_id = $this->createContestPost();

		$first = $this->submitEntry( $post_id );
		$this->assertTrue( $first['success'] );

		$second = $this->submitEntry( $post_id, array( 'email' => 'GHOUL@example.com' ) );

		$this->assertFalse( $second['success'] );
		$this->assertSame( 'already_entered', $second['code'] );
		$this->assertSame(
			$first['entry']['id'],
			$second['entry']['id'],
			'Duplicate submission returns the existing entry so the entrant can recover their share link.'
		);
		$this->assertCount( 1, extrachill_content_blocks_photo_contest_get_entries( $post_id, self::CONTEST_ID ) );
	}

	public function test_submit_entry_rejects_after_entries_close() {
		$close   = '2026-10-24 20:00';
		$post_id = $this->createContestPost( array( 'entriesCloseAt' => $close ) );

		$open = $this->submitEntry( $post_id, array( 'now' => strtotime( $close ) - 3600 ) );
		$this->assertTrue( $open['success'] );

		$closed = $this->submitEntry(
			$post_id,
			array(
				'email' => 'late@example.com',
				'now'   => strtotime( $close ) + 3600,
			)
		);
		$this->assertSame( 'entries_closed', $closed['code'] );
	}

	public function test_submit_entry_validates_the_photo() {
		$post_id = $this->createContestPost();

		$missing = $this->submitEntry( $post_id, array( 'file' => null ) );
		$this->assertSame( 'missing_photo', $missing['code'] );

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
		$this->assertSame( 'photo_too_large', $oversized['code'] );

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
		$this->assertSame( 'invalid_photo_type', $bad_type['code'] );

		$upload_error = $this->submitEntry(
			$post_id,
			array(
				'file' => array(
					'name'     => 'costume.jpg',
					'tmp_name' => '/tmp/phpXXXXXX',
					'size'     => 1024,
					'error'    => 4, // UPLOAD_ERR_NO_FILE.
				),
			)
		);
		$this->assertSame( 'upload_failed', $upload_error['code'] );
	}

	public function test_rate_limit_ceiling_is_venue_wifi_safe() {
		// A whole venue shares one IP behind its wifi or carrier NAT; email
		// dedup is the real control. 5 was too low for contest night.
		$this->assertSame( 20, EXTRACHILL_PHOTO_CONTEST_RATE_LIMIT );
	}

	public function test_submit_entry_rate_limits_per_ip() {
		$post_id = $this->createContestPost();

		for ( $i = 0; $i < EXTRACHILL_PHOTO_CONTEST_RATE_LIMIT; $i++ ) {
			$result = $this->submitEntry(
				$post_id,
				array(
					'email' => "guest{$i}@example.com",
					'ip'    => '203.0.113.9',
				)
			);
			$this->assertTrue( $result['success'], "Submission {$i} inside the window should pass." );
		}

		$blocked = $this->submitEntry(
			$post_id,
			array(
				'email' => 'onemore@example.com',
				'ip'    => '203.0.113.9',
			)
		);
		$this->assertSame( 'rate_limited', $blocked['code'] );

		$other_ip = $this->submitEntry(
			$post_id,
			array(
				'email' => 'other@example.com',
				'ip'    => '198.51.100.7',
			)
		);
		$this->assertTrue( $other_ip['success'], 'The limit is per IP.' );
	}

	// ─── Voting ──────────────────────────────────────────────────────────────

	// ─── Duplicate photos ───────────────────────────────────────────────────────────

	public function test_submit_entry_rejects_exact_duplicate_photos() {
		$post_id = $this->createContestPost();
		$photo   = $this->createTempPhoto( 'identical-costume-bytes' );

		$first = $this->submitEntry(
			$post_id,
			array(
				'file' => array(
					'name'     => 'costume.jpg',
					'tmp_name' => $photo,
					'size'     => 1024,
					'error'    => 0,
				),
			)
		);
		$this->assertTrue( $first['success'] );
		$this->assertSame(
			md5_file( $photo ),
			get_post_meta( $first['entry']['id'], EXTRACHILL_PHOTO_CONTEST_META_HASH, true ),
			'The content hash is stored as entry meta.'
		);

		// Same bytes in a different file, from a different entrant.
		$copy      = $this->createTempPhoto( 'identical-costume-bytes' );
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

		$this->assertFalse( $duplicate['success'] );
		$this->assertSame( 'duplicate_photo', $duplicate['code'] );
		$this->assertCount(
			1,
			extrachill_content_blocks_photo_contest_get_entries( $post_id, self::CONTEST_ID, true ),
			'The duplicate is rejected before any entry is stored.'
		);

		// Different bytes pass.
		$fresh  = $this->createTempPhoto( 'different-costume-bytes' );
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
		$this->assertTrue( $result['success'], 'A different photo from a new email is accepted.' );
	}

	public function test_duplicate_photo_check_includes_hidden_entries() {
		$post_id = $this->createContestPost();
		$photo   = $this->createTempPhoto( 'hidden-costume-bytes' );

		$entry_id = $this->submitEntry(
			$post_id,
			array(
				'file' => array(
					'name'     => 'costume.jpg',
					'tmp_name' => $photo,
					'size'     => 1024,
					'error'    => 0,
				),
			)
		)['entry']['id'];

		extrachill_content_blocks_photo_contest_set_entry_hidden( $entry_id, true );

		$copy  = $this->createTempPhoto( 'hidden-costume-bytes' );
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

		$this->assertSame( 'duplicate_photo', $retry['code'], 'Hiding a photo must not let the identical file back in.' );
	}

	public function test_unreadable_upload_skips_duplicate_check_instead_of_blocking() {
		$post_id = $this->createContestPost();

		// The default fixture tmp_name does not exist; both entries submit.
		$first = $this->submitEntry( $post_id );
		$this->assertTrue( $first['success'] );
		$this->assertSame(
			'',
			get_post_meta( $first['entry']['id'], EXTRACHILL_PHOTO_CONTEST_META_HASH, true ),
			'No hash meta is stored when the upload cannot be hashed.'
		);

		$second = $this->submitEntry( $post_id, array( 'email' => 'second@example.com' ) );
		$this->assertTrue( $second['success'], 'Unhashable uploads never trip the duplicate rejection.' );
	}

	public function test_vote_uses_entry_keyed_image_vote_backend_with_dedup() {
		$post_id  = $this->createContestPost();
		$entry_id = $this->submitEntry( $post_id )['entry']['id'];

		$first = extrachill_content_blocks_photo_contest_process_vote(
			$post_id,
			self::CONTEST_ID,
			$entry_id,
			'voter@example.com'
		);

		$this->assertTrue( $first['success'] );
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
		$this->assertFalse( $repeat['success'] );
		$this->assertSame( 'already_voted', $repeat['code'] );
	}

	public function test_same_email_can_vote_on_different_entries_but_not_twice_on_one() {
		$post_id  = $this->createContestPost();
		$entry_a  = $this->submitEntry( $post_id, array( 'email' => 'a@example.com' ) )['entry']['id'];
		$entry_b  = $this->submitEntry( $post_id, array( 'email' => 'b@example.com' ) )['entry']['id'];

		$this->assertTrue(
			extrachill_content_blocks_photo_contest_process_vote( $post_id, self::CONTEST_ID, $entry_a, 'voter@example.com' )['success']
		);
		$this->assertTrue(
			extrachill_content_blocks_photo_contest_process_vote( $post_id, self::CONTEST_ID, $entry_b, 'voter@example.com' )['success'],
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
		$this->assertTrue( $contest['success'], 'Same email, same post, different instance namespaces.' );

		$this->assertSame( 1, extrachill_content_blocks_image_vote_count( $post_id, 'block-123-456' ) );
	}

	public function test_vote_rejected_when_voting_closed() {
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

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'voting_closed', $result['code'] );
	}

	public function test_vote_rejected_for_hidden_or_foreign_entries() {
		$post_id  = $this->createContestPost();
		$entry_id = $this->submitEntry( $post_id )['entry']['id'];

		extrachill_content_blocks_photo_contest_set_entry_hidden( $entry_id, true );

		$hidden = extrachill_content_blocks_photo_contest_process_vote( $post_id, self::CONTEST_ID, $entry_id, 'voter@example.com' );
		$this->assertSame( 'entry_not_found', $hidden['code'] );

		$unknown = extrachill_content_blocks_photo_contest_process_vote( $post_id, self::CONTEST_ID, 424242, 'voter@example.com' );
		$this->assertSame( 'entry_not_found', $unknown['code'] );
	}

	// ─── Moderation ──────────────────────────────────────────────────────────

	public function test_hide_entry_removes_it_from_public_list_and_unhide_restores() {
		$post_id  = $this->createContestPost();
		$entry_id = $this->submitEntry( $post_id )['entry']['id'];

		$this->assertCount( 1, extrachill_content_blocks_photo_contest_get_entries( $post_id, self::CONTEST_ID ) );

		$hide = extrachill_content_blocks_photo_contest_set_entry_hidden( $entry_id, true );
		$this->assertTrue( $hide['success'] );
		$this->assertCount( 0, extrachill_content_blocks_photo_contest_get_entries( $post_id, self::CONTEST_ID ) );
		$this->assertCount(
			1,
			extrachill_content_blocks_photo_contest_get_entries( $post_id, self::CONTEST_ID, true ),
			'Admin views still see hidden entries.'
		);

		$restore = extrachill_content_blocks_photo_contest_set_entry_hidden( $entry_id, false );
		$this->assertTrue( $restore['success'] );
		$this->assertCount( 1, extrachill_content_blocks_photo_contest_get_entries( $post_id, self::CONTEST_ID ) );
	}

	public function test_hide_rejects_non_entry_attachments() {
		$attachment_id = wp_stubs_insert_post(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			)
		);

		$result = extrachill_content_blocks_photo_contest_set_entry_hidden( $attachment_id, true );
		$this->assertSame( 'entry_not_found', $result['code'] );
	}

	public function test_hidden_entrant_cannot_resubmit_with_same_email() {
		$post_id  = $this->createContestPost();
		$entry_id = $this->submitEntry( $post_id )['entry']['id'];

		extrachill_content_blocks_photo_contest_set_entry_hidden( $entry_id, true );

		$retry = $this->submitEntry( $post_id );
		$this->assertSame( 'already_entered', $retry['code'] );
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

	// ─── OG deep links ───────────────────────────────────────────────────────

	// ─── Render config ───────────────────────────────────────────────────────────────

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

	public function test_og_overrides_swap_image_title_and_url_for_entry_deep_links() {
		$post_id  = $this->createContestPost();
		$entry_id = $this->submitEntry( $post_id )['entry']['id'];

		$og = array(
			'og:title'        => 'Skinful Halloween Costume Contest',
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
}
