<?php
/**
 * Registry of team onboarding adapters.
 *
 * @package ExtraChillUsers\Teams
 */

namespace ExtraChill\Users\Teams;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Resolves adapters registered by team-owning plugins. */
final class TeamOnboardingAdapters {

	/**
	 * All registered adapters keyed by team type.
	 *
	 * Plugins register on `extrachill_users_team_onboarding_adapters` by adding
	 * `type => TeamOnboardingAdapter` entries.
	 *
	 * @return array<string, TeamOnboardingAdapter>
	 */
	public static function all(): array {
		$adapters = apply_filters( 'extrachill_users_team_onboarding_adapters', array() );
		$valid    = array();
		foreach ( is_array( $adapters ) ? $adapters : array() as $type => $adapter ) {
			if ( $adapter instanceof TeamOnboardingAdapter && $adapter->team_type() === $type ) {
				$valid[ $type ] = $adapter;
			}
		}
		return $valid;
	}

	/** One adapter, or null when the team type has no onboarding. */
	public static function get( string $team_type ): ?TeamOnboardingAdapter {
		return self::all()[ $team_type ] ?? null;
	}
}
