<?php
/**
 * Network Bot User
 *
 * Config-driven source of truth for the platform "bot" account — the user that
 * genuine Data Machine automation (scheduled scrapes, non-submission pipelines)
 * is attributed to so authorship stays honest at the source.
 *
 * This helper exists so the bot id is config-over-code: callers resolve it
 * through {@see ec_get_network_bot_user_id()} (or its filter) instead of
 * scattering a magic `32` literal across plugins (layer purity / config over
 * code per the site RULES).
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

/**
 * Default user ID of the network automation bot account.
 *
 * "Extra Chill" (login "Extra Chill Staff"), historically uid 32. Exposed as a
 * constant so the default is itself not a bare literal at call sites.
 */
if ( ! defined( 'EXTRACHILL_NETWORK_BOT_USER_ID_DEFAULT' ) ) {
	define( 'EXTRACHILL_NETWORK_BOT_USER_ID_DEFAULT', 32 );
}

/**
 * Get the network bot user ID — the honest author for automation.
 *
 * The value is filterable via `extrachill_network_bot_user_id` so deployments
 * can override it without editing code, and so tests can stub it. The default
 * is {@see EXTRACHILL_NETWORK_BOT_USER_ID_DEFAULT} (32).
 *
 * Consumers (event/wire upsert author resolution, authorship backfills, points
 * engines) should call this rather than hardcoding the id, so a future bot-id
 * change is a single config/filter edit instead of a plugin-wide find/replace.
 *
 * @return int The bot user ID (filtered). 0 if filtered to an empty/falsey value.
 */
function ec_get_network_bot_user_id() {
	/**
	 * Filter the network automation bot user ID.
	 *
	 * @param int $bot_user_id Default bot user ID (32).
	 */
	return (int) apply_filters( 'extrachill_network_bot_user_id', EXTRACHILL_NETWORK_BOT_USER_ID_DEFAULT );
}
