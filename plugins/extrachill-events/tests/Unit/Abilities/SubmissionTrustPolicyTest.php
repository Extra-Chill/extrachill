<?php
/** Tests for trusted public event submitters. */

use ExtraChillEvents\Abilities\SubmissionTrustPolicy;
use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( $user_id, $key, $single = false ) {
		return $GLOBALS['submission_trust_user_meta'][ $user_id ][ $key ] ?? '';
	}
	function get_userdata( $user_id ) {
		return $GLOBALS['submission_trust_users'][ $user_id ] ?? false;
	}
	function user_can( $user_id, $capability ) {
		return in_array( $capability, $GLOBALS['submission_trust_caps'][ $user_id ] ?? array(), true );
	}
	function get_posts( $args ) {
		return ! empty( $GLOBALS['submission_trust_prior_published'][ $args['meta_value'] ] );
	}
}

require_once dirname( __DIR__, 3 ) . '/inc/Abilities/SubmissionTrustPolicy.php';

final class SubmissionTrustPolicyTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['submission_trust_user_meta'] = array();
		$GLOBALS['submission_trust_users'] = array();
		$GLOBALS['submission_trust_prior_published'] = array();
		$GLOBALS['submission_trust_caps'] = array();
	}

	public function test_prior_published_claimed_submitter_is_trusted(): void {
		$GLOBALS['submission_trust_users'][11] = (object) array( 'roles' => array( 'subscriber' ) );
		$GLOBALS['submission_trust_prior_published'][11] = true;
		$this->assertTrue( SubmissionTrustPolicy::trusts( 11, true ) );
	}

	public function test_events_staff_capability_is_trusted(): void {
		$GLOBALS['submission_trust_caps'][12] = array( 'access_events_admin' );
		$this->assertTrue( SubmissionTrustPolicy::trusts( 12, true ) );
		$this->assertTrue( SubmissionTrustPolicy::isStaff( 12 ) );
	}

	public function test_administrator_capability_is_staff(): void {
		$GLOBALS['submission_trust_caps'][16] = array( 'manage_options' );
		$this->assertTrue( SubmissionTrustPolicy::isStaff( 16 ) );
		$this->assertTrue( SubmissionTrustPolicy::trusts( 16, true ) );
	}

	public function test_role_name_alone_grants_nothing(): void {
		$GLOBALS['submission_trust_users'][17] = (object) array( 'roles' => array( 'extra_chill_team' ) );
		$this->assertFalse( SubmissionTrustPolicy::isStaff( 17 ) );
		$this->assertFalse( SubmissionTrustPolicy::trusts( 17, true ) );
	}

	public function test_prior_publication_does_not_make_staff(): void {
		$GLOBALS['submission_trust_prior_published'][11] = true;
		$this->assertFalse( SubmissionTrustPolicy::isStaff( 11 ) );
	}

	public function test_unknown_submitter_is_not_trusted(): void {
		$GLOBALS['submission_trust_users'][13] = (object) array( 'roles' => array( 'subscriber' ) );
		$this->assertFalse( SubmissionTrustPolicy::trusts( 13, true ) );
	}

	public function test_anonymous_email_match_does_not_supply_account_trust(): void {
		$GLOBALS['submission_trust_users'][15] = (object) array( 'roles' => array( 'subscriber' ) );
		$GLOBALS['submission_trust_prior_published'][15] = true;
		$this->assertFalse( SubmissionTrustPolicy::trusts( 15, false ) );
	}

	public function test_unclaimed_account_is_not_trusted_even_with_prior_publication(): void {
		$GLOBALS['submission_trust_users'][14] = (object) array( 'roles' => array( 'subscriber' ) );
		$GLOBALS['submission_trust_user_meta'][14]['ec_unclaimed'] = '1';
		$GLOBALS['submission_trust_prior_published'][14] = true;
		$this->assertFalse( SubmissionTrustPolicy::trusts( 14, true ) );
	}
}
