<?php
/**
 * Promoter organization authorization.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/TeamAuthorization.php';

/** Authorizes administrators for bootstrap/revocation and administrators or owners for membership management. */
final class PromoterAuthorization {
	public const ACTION_VERIFY_ORGANIZATION = 'verify_organization';
	public const ACTION_REVOKE_ORGANIZATION = 'revoke_organization';
	public const ACTION_MANAGE_MEMBERS      = 'manage_members';
	public const ACTION_ACCESS_PROMOTER     = 'access_promoter';

	/** @var TeamAuthorization */
	private $policy;

	public function __construct( ?PromoterAuthorityRepository $repository = null, bool $use_execution_principal = true ) {
		$this->policy = new TeamAuthorization( null, $repository, $use_execution_principal );
	}

	public function authorize( int $user_id, int $promoter_term_id, string $action ) {
		return $this->policy->authorize( $user_id, 'promoter', $promoter_term_id, $action );
	}

	public static function execution_principal() {
		return TeamAuthorization::execution_principal();
	}

	public static function effective_user_id(): int {
		return TeamAuthorization::effective_user_id();
	}

	public static function user_can( int $user_id, string $capability, bool $use_execution_principal = true ): bool {
		return TeamAuthorization::user_can( $user_id, $capability, $use_execution_principal );
	}

	public static function is_site_admin( int $user_id, bool $use_execution_principal = true ): bool {
		return TeamAuthorization::is_site_admin( $user_id, $use_execution_principal );
	}

	public static function principal_allows( int $user_id, string $capability ): bool {
		return TeamAuthorization::principal_allows( $user_id, $capability );
	}
}
