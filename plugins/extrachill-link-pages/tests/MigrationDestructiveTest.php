<?php
/**
 * Destructive and fault-injection migration tests.
 *
 * @package ExtraChillLinkPages
 */

use PHPUnit\Framework\TestCase;

/** Exercise the complete migration lifecycle against the stateful fixture. */
final class MigrationDestructiveTest extends TestCase {
	protected function setUp(): void {
		ec_test_reset();
	}

	private function post( $id, $type, $slug, $status = 'publish', $parent = 0 ) {
		return (object) array(
			'ID'                    => $id,
			'post_author'           => '1',
			'post_date'             => '2026-01-02 03:04:05',
			'post_date_gmt'         => '2026-01-02 08:04:05',
			'post_content'          => 'Content ' . $id,
			'post_title'            => 'Title ' . $id,
			'post_excerpt'          => 'Excerpt ' . $id,
			'post_status'           => $status,
			'comment_status'        => 'closed',
			'ping_status'           => 'closed',
			'post_password'         => '',
			'post_name'             => $slug,
			'to_ping'               => '',
			'pinged'                => '',
			'post_modified'         => '2026-02-03 04:05:06',
			'post_modified_gmt'     => '2026-02-03 09:05:06',
			'post_content_filtered' => '',
			'post_parent'           => $parent,
			'guid'                  => 'https://source.test/?p=' . $id,
			'menu_order'            => 0,
			'post_type'             => $type,
			'post_mime_type'        => 'attachment' === $type ? 'image/jpeg' : '',
		);
	}

	private function addMeta( $blog_id, $post_id, $key, $value ): void {
		$raw     = is_array( $value ) || is_object( $value ) ? serialize( $value ) : (string) $value;
		$meta_id = ++$GLOBALS['ec_test']['next_meta_id'];
		$GLOBALS['ec_test']['meta_rows'][ $meta_id ]                                = array(
			'blog_id'    => $blog_id,
			'post_id'    => $post_id,
			'meta_key'   => $key,
			'meta_value' => $raw,
		);
		$GLOBALS['ec_test']['blogs'][ $blog_id ]['post_meta'][ $post_id ][ $key ][] = $value;
	}

	private function registerParticipant( $plan = null ): void {
		$callbacks = array(
			'claim_owner' => static function () {
				return true;
			},
			'plan'        => $plan ?: static function () {
				return array(
					'fingerprint'    => hash( 'sha256', 'fixture-plan' ),
					'attachment_ids' => array(),
				);
			},
			'apply'       => static function () {
				$GLOBALS['ec_test']['participant_apply_calls'][] = true;
				return true;
			},
			'validate'    => static function () {
				$GLOBALS['ec_test']['participant_validate_calls'][] = true;
				return true;
			},
			'rollback'    => static function () {
				$GLOBALS['ec_test']['participant_rollback_calls'][] = true;
				return true;
			},
		);
		$this->assertTrue( ec_register_link_page_migration_participant( 'owner-adapter', '1', $callbacks ) );
	}

	private function seedCompleteInventory(): array {
		$statuses = array(
			40 => 'publish',
			41 => 'draft',
			42 => 'private',
			43 => 'trash',
		);
		foreach ( $statuses as $link_page_id => $status ) {
			$profile_id = $link_page_id + 100;
			$GLOBALS['ec_test']['blogs'][4]['posts'][ $profile_id ]   = $this->post( $profile_id, 'profile', 'profile-' . $profile_id );
			$GLOBALS['ec_test']['blogs'][4]['posts'][ $link_page_id ] = $this->post( $link_page_id, EC_LINK_PAGE_POST_TYPE, 'page-' . $link_page_id, $status );
			$this->addMeta( 4, $link_page_id, EC_LINK_PAGE_OWNER_META_KEY, 'post:4:profile:' . $profile_id );
		}
		$this->addMeta( 4, 40, '_thumbnail_id', '60' );
		$this->addMeta( 4, 40, 'duplicate', 'first' );
		$this->addMeta( 4, 40, 'duplicate', 'second' );
		$GLOBALS['ec_test']['blogs'][4]['posts'][60] = $this->post( 60, 'attachment', 'cover', 'inherit', 40 );
		$metadata                                    = array(
			'file'                  => '2026/cover.jpg',
			'sizes'                 => array( 'small' => array( 'file' => 'cover-300.jpg' ) ),
			'original_image'        => 'cover-original.jpg',
			'thumb'                 => 'cover-thumb.jpg',
			'source_image'          => 'cover-source.jpg',
			'animated_video'        => 'cover.mp4',
			'animated_video_poster' => 'cover-poster.jpg',
		);
		$this->addMeta( 4, 60, '_wp_attached_file', '2026/cover.jpg' );
		$this->addMeta( 4, 60, '_wp_attachment_metadata', $metadata );
		$this->addMeta( 4, 60, '_wp_attachment_backup_sizes', array( 'full' => array( 'file' => 'cover-backup.jpg' ) ) );
		$files = array( 'cover.jpg', 'cover-300.jpg', 'cover-original.jpg', 'cover-thumb.jpg', 'cover-source.jpg', 'cover.mp4', 'cover-poster.jpg', 'cover-backup.jpg' );
		mkdir( sys_get_temp_dir() . '/ec-link-pages-blog-4/2026', 0777, true );
		foreach ( $files as $file ) {
			file_put_contents( sys_get_temp_dir() . '/ec-link-pages-blog-4/2026/' . $file, 'bytes:' . $file );
		}
		$this->registerParticipant();
		return $files;
	}

	private function planAndApply(): array {
		$this->seedCompleteInventory();
		$plan = ec_plan_link_page_storage_migration( 4, 7 );
		$this->assertFalse( is_wp_error( $plan ), is_wp_error( $plan ) ? $plan->get_error_message() : '' );
		$this->assertTrue( $plan['ready'], wp_json_encode( $plan ) );
		$result = ec_apply_link_page_storage_migration( 4, 7, $plan['fingerprint'] );
		$this->assertFalse( is_wp_error( $result ), is_wp_error( $result ) ? $result->get_error_message() : '' );
		return array( $plan, $result );
	}

	public function test_complete_plan_apply_validate_and_rollback_preserves_exact_inventory(): void {
		$files = $this->seedCompleteInventory();
		$plan  = ec_plan_link_page_storage_migration( 4, 7 );
		$this->assertSame( array( 'publish', 'draft', 'private', 'trash' ), array_column( $plan['posts'], 'post_status' ) );
		$this->assertSame( 'inherit', $plan['attachments'][0]['post']['post_status'] );
		$this->assertCount( 8, $plan['attachments'][0]['files'] );
		$result = ec_apply_link_page_storage_migration( 4, 7, $plan['fingerprint'] );
		$this->assertSame( 'applied', $result['status'] );
		$this->assertSame( '2026-02-03 04:05:06', $GLOBALS['ec_test']['blogs'][7]['posts'][40]->post_modified );
		$this->assertSame( array( 'first', 'second' ), $GLOBALS['ec_test']['blogs'][7]['post_meta'][40]['duplicate'] );
		$this->assertContains( array( 40, 'post_meta' ), $GLOBALS['ec_test']['deleted_caches'] );
		foreach ( $files as $file ) {
			$this->assertFileExists( sys_get_temp_dir() . '/ec-link-pages-blog-7/2026/' . $file ); }
		$this->assertSame( 'valid', ec_validate_link_page_storage_migration( $result['journal_id'] )['status'] );
		$this->assertSame( 'rolled_back', ec_rollback_link_page_storage_migration( $result['journal_id'] )['status'] );
		$this->assertSame( array(), $GLOBALS['ec_test']['blogs'][7]['posts'] );
		foreach ( $files as $file ) {
			$this->assertFileDoesNotExist( sys_get_temp_dir() . '/ec-link-pages-blog-7/2026/' . $file ); }
		$this->assertTrue( ec_rollback_link_page_storage_migration( $result['journal_id'] )['idempotent'] );
	}

	public function test_import_fallback_race_never_deletes_requested_occupied_id(): void {
		$this->seedCompleteInventory();
		$plan                                     = ec_plan_link_page_storage_migration( 4, 7 );
		$GLOBALS['ec_test']['before_insert_post'] = function () {
			$GLOBALS['ec_test']['blogs'][7]['posts'][40] = $this->post( 40, 'post', 'user-owned' );
		};
		$result                                   = ec_apply_link_page_storage_migration( 4, 7, $plan['fingerprint'] );
		$this->assertSame( 'link_page_migration_apply_failed', $result->get_error_code() );
		$this->assertSame( 'user-owned', $GLOBALS['ec_test']['blogs'][7]['posts'][40]->post_name );
		$this->assertCount( 1, $GLOBALS['ec_test']['blogs'][7]['posts'] );
	}

	public function test_token_interruption_before_actual_id_persistence_is_compensated(): void {
		$this->seedCompleteInventory();
		$plan                                    = ec_plan_link_page_storage_migration( 4, 7 );
		$GLOBALS['ec_test']['after_insert_post'] = static function () {
			throw new RuntimeException( 'interrupt after insert' );
		};
		$result                                  = ec_apply_link_page_storage_migration( 4, 7, $plan['fingerprint'] );
		$this->assertSame( 'link_page_migration_apply_failed', $result->get_error_code() );
		$this->assertSame( array(), $GLOBALS['ec_test']['blogs'][7]['posts'] );
	}

	public function test_actual_post_id_journal_failure_immediately_deletes_token_owned_insert(): void {
		$this->seedCompleteInventory();
		$plan = ec_plan_link_page_storage_migration( 4, 7 );
		$GLOBALS['ec_test']['after_insert_post'] = static function () {
			$GLOBALS['ec_test']['fail_network_option_write_calls'][] = $GLOBALS['ec_test']['network_option_write_calls'] + 1;
		};
		$result = ec_apply_link_page_storage_migration( 4, 7, $plan['fingerprint'] );
		$this->assertSame( 'link_page_migration_apply_failed', $result->get_error_code() );
		$this->assertSame( array(), $GLOBALS['ec_test']['blogs'][7]['posts'] );
	}

	public function test_raw_meta_id_journal_failure_immediately_deletes_inserted_row(): void {
		$this->seedCompleteInventory();
		$plan = ec_plan_link_page_storage_migration( 4, 7 );
		$GLOBALS['ec_test']['after_raw_meta_insert'] = static function () {
			$GLOBALS['ec_test']['fail_network_option_write_calls'][] = $GLOBALS['ec_test']['network_option_write_calls'] + 1;
		};
		$result = ec_apply_link_page_storage_migration( 4, 7, $plan['fingerprint'] );
		$this->assertSame( 'link_page_migration_apply_failed', $result->get_error_code() );
		$this->assertSame( array(), $GLOBALS['ec_test']['blogs'][7]['posts'] );
		$this->assertSame( array(), array_filter( $GLOBALS['ec_test']['meta_rows'], static function ( $row ) { return 7 === (int) $row['blog_id']; } ) );
	}

	public function test_phase_mismatch_halts_destructive_rollback(): void {
		list( , $result )                                        = $this->planAndApply();
		$GLOBALS['ec_test']['blogs'][7]['posts'][40]->post_title = 'User edit';
		$rollback = ec_rollback_link_page_storage_migration( $result['journal_id'] );
		$this->assertSame( 'link_page_migration_rollback_incomplete', $rollback->get_error_code() );
		$this->assertSame( 'User edit', $GLOBALS['ec_test']['blogs'][7]['posts'][40]->post_title );
	}

	public function test_applied_journal_requires_owner_participant_for_validate_and_rollback(): void {
		list( , $result ) = $this->planAndApply();
		$registry         = ec_link_page_migration_participant_registry();
		$reflection       = new ReflectionObject( $registry );
		$property         = $reflection->getProperty( 'participants' );
		$property->setAccessible( true );
		$property->setValue( $registry, array() );
		$this->assertSame( 'link_page_migration_participant_contract_missing', ec_validate_link_page_storage_migration( $result['journal_id'] )->get_error_code() );
		$this->assertSame( 'link_page_migration_participant_contract_missing', ec_rollback_link_page_storage_migration( $result['journal_id'] )->get_error_code() );
		$this->assertNotEmpty( $GLOBALS['ec_test']['blogs'][7]['posts'] );
	}

	public function test_operation_lock_and_status_cas_reject_lifecycle_races(): void {
		$this->seedCompleteInventory();
		$plan                                     = ec_plan_link_page_storage_migration( 4, 7 );
		$GLOBALS['ec_test']['fail_advisory_lock'] = true;
		$this->assertSame( 'link_page_migration_operation_lock_failed', ec_apply_link_page_storage_migration( 4, 7, $plan['fingerprint'] )->get_error_code() );
		unset( $GLOBALS['ec_test']['fail_advisory_lock'] );
		$journal = array(
			'id'         => 'cas',
			'network_id' => 1,
			'status'     => 'applied',
			'created_at' => '2026-01-01',
			'entries'    => array(),
		);
		$this->assertTrue( ec_link_page_migration_store_journal( $journal ) );
		$GLOBALS['ec_test']['site_options'][ ec_link_page_migration_journal_key( 'cas' ) ]['status'] = 'rolling_back';
		$this->assertSame( 'link_page_migration_status_race', ec_link_page_migration_transition_status( $journal, array( 'applied' ), 'rolling_back' )->get_error_code() );
	}

	public function test_required_participant_merge_is_stable_and_contract_safe(): void {
		$merged = ec_link_page_migration_merge_required_participants(
			array(
				array(
					array(
						'id'               => 'owner',
						'contract_version' => '1',
					),
					array(
						'id'               => 'caller-extension',
						'contract_version' => '2',
					),
				),
				array(
					array(
						'id'               => 'owner',
						'contract_version' => '1',
					),
				),
			)
		);
		$this->assertSame( array( 'owner', 'caller-extension' ), array_column( $merged, 'id' ) );
		$this->assertSame(
			'link_page_migration_participant_contract_conflict',
			ec_link_page_migration_merge_required_participants(
				array(
					$merged,
					array(
						array(
							'id'               => 'owner',
							'contract_version' => '2',
						),
					),
				)
			)->get_error_code()
		);
	}

	public function test_external_attachment_parent_is_blocked_without_exact_owner_mapping(): void {
		$GLOBALS['ec_test']['blogs'][4]['posts'][140] = $this->post( 140, 'profile', 'profile-140' );
		$GLOBALS['ec_test']['blogs'][4]['posts'][40]  = $this->post( 40, EC_LINK_PAGE_POST_TYPE, 'page-40' );
		$GLOBALS['ec_test']['blogs'][4]['posts'][60]  = $this->post( 60, 'attachment', 'cover', 'inherit', 999 );
		$this->addMeta( 4, 40, EC_LINK_PAGE_OWNER_META_KEY, 'post:4:profile:140' );
		$this->addMeta( 4, 40, '_thumbnail_id', '60' );
		$this->addMeta( 4, 60, '_wp_attached_file', 'cover.jpg' );
		file_put_contents( sys_get_temp_dir() . '/ec-link-pages-blog-4/cover.jpg', 'cover' );
		$this->registerParticipant();
		$blocked = ec_plan_link_page_storage_migration( 4, 7 );
		$this->assertContains( 'attachment_parent_semantics', array_column( $blocked['unsupported'], 'type' ) );
	}

	public function test_external_parent_exact_owner_participant_mapping_authorizes_zero(): void {
		$GLOBALS['ec_test']['blogs'][4]['posts'][140] = $this->post( 140, 'profile', 'profile-140' );
		$GLOBALS['ec_test']['blogs'][4]['posts'][40]  = $this->post( 40, EC_LINK_PAGE_POST_TYPE, 'page-40' );
		$GLOBALS['ec_test']['blogs'][4]['posts'][60]  = $this->post( 60, 'attachment', 'cover', 'inherit', 999 );
		$this->addMeta( 4, 40, EC_LINK_PAGE_OWNER_META_KEY, 'post:4:profile:140' );
		$this->addMeta( 4, 40, '_thumbnail_id', '60' );
		$this->addMeta( 4, 60, '_wp_attached_file', 'cover.jpg' );
		file_put_contents( sys_get_temp_dir() . '/ec-link-pages-blog-4/cover.jpg', 'cover' );
		$this->registerParticipant(
			static function () {
				return array(
					'fingerprint'          => hash( 'sha256', 'mapped' ),
					'attachment_ids'       => array( 60 ),
					'attachment_semantics' => array(
						array(
							'attachment_id'      => 60,
							'destination_parent' => 0,
							'owner_reference'    => 'post:4:profile:140',
						),
					),
				);
			}
		);
		$plan = ec_plan_link_page_storage_migration( 4, 7 );
		$this->assertTrue( $plan['ready'], wp_json_encode( $plan ) );
		$this->assertSame( 0, $plan['attachments'][0]['destination_parent'] );
	}

	public function test_journal_capacity_evicts_only_oldest_rolled_back_with_all_pieces(): void {
		for ( $i = 1; $i <= EC_LINK_PAGE_MIGRATION_JOURNAL_LIMIT; ++$i ) {
			$journal = array(
				'id'         => 'journal-' . $i,
				'network_id' => 1,
				'status'     => 1 === $i ? 'rolled_back' : 'applied',
				'created_at' => sprintf( '2026-01-%02d', $i ),
				'entries'    => array( array( 'sequence' => 1 ) ),
			);
			$this->assertTrue( ec_link_page_migration_store_entry( $journal['id'], $journal['entries'][0] ) );
			$this->assertTrue( ec_link_page_migration_store_journal( $journal ) );
		}
		$new = array(
			'id'         => 'journal-new',
			'network_id' => 1,
			'status'     => 'applying',
			'created_at' => '2026-02-01',
			'entries'    => array(),
		);
		$this->assertTrue( ec_link_page_migration_store_journal( $new ) );
		$this->assertArrayNotHasKey( ec_link_page_migration_journal_key( 'journal-1' ), $GLOBALS['ec_test']['site_options'] );
		$this->assertArrayNotHasKey( ec_link_page_migration_plan_key( 'journal-1' ), $GLOBALS['ec_test']['site_options'] );
		$this->assertArrayNotHasKey( ec_link_page_migration_journal_key( 'journal-1', 1 ), $GLOBALS['ec_test']['site_options'] );
		$this->assertCount( EC_LINK_PAGE_MIGRATION_JOURNAL_LIMIT, $GLOBALS['ec_test']['site_options'][ EC_LINK_PAGE_MIGRATION_JOURNAL_INDEX_OPTION ] );
	}

	public function test_durable_header_is_bounded_and_plan_is_immutable(): void {
		$journal = array(
			'id'                => 'split',
			'network_id'        => 1,
			'status'            => 'applying',
			'created_at'        => '2026-01-01',
			'entries'           => array(),
			'participant_plans' => array( 'owner' => str_repeat( 'p', 1000 ) ),
			'source_inventory'  => array( 'posts' => str_repeat( 's', 1000 ) ),
		);
		$this->assertTrue( ec_link_page_migration_store_journal( $journal ) );
		$header = $GLOBALS['ec_test']['site_options'][ ec_link_page_migration_journal_key( 'split' ) ];
		$this->assertArrayNotHasKey( 'source_inventory', $header );
		$this->assertArrayNotHasKey( 'participant_plans', $header );
		$journal['source_inventory']['posts'] = 'changed';
		$this->assertSame( 'link_page_migration_plan_changed', ec_link_page_migration_store_journal( $journal )->get_error_code() );
	}

	public function test_full_active_journal_refuses_new_apply_record(): void {
		for ( $i = 1; $i <= EC_LINK_PAGE_MIGRATION_JOURNAL_LIMIT; ++$i ) {
			$this->assertTrue(
				ec_link_page_migration_store_journal(
					array(
						'id'         => 'active-' . $i,
						'network_id' => 1,
						'status'     => 'failed',
						'created_at' => (string) $i,
						'entries'    => array(),
					)
				)
			);
		}
		$result = ec_link_page_migration_store_journal(
			array(
				'id'         => 'overflow',
				'network_id' => 1,
				'status'     => 'applying',
				'created_at' => 'later',
				'entries'    => array(),
			)
		);
		$this->assertSame( 'link_page_migration_journal_capacity', $result->get_error_code() );
	}

	public function test_participant_under_pop_is_restored_and_reported(): void {
		switch_to_blog( 7 );
		$participant = array(
			'name'      => 'corrupt',
			'callbacks' => array(
				'validate' => static function () {
						restore_current_blog();
						return true; },
			),
		);
		$result      = ec_link_page_migration_invoke_participant( $participant, 'validate', array() );
		$this->assertSame( 'link_page_migration_participant_context_corrupt', $result->get_error_code() );
		$this->assertSame( 7, get_current_blog_id() );
		$this->assertSame( array( 4 ), $GLOBALS['_wp_switched_stack'] );
		restore_current_blog();
	}

	public function test_participant_over_pop_is_exactly_restored(): void {
		$participant = array(
			'name'      => 'over-pop',
			'callbacks' => array(
				'validate' => static function () {
						switch_to_blog( 7 );
						switch_to_blog( 4 );
						return true; },
			),
		);
		$this->assertTrue( ec_link_page_migration_invoke_participant( $participant, 'validate', array() ) );
		$this->assertSame( 4, get_current_blog_id() );
		$this->assertSame( array(), $GLOBALS['_wp_switched_stack'] );
	}

	public function test_source_drift_and_symlink_substitution_fail_validation(): void {
		list( , $result )                                        = $this->planAndApply();
		$GLOBALS['ec_test']['blogs'][4]['posts'][40]->post_title = 'Source changed';
		$this->assertSame( 'link_page_migration_source_drift', ec_validate_link_page_storage_migration( $result['journal_id'] )->get_error_code() );
		$GLOBALS['ec_test']['blogs'][4]['posts'][40]->post_title = 'Title 40';
		$path    = sys_get_temp_dir() . '/ec-link-pages-blog-7/2026/cover.jpg';
		$outside = sys_get_temp_dir() . '/ec-link-pages-outside-' . uniqid() . '.jpg';
		file_put_contents( $outside, file_get_contents( $path ) );
		unlink( $path );
		if ( function_exists( 'symlink' ) && @symlink( $outside, $path ) ) {
			$this->assertSame( 'link_page_migration_validation_failed', ec_validate_link_page_storage_migration( $result['journal_id'] )->get_error_code() );
			unlink( $path );
		}
		unlink( $outside );
	}

	public function test_core_slug_namespace_blocks_attachment_conflict(): void {
		$this->seedCompleteInventory();
		$GLOBALS['ec_test']['blogs'][7]['posts'][999] = $this->post( 999, 'post', 'cover' );
		$plan = ec_plan_link_page_storage_migration( 4, 7 );
		$this->assertContains( 'slug', array_column( $plan['collisions'], 'type' ) );
	}
}
