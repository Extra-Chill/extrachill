<?php
/**
 * Events team-term owner shape for the shared Link Pages engine.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Binds the runtime's owned Link Page engine to canonical Events taxonomies.
 *
 * Owner kinds (venue, promoter) extend this and supply only their taxonomy,
 * authorization, canonical source read, management URL, and the few
 * snapshot/schema fields that genuinely differ.
 */
abstract class TeamTermLinkPages extends \ExtraChill\LinkPages\OwnedLinkPages {

	public const OWNER_KIND = 'term';

	protected const HOOK_PREFIX = 'extrachill_events_';

	/** Audit policy recorded when the owner term is deleted. */
	protected const ORPHAN_POLICY = 'draft_on_term_deleted';

	/** Resolve the configured canonical Events blog. */
	protected static function owner_blog_id(): int {
		return (int) ec_get_blog_id( 'events' );
	}

	/**
	 * Core term edits and the Events profile-update action both refresh.
	 *
	 * @return array<string,int>
	 */
	protected static function refresh_hooks(): array {
		return array(
			'edited_' . static::KIND => 30,
			static::HOOK_PREFIX . static::KIND . '_profile_updated' => 20,
		);
	}
}
