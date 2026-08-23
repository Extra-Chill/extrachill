<?php
/**
 * Promoter authority management service.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

defined( 'ABSPATH' ) || exit;

/** Reauthorizes promoter organization and membership operations. */
final class PromoterAuthorityService {
	/**
	 * Promoter authority persistence.
	 *
	 * @var PromoterAuthorityRepository
	 */
	private $repository;

	/**
	 * Promoter authorization policy.
	 *
	 * @var PromoterAuthorization
	 */
	private $authorization;

	/**
	 * Construct the service boundary.
	 *
	 * @param PromoterAuthorityRepository|null $repository    Repository override.
	 * @param PromoterAuthorization|null       $authorization Authorization override.
	 */
	public function __construct( ?PromoterAuthorityRepository $repository = null, ?PromoterAuthorization $authorization = null ) {
		$this->repository    = $repository ? $repository : new PromoterAuthorityRepository();
		$this->authorization = $authorization ? $authorization : new PromoterAuthorization( $this->repository );
	}

	/**
	 * Verify and bootstrap one promoter organization.
	 *
	 * @param int $actor_user_id    Administrator network user ID.
	 * @param int $promoter_term_id Exact promoter term ID.
	 * @param int $owner_user_id    Initial owner network user ID.
	 */
	public function verify( int $actor_user_id, int $promoter_term_id, int $owner_user_id ) {
		$allowed = $this->authorization->authorize( $actor_user_id, $promoter_term_id, PromoterAuthorization::ACTION_VERIFY_ORGANIZATION );
		return is_wp_error( $allowed ) ? $allowed : $this->repository->verify( $promoter_term_id, $owner_user_id, $actor_user_id );
	}

	/**
	 * Revoke one promoter organization.
	 *
	 * @param int $actor_user_id    Administrator network user ID.
	 * @param int $promoter_term_id Exact promoter term ID.
	 * @param int $expected_version Optimistic organization version.
	 */
	public function revoke_organization( int $actor_user_id, int $promoter_term_id, int $expected_version ) {
		$allowed = $this->authorization->authorize( $actor_user_id, $promoter_term_id, PromoterAuthorization::ACTION_REVOKE_ORGANIZATION );
		return is_wp_error( $allowed ) ? $allowed : $this->repository->revoke_organization( $promoter_term_id, $expected_version, $actor_user_id );
	}

	/**
	 * Create one explicit active membership.
	 *
	 * @param int  $actor_user_id    Acting owner network user ID.
	 * @param int  $promoter_term_id Exact promoter term ID.
	 * @param int  $user_id          Target network user ID.
	 * @param bool $is_owner         Structural owner state.
	 */
	public function create_membership( int $actor_user_id, int $promoter_term_id, int $user_id, bool $is_owner ) {
		$allowed = $this->authorization->authorize( $actor_user_id, $promoter_term_id, PromoterAuthorization::ACTION_MANAGE_MEMBERS );
		return is_wp_error( $allowed ) ? $allowed : $this->repository->create_membership( $promoter_term_id, $user_id, $is_owner, $actor_user_id );
	}

	/**
	 * Change one membership's structural owner state.
	 *
	 * @param int  $actor_user_id    Acting owner network user ID.
	 * @param int  $promoter_term_id Exact promoter term ID.
	 * @param int  $user_id          Target network user ID.
	 * @param bool $is_owner         Structural owner state.
	 * @param int  $expected_version Optimistic membership version.
	 */
	public function update_membership( int $actor_user_id, int $promoter_term_id, int $user_id, bool $is_owner, int $expected_version ) {
		$allowed = $this->authorization->authorize( $actor_user_id, $promoter_term_id, PromoterAuthorization::ACTION_MANAGE_MEMBERS );
		return is_wp_error( $allowed ) ? $allowed : $this->repository->update_owner( $promoter_term_id, $user_id, $is_owner, $expected_version, $actor_user_id );
	}

	/**
	 * Revoke one membership while preserving its row.
	 *
	 * @param int $actor_user_id    Acting owner network user ID.
	 * @param int $promoter_term_id Exact promoter term ID.
	 * @param int $user_id          Target network user ID.
	 * @param int $expected_version Optimistic membership version.
	 */
	public function revoke_membership( int $actor_user_id, int $promoter_term_id, int $user_id, int $expected_version ) {
		$allowed = $this->authorization->authorize( $actor_user_id, $promoter_term_id, PromoterAuthorization::ACTION_MANAGE_MEMBERS );
		return is_wp_error( $allowed ) ? $allowed : $this->repository->revoke_membership( $promoter_term_id, $user_id, $expected_version, $actor_user_id );
	}

	/**
	 * List the bounded membership set for one promoter.
	 *
	 * @param int $actor_user_id    Acting owner network user ID.
	 * @param int $promoter_term_id Exact promoter term ID.
	 */
	public function list_memberships( int $actor_user_id, int $promoter_term_id ) {
		$allowed = $this->authorization->authorize( $actor_user_id, $promoter_term_id, PromoterAuthorization::ACTION_MANAGE_MEMBERS );
		return is_wp_error( $allowed ) ? $allowed : $this->repository->list_memberships( $promoter_term_id );
	}
}
