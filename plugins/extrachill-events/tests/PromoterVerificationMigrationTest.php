<?php
/** Standalone promoter verification migration coverage. */
use ExtraChillEvents\Core\PromoterVerificationMigration;
use PHPUnit\Framework\TestCase;

define( 'ABSPATH', __DIR__ );
define( 'WP_CLI', true );
define( 'ARRAY_A', 'ARRAY_A' );
function add_filter( $name, $callback ) { $GLOBALS['migration_filter'] = $callback; }
function ec_get_blog_id( $name ) { return 7; }
function switch_to_blog( $id ) { $GLOBALS['migration_blog'] = $id; }
function restore_current_blog() { $GLOBALS['migration_blog'] = 1; }
function metadata_exists( $type, $id, $key ) { return isset( $GLOBALS['migration_meta'][ $id ][ $key ] ); }
function get_term_meta( $id, $key, $single ) { return $GLOBALS['migration_meta'][ $id ][ $key ] ?? ''; }
function update_term_meta( $id, $key, $value ) {
	if ( $GLOBALS['migration_fail_write'] ) { return false; }
	$GLOBALS['migration_meta'][ $id ][ $key ] = $value;
	return 1;
}
function add_term_meta( $id, $key, $value, $unique ) { return update_term_meta( $id, $key, $value ); }
function get_term( $id, $taxonomy ) { return (object) array( 'term_id' => $id, 'taxonomy' => $taxonomy ); }
function is_wp_error( $value ) { return false; }
require_once dirname( __DIR__ ) . '/inc/Core/PromoterVerificationMigration.php';

final class PromoterVerificationMigrationTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['migration_meta']       = array();
		$GLOBALS['migration_blog']       = 1;
		$GLOBALS['migration_fail_write'] = false;
		$GLOBALS['wpdb'] = new class {
			public $last_error = '';
			public $exists = true;
			public $drops = array();
			public $rows = array();
			public function get_blog_prefix( $id ) { return 'wp_' . $id . '_'; }
			public function esc_like( $value ) { return addcslashes( $value, '_%' ); }
			public function prepare( $sql, ...$values ) {
				foreach ( $values as $value ) {
					$sql = preg_replace_callback( '/%[si]/', static function ( $match ) use ( $value ) {
						return '%i' === $match[0] ? '`' . $value . '`' : "'" . $value . "'";
					}, $sql, 1 );
				}
				return $sql;
			}
			public function get_var( $sql ) {
				if ( str_starts_with( $sql, 'SHOW TABLES' ) ) { return $this->exists ? 'wp_7_ec_promoter_organizations' : null; }
				return count( array_filter( $this->rows, static function ( $row ) {
					return ! metadata_exists( 'term', $row['promoter_term_id'], '_ec_promoter_status' );
				} ) );
			}
			public function get_results( $sql, $format ) { return $this->rows; }
			public function query( $sql ) {
				$this->drops[] = $sql;
				$this->exists = false;
				return true;
			}
		};
		$GLOBALS['wpdb']->rows[] = array(
			'promoter_term_id'    => '113735',
			'status'              => 'active',
			'version'             => '3',
			'verified_by_user_id' => '1',
			'verified_at'         => '2026-01-01 00:00:00',
			'updated_at'          => '2026-01-02 00:00:00',
			'revoked_by_user_id'  => null,
			'revoked_at'          => null,
		);
	}

	public function test_inspect_is_read_only_and_apply_copies_then_drops(): void {
		$state = PromoterVerificationMigration::inspect();
		$this->assertSame( 'pending', $state['status'] );
		$this->assertSame( 1, $state['missing_meta'] );
		$this->assertFalse( PromoterVerificationMigration::isComplete() );
		$this->assertSame( array(), $GLOBALS['migration_meta'] );
		$this->assertSame( array(), $GLOBALS['wpdb']->drops );
		$this->assertTrue( PromoterVerificationMigration::apply()['success'] );
		$this->assertSame( 'active', get_term_meta( 113735, '_ec_promoter_status', true ) );
		$audit = get_term_meta( 113735, '_ec_promoter_verification', true );
		$this->assertSame( 3, $audit['version'] );
		$this->assertSame( 1, $audit['verified_by_user_id'] );
		$this->assertNull( $audit['revoked_at'] );
		$this->assertSame( '2026-01-02 00:00:00', $audit['updated_at'] );
		$this->assertSame( 1, $GLOBALS['migration_blog'] );
		$this->assertTrue( PromoterVerificationMigration::isComplete() );
		$this->assertFalse( PromoterVerificationMigration::inspect()['exists'] );
		$this->assertTrue( PromoterVerificationMigration::apply()['complete'] );
		$this->assertCount( 1, $GLOBALS['wpdb']->drops );
	}

	public function test_already_migrated_term_is_not_overwritten(): void {
		$GLOBALS['migration_meta'][113735] = array( '_ec_promoter_status' => 'revoked', '_ec_promoter_verification' => array( 'version' => 9 ) );
		$before = $GLOBALS['migration_meta'];
		$this->assertSame( 0, PromoterVerificationMigration::inspect()['missing_meta'] );
		$this->assertTrue( PromoterVerificationMigration::apply()['complete'] );
		$this->assertSame( $before, $GLOBALS['migration_meta'] );
	}

	public function test_failed_copy_preserves_table_and_restores_blog(): void {
		$GLOBALS['migration_fail_write'] = true;
		$this->assertFalse( PromoterVerificationMigration::apply()['success'] );
		$this->assertTrue( $GLOBALS['wpdb']->exists );
		$this->assertSame( array(), $GLOBALS['wpdb']->drops );
		$this->assertSame( 1, $GLOBALS['migration_blog'] );
		$this->assertFalse( metadata_exists( 'term', 113735, '_ec_promoter_status' ) );
		$GLOBALS['migration_fail_write'] = false;
		$this->assertTrue( PromoterVerificationMigration::apply()['complete'] );
	}
}
