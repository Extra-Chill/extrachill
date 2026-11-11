<?php
/** Team-specific onboarding boundaries. */
namespace ExtraChill\Users\Teams;

interface TeamOnboardingAdapter {
	public function team_type(): string;
	public function operations(): array;
	/** Validate the entity, acquiring its anchor lock when requested inside a transaction. */
	public function check_team( int $team_id, bool $lock );
	/** Return true or WP_Error using lock-current canonical membership rows. */
	public function authorize_locked( int $actor_user_id, int $team_id, array $members );
	public function error_code( string $code ): string;
	/** Add any consumer compatibility aliases without exposing internal secrets. */
	public function public_record( array $record ): array;
}
