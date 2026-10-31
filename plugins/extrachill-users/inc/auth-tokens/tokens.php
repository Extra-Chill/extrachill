<?php
/**
 * Token helpers (extrachill-users).
 *
 * As of the eu#76 auth-stack consolidation, the duplicated token PRIMITIVES
 * that used to live here (signed-JWT access tokens, opaque refresh-token
 * generation/hashing, base64url codecs) have been DELETED. wp-native-auth
 * is now the single owner of token primitives — see
 * wp-native-auth/inc/tokens.php. The Extra Chill service layer
 * (inc/auth-tokens/service.php) delegates to those primitives while keeping
 * EC policy (2FA, community membership, invites, onboarding) on top.
 *
 * What remains here is the EC public UUID helper, which the extrachill-api
 * auth routes and the EC service layer both call. It is a thin validator,
 * not a token primitive, so it stays with extrachill-users.
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

/**
 * Validate that a string is a UUID v4 (device_id contract).
 *
 * Kept in extrachill-users because the extrachill/v1/auth/* REST routes and
 * the EC service layer call it directly. Mirrors wp-native-auth's
 * wp_native_auth_is_uuid_v4() pattern exactly; the two are independent thin
 * validators, not a shared primitive.
 *
 * @param string $uuid Candidate string.
 * @return bool True if $uuid is a UUID v4.
 */
function extrachill_users_is_uuid_v4( string $uuid ): bool {
	return (bool) preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid );
}
