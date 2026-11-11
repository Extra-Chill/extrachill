<?php
/** Shared guarded membership transaction tests. */

use ExtraChill\Users\Teams\TeamMembershipRepository;
use ExtraChill\Users\Teams\TeamOnboardingAdapter;
use PHPUnit\Framework\TestCase;

final class GuardedTeamMemberChangeTest extends TestCase {
	private $database;
	private $repository;
	private $adapter;

	protected function setUp(): void {
		$this->database = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = new class {
			public $last_error = '';
			public $queries = array();
			public $fail = '';
			public function query( $sql ) {
				$this->queries[] = $sql;
				return $this->fail === $sql ? false : 1;
			}
		};
		$this->repository = new class extends TeamMembershipRepository {
			public $status = 'active';
			public $written = false;
			public $result = 1;
			public function lock_team( string $type, int $id, ?int $limit = null ): array {
				return array( array( 'user_id' => 2, 'is_owner' => true, 'status' => $this->status, 'version' => 1 ) );
			}
			public function hydrate_rows( string $type, array $rows ) { return $rows; }
			public function mutate( string $type, int $id, int $user, int $version, ?bool $owner, bool $revoke, int $actor ) {
				$this->written = true;
				return $this->result;
			}
			public function get( string $type, int $id, int $user ) { return array( 'version' => 2 ); }
		};
		$this->adapter = $this->createMock( TeamOnboardingAdapter::class );
		$this->adapter->method( 'team_type' )->willReturn( 'artist' );
		$this->adapter->method( 'error_code' )->willReturnCallback( static function ( $code ) { return str_replace( 'team_', 'custom_', $code ); } );
		$this->adapter->expects( $this->once() )->method( 'check_team' )->with( 55, true )->willReturn( true );
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->database;
	}

	public function test_final_owner_floor_and_adapter_error_mapping(): void {
		$error = $this->repository->change_member( $this->adapter, 55, 2, 1, false, false, 2 );
		$this->assertSame( 'custom_membership_last_owner', $error->get_error_code() );
		$this->assertSame( 409, $error->get_error_data()['status'] );
		$this->assertFalse( $this->repository->written );
		$this->assertSame( array( 'START TRANSACTION', 'ROLLBACK' ), $GLOBALS['wpdb']->queries );
	}

	public function test_floor_can_be_disabled_by_caller(): void {
		$this->assertSame( array( 'version' => 2 ), $this->repository->change_member( $this->adapter, 55, 2, 1, false, false, 2, false ) );
		$this->assertTrue( $this->repository->written );
		$this->assertSame( array( 'START TRANSACTION', 'COMMIT' ), $GLOBALS['wpdb']->queries );
	}

	public function test_version_conflict(): void {
		$error = $this->repository->change_member( $this->adapter, 55, 2, 3, true, false, 2 );
		$this->assertSame( 'custom_membership_version_conflict', $error->get_error_code() );
		$this->assertSame( 1, $error->get_error_data()['current_version'] );
		$this->assertFalse( $this->repository->written );
	}

	public function test_revoked_row_cannot_change(): void {
		$this->repository->status = 'revoked';
		$error = $this->repository->change_member( $this->adapter, 55, 2, 1, true, false, 2 );
		$this->assertSame( 'custom_membership_revoked', $error->get_error_code() );
		$this->assertFalse( $this->repository->written );
	}

	public function test_authorization_rejection_is_returned_unchanged(): void {
		$denied = new WP_Error( 'type_owned_denial', 'Denied', array( 'status' => 403 ) );
		$this->adapter->expects( $this->once() )->method( 'authorize_locked' )->willReturn( $denied );
		$this->assertSame( $denied, $this->repository->change_member( $this->adapter, 55, 2, 1, true, false, 2 ) );
		$this->assertFalse( $this->repository->written );
	}

	public function test_failed_commit_is_uncertain_and_never_rolled_back(): void {
		$GLOBALS['wpdb']->fail = 'COMMIT';
		$error = $this->repository->change_member( $this->adapter, 55, 2, 1, true, false, 2 );
		$this->assertSame( 'custom_membership_commit_uncertain', $error->get_error_code() );
		$this->assertSame( array( 'START TRANSACTION', 'COMMIT' ), $GLOBALS['wpdb']->queries );
	}

	public function test_zero_row_update_is_a_version_conflict(): void {
		$this->repository->result = 0;
		$error = $this->repository->change_member( $this->adapter, 55, 2, 1, true, false, 2 );
		$this->assertSame( 'custom_membership_version_conflict', $error->get_error_code() );
		$this->assertSame( array( 'START TRANSACTION', 'ROLLBACK' ), $GLOBALS['wpdb']->queries );
	}
}
