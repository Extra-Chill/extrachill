<?php
/**
 * Community topics metric provider.
 *
 * Reads `topics` from the delegated community-get-stats payload. DELEGATES —
 * never re-queries bbPress.
 *
 * @package ExtraChillNetwork\NetworkStats\Providers
 * @since   1.19.0
 */

namespace ExtraChillNetwork\NetworkStats\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Published community forum topics.
 */
class CommunityTopicsProvider extends CommunityStatsProvider {

	/**
	 * Construct the community-topics provider.
	 */
	public function __construct() {
		parent::__construct( 'community_topics', __( 'Community Topics', 'extrachill-network' ), HOUR_IN_SECONDS );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function field(): string {
		return 'topics';
	}
}
