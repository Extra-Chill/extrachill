<?php
/**
 * Community active-members metric provider.
 *
 * Reads `active_users` from the delegated community-get-stats payload (members
 * who have earned community points). DELEGATES — never re-queries bbPress.
 *
 * @package ExtraChillNetwork\NetworkStats\Providers
 * @since   1.19.0
 */

namespace ExtraChillNetwork\NetworkStats\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Active community members (point-earning users).
 */
class CommunityMembersProvider extends CommunityStatsProvider {

	/**
	 * Construct the community-members provider.
	 */
	public function __construct() {
		parent::__construct( 'community_members', __( 'Community Members', 'extrachill-network' ), HOUR_IN_SECONDS );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function field(): string {
		return 'active_users';
	}
}
