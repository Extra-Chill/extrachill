<?php
/**
 * Team-specific onboarding boundaries.
 *
 * @package ExtraChillUsers\Teams
 */

namespace ExtraChill\Users\Teams;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Everything the generic onboarding lifecycle needs from one team type.
 *
 * Adapters own validation, authorization and copy. The lifecycle owns
 * transactions, tokens, account provenance and delivery.
 */
interface TeamOnboardingAdapter {
	/** Team type discriminator stored in the network team tables. */
	public function team_type(): string;

	/** Supported operations: any of `invitations`, `claims`. */
	public function operations(): array;

	/**
	 * Validate the entity, acquiring its anchor lock when requested inside a transaction.
	 *
	 * @return true|\WP_Error
	 */
	public function check_team( int $team_id, bool $lock );

	/**
	 * Whether the actor may manage members, before any transaction opens.
	 *
	 * @return true|\WP_Error
	 */
	public function authorize( int $actor_user_id, int $team_id );

	/**
	 * Whether the actor may manage members, using lock-current canonical membership rows.
	 *
	 * @return true|\WP_Error
	 */
	public function authorize_locked( int $actor_user_id, int $team_id, array $members );

	/** Map a generic `team_*` error code onto the consumer's public code. */
	public function error_code( string $code ): string;

	/** Add any consumer compatibility aliases without exposing internal secrets. */
	public function public_record( array $record ): array;

	/** Destination after an acceptance attempt. */
	public function accepted_url( int $team_id, bool $accepted ): string;

	/** Registration source recorded on accounts created for an invitation. */
	public function registration_source(): string;

	/**
	 * Build invitation email copy for one prepared invitation.
	 *
	 * @param array  $invitation   Prepared invitation (public_id, team_id, is_owner, version).
	 * @param string $token        Plain single-use acceptance token.
	 * @return array|\WP_Error {subject, intro, accept_label, from_name, accept_url}
	 */
	public function invitation_email( array $invitation, string $token );
}
