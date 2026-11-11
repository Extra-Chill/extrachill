<?php
/**
 * Generic team onboarding lifecycle driven through a fake adapter.
 *
 * @package ExtraChill\Users
 */

use ExtraChill\Users\Teams\TeamMembershipRepository;
use ExtraChill\Users\Teams\TeamOnboardingAdapter;
use ExtraChill\Users\Teams\TeamOnboardingAdapters;
use ExtraChill\Users\Teams\TeamOnboardingRepository;

/** Prove the users-layer lifecycle needs nothing from a team-owning plugin but its adapter. */
class Test_Team_Onboarding_Lifecycle extends WP_UnitTestCase {
	// phpcs:disable Squiz.Commenting.FunctionComment.Missing

	private const TEAM_ID = 909090;

	/** Team type the fake adapter stands in for; it must be a stored team type. */
	private const TYPE = 'venue';

	private $adapter;
	private $repository;
	private $owner;

	protected function setUp(): void {
		parent::setUp();
		extrachill_users_install_teams_table();
		$this->adapter    = new class( self::TYPE ) implements TeamOnboardingAdapter {
			public $locks = array();
			private $type;
			public function __construct( string $type ) {
				$this->type = $type;
			}
			public function team_type(): string {
				return $this->type;
			}
			public function operations(): array {
				return array( 'invitations', 'claims' );
			}
			public function check_team( int $team_id, bool $lock ) {
				$this->locks[] = array( $team_id, $lock );
				return $team_id > 0 ? true : new WP_Error( 'fake_team_missing', 'Missing.' );
			}
			public function authorize( int $actor_user_id, int $team_id ) {
				return true;
			}
			public function authorize_locked( int $actor_user_id, int $team_id, array $members ) {
				foreach ( $members as $member ) {
					if ( (int) $member['user_id'] === $actor_user_id && 'active' === $member['status'] && $member['is_owner'] ) {
						return true;
					}
				}
				return new WP_Error( 'fake_forbidden', 'Forbidden.' );
			}
			public function error_code( string $code ): string {
				return str_replace( 'team', 'fake', $code );
			}
			public function public_record( array $record ): array {
				return $record;
			}
			public function registration_source(): string {
				return 'fake_invitation';
			}
			public function invitation_email( array $invitation, string $token ) {
				return array(
					'subject'      => 'Join',
					'intro'        => 'Join the fake team.',
					'accept_label' => 'Accept',
					'from_name'    => 'Fake',
					'accept_url'   => 'https://example.test/accept?token=' . $token,
				);
			}
		};
		$this->repository = new TeamOnboardingRepository( $this->adapter );
		$this->owner      = self::factory()->user->create();
		$memberships      = new TeamMembershipRepository();
		$memberships->insert( self::TYPE, $memberships->new_row( self::TYPE, self::TEAM_ID, $this->owner, true, $this->owner ) );
	}

	private function invite( int $user_id ): array {
		$invitation = $this->repository->create_invitation( $this->owner, self::TEAM_ID, $user_id, false, hash( 'sha256', 'invitee' . $user_id ) );
		$this->assertIsArray( $invitation, is_wp_error( $invitation ) ? $invitation->get_error_code() : '' );
		return $invitation;
	}

	public function test_invite_then_accept_activates_membership(): void {
		$invitee    = self::factory()->user->create();
		$invitation = $this->invite( $invitee );
		$this->assertSame( TeamOnboardingRepository::INVITE_PENDING, $invitation['status'] );
		$this->assertSame( array( self::TEAM_ID, true ), end( $this->adapter->locks ) );

		$prepared = $this->repository->prepare_delivery( $invitation['_delivery_id'], 3600 );
		$this->assertIsArray( $prepared );
		$accepted = $this->repository->respond_to_invitation( $invitee, $prepared['public_id'], $prepared['_delivery_token'], self::TEAM_ID, false, (int) $prepared['version'], TeamOnboardingRepository::INVITE_ACCEPTED );
		$this->assertIsArray( $accepted, is_wp_error( $accepted ) ? $accepted->get_error_code() : '' );
		$this->assertSame( TeamOnboardingRepository::INVITE_ACCEPTED, $accepted['status'] );

		$membership = ( new TeamMembershipRepository() )->get( self::TYPE, self::TEAM_ID, $invitee );
		$this->assertSame( 'active', $membership['status'] );
		$this->assertFalse( $membership['is_owner'] );

		$public = $this->repository->public_invitation( $accepted );
		foreach ( array( '_token_hash', '_email_hash', '_delivery_id', '_delivery_token', '_account_created' ) as $secret ) {
			$this->assertArrayNotHasKey( $secret, $public );
		}
	}

	public function test_cancel_revokes_the_invited_membership(): void {
		$invitee    = self::factory()->user->create();
		$invitation = $this->invite( $invitee );
		$cancelled  = $this->repository->cancel_invitation( $this->owner, (int) $invitation['id'], (int) $invitation['version'] );
		$this->assertIsArray( $cancelled, is_wp_error( $cancelled ) ? $cancelled->get_error_code() : '' );
		$this->assertSame( TeamOnboardingRepository::INVITE_CANCELLED, $cancelled['status'] );
		$this->assertSame( 'revoked', ( new TeamMembershipRepository() )->get( self::TYPE, self::TEAM_ID, $invitee )['status'] );
	}

	public function test_claims_and_invitations_exclude_each_other_with_adapter_error_codes(): void {
		$invited = self::factory()->user->create();
		$this->invite( $invited );
		$this->assertSame( 'fake_claim_invitation_exists', $this->repository->submit_claim( $invited, self::TEAM_ID )->get_error_code() );

		$claimant = self::factory()->user->create();
		$claim    = $this->repository->submit_claim( $claimant, self::TEAM_ID );
		$this->assertIsArray( $claim );
		$blocked = $this->repository->create_invitation( $this->owner, self::TEAM_ID, $claimant, false, hash( 'sha256', 'claimant' ) );
		$this->assertSame( 'fake_invitation_claim_exists', $blocked->get_error_code() );
	}

	public function test_registry_returns_only_adapters_matching_their_key(): void {
		$adapter  = $this->adapter;
		$register = static function ( array $adapters ) use ( $adapter ): array {
			$adapters[ self::TYPE ] = $adapter;
			$adapters['mismatch']   = $adapter;
			$adapters['junk']       = new stdClass();
			return $adapters;
		};
		add_filter( 'extrachill_users_team_onboarding_adapters', $register, 99 );
		try {
			$this->assertSame( $adapter, TeamOnboardingAdapters::get( self::TYPE ) );
			$this->assertNull( TeamOnboardingAdapters::get( 'mismatch' ) );
			$this->assertNull( TeamOnboardingAdapters::get( 'junk' ) );
		} finally {
			remove_filter( 'extrachill_users_team_onboarding_adapters', $register, 99 );
		}
	}
}
