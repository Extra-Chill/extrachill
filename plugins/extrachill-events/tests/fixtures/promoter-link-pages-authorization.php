<?php

namespace ExtraChillEvents\Core;

class PromoterAuthorityRepository {
	public const STATUS_ACTIVE     = 'active';
	public const STATUS_REVOKED    = 'revoked';
	public const MAX_ORGANIZATIONS = 500;

	public function get_organization( int $promoter_term_id ) {
		return $GLOBALS['promoter_link_page_fixture']['organizations'][ $promoter_term_id ] ?? null;
	}

	public function list_active_organizations() {
		if ( ! empty( $GLOBALS['promoter_link_page_fixture']['discovery_error'] ) ) {
			return new \WP_Error( 'approved_promoter_list_failed', 'Failed.' );
		}
		$rows = array_values(
			array_filter(
				$GLOBALS['promoter_link_page_fixture']['organizations'],
				static function ( $organization ) {
					return self::STATUS_ACTIVE === $organization['status']; }
			)
		);
		return count( $rows ) > self::MAX_ORGANIZATIONS ? new \WP_Error( 'approved_promoter_limit_exceeded', 'Overflow.' ) : $rows;
	}

	public function with_active_membership_lock( int $promoter_term_id, int $user_id, callable $callback ) {
		if ( is_callable( $GLOBALS['promoter_link_page_fixture']['revoke_on_authority_lock'] ?? null ) ) {
			$revoke = $GLOBALS['promoter_link_page_fixture']['revoke_on_authority_lock'];
			unset( $GLOBALS['promoter_link_page_fixture']['revoke_on_authority_lock'] );
			$revoke();
		}
		$organization = $this->get_organization( $promoter_term_id );
		$key          = $user_id . ':' . $promoter_term_id;
		return is_array( $organization ) && self::STATUS_ACTIVE === $organization['status'] && ! empty( $GLOBALS['promoter_link_page_fixture']['memberships'][ $key ] ) ? $callback() : new \WP_Error( 'promoter_authority_forbidden', 'Forbidden.', array( 'status' => 403 ) );
	}

	public function with_active_organization_lock( int $promoter_term_id, callable $callback ) {
		$organization = $this->get_organization( $promoter_term_id );
		return is_array( $organization ) && self::STATUS_ACTIVE === $organization['status'] ? $callback() : new \WP_Error( 'promoter_authority_forbidden', 'Forbidden.', array( 'status' => 403 ) );
	}
}

final class PromoterAuthorization {
	public const ACTION_ACCESS_PROMOTER = 'access_promoter';

	public function authorize( int $user_id, int $promoter_term_id, string $action ) {
		$key          = $user_id . ':' . $promoter_term_id;
		$organization = $GLOBALS['promoter_link_page_fixture']['organizations'][ $promoter_term_id ] ?? null;
		$allowed      = self::ACTION_ACCESS_PROMOTER === $action
			&& ! empty( $organization )
			&& PromoterAuthorityRepository::STATUS_ACTIVE === $organization['status']
			&& ! empty( $GLOBALS['promoter_link_page_fixture']['memberships'][ $key ] )
			&& ! empty( $GLOBALS['promoter_link_page_fixture']['feature'][ $user_id ] );
		return $allowed ? true : new \WP_Error( 'promoter_authority_forbidden', 'Forbidden.', array( 'status' => 403 ) );
	}
}
