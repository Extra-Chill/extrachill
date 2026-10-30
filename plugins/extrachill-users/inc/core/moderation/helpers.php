<?php
/**
 * Moderation Helpers
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

function extrachill_users_moderation_meta_key() {
	return 'extrachill_user_moderation';
}

function extrachill_users_legacy_ban_meta_key() {
	return 'extrachill_user_ban';
}
