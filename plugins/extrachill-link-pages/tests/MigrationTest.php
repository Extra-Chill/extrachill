<?php
/**
 * Migration behavior tests.
 *
 * @package ExtraChillLinkPages
 */

use PHPUnit\Framework\TestCase;

/** Test the concrete migration contracts available in the standalone fixture. */
final class MigrationTest extends TestCase {
	protected function setUp(): void {
		ec_test_reset();
	}

	public function test_plan_is_read_only_and_fingerprint_is_deterministic(): void {
		$before = $GLOBALS['ec_test'];
		$first  = ec_plan_link_page_storage_migration( 4, 7 );
		$second = ec_plan_link_page_storage_migration( 4, 7 );

		$this->assertFalse( is_wp_error( $first ) );
		$this->assertTrue( $first['ready'] );
		$this->assertSame( $first['fingerprint'], $second['fingerprint'] );
		$this->assertSame( 4, get_current_blog_id() );
		$this->assertSame( $before['site_options'] ?? array(), $GLOBALS['ec_test']['site_options'] ?? array() );
		$this->assertSame( array(), $GLOBALS['ec_test']['blogs'][7]['posts'] );
	}

	public function test_canonical_hash_ignores_associative_insertion_order_but_detects_drift(): void {
		$this->assertSame(
			ec_link_page_migration_hash(
				array(
					'b' => 2,
					'a' => array(
						'y' => 2,
						'x' => 1,
					),
				)
			),
			ec_link_page_migration_hash(
				array(
					'a' => array(
						'x' => 1,
						'y' => 2,
					),
					'b' => 2,
				)
			)
		);
		$this->assertNotSame( ec_link_page_migration_hash( array( 'a' => 1 ) ), ec_link_page_migration_hash( array( 'a' => 2 ) ) );
	}

	public function test_post_descriptor_normalizes_core_integer_fields(): void {
		$post              = (object) array_fill_keys( array( 'ID', 'post_parent', 'menu_order' ), '7' );
		$descriptor        = ec_link_page_migration_post_fields( $post );
		$this->assertSame( 7, $descriptor['ID'] );
		$this->assertSame( 7, $descriptor['post_parent'] );
		$this->assertSame( 7, $descriptor['menu_order'] );
	}

	public function test_participant_registration_is_named_complete_and_append_only(): void {
		$callbacks = array_fill_keys( array( 'claim_owner', 'plan', 'apply', 'validate', 'rollback' ), '__return_true' );
		$this->assertTrue( ec_register_link_page_migration_participant( 'fixture', '1', $callbacks ) );
		$this->assertSame( 'duplicate_link_page_migration_participant', ec_register_link_page_migration_participant( 'fixture', '1', $callbacks )->get_error_code() );
		unset( $callbacks['rollback'] );
		$this->assertSame( 'invalid_link_page_migration_participant', ec_register_link_page_migration_participant( 'incomplete', '1', $callbacks )->get_error_code() );
	}

	public function test_migration_ability_registers_in_the_core_site_category(): void {
		ec_register_link_page_storage_migration_ability();
		$ability = wp_get_ability( 'extrachill/migrate-link-page-storage' );
		$this->assertIsArray( $ability );
		$this->assertSame( 'site', $ability['category'] );
		$this->assertFalse( $ability['meta']['show_in_rest'] );
	}

	public function test_nested_blog_context_is_restored_after_exception(): void {
		$result = ec_link_page_migration_in_blog(
			7,
			static function () {
				throw new RuntimeException( 'failure' );
			}
		);
		$this->assertSame( 'link_page_migration_exception', $result->get_error_code() );
		$this->assertSame( 4, get_current_blog_id() );
		$this->assertSame( array(), $GLOBALS['_wp_switched_stack'] );
	}

	public function test_versioned_journal_entries_are_independent_and_verified(): void {
		$journal = array(
			'id'         => 'journal-one',
			'network_id' => 1,
			'status'     => 'applying',
			'created_at' => '2026-08-25T00:00:00Z',
			'entries'    => array(),
		);
		$this->assertTrue( ec_link_page_migration_store_journal( $journal ) );
		$entry = array(
			'sequence' => 1,
			'type'     => 'file',
			'applied'  => false,
		);
		$this->assertTrue( ec_link_page_migration_store_entry( $journal['id'], $entry ) );
		$journal['entries'][] = $entry;
		$this->assertTrue( ec_link_page_migration_store_journal( $journal ) );
		$this->assertSame( $journal, array_intersect_key( ec_link_page_migration_get_journal( $journal['id'] ), $journal ) );
		$GLOBALS['ec_test']['fail_network_option_write'] = true;
		$this->assertSame( 'link_page_migration_journal_write_failed', ec_link_page_migration_store_entry( $journal['id'], array( 'sequence' => 2 ) )->get_error_code() );
	}

	public function test_missing_required_participant_blocks_rollback_contract(): void {
		$result = ec_link_page_migration_require_participants(
			array(
				'required_participants' => array(
					array(
						'id'               => 'missing',
						'contract_version' => '1',
					),
				),
			)
		);
		$this->assertSame( 'link_page_migration_participant_contract_missing', $result->get_error_code() );
	}

	public function test_realpath_rejects_source_symlink_escape(): void {
		$root = sys_get_temp_dir() . '/ec-migration-containment-' . uniqid();
		mkdir( $root );
		file_put_contents( $root . '/inside.txt', 'inside' );
		$this->assertSame( realpath( $root . '/inside.txt' ), ec_link_page_migration_realpath( $root, $root . '/inside.txt' ) );
		if ( function_exists( 'symlink' ) && @symlink( '/etc/passwd', $root . '/outside.txt' ) ) {
			$this->assertFalse( ec_link_page_migration_realpath( $root, $root . '/outside.txt' ) );
			unlink( $root . '/outside.txt' );
		}
		unlink( $root . '/inside.txt' );
		rmdir( $root );
	}
}
