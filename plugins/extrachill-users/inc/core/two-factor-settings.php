<?php
/**
 * Two-step sign-in management on the member Settings page.
 *
 * Members manage two-step sign-in (Two Factor) from Settings → Security,
 * never from Two Factor's wp-admin profile screen. The Settings block calls
 * Two Factor's own REST routes directly with cookie auth; this file only
 * answers whether those routes exist, so the block can show the section or
 * nothing at all.
 *
 * The routes come from WordPress/two-factor#935 (provider management and
 * server-owned authenticator enrollment) and #1045 (revalidation). Neither
 * has shipped in a Two Factor release yet, so on Two Factor 0.16.0 this
 * reports false and the Settings page renders no two-step sign-in controls.
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

/**
 * Two Factor REST routes the Settings section depends on.
 *
 * Keys are route patterns exactly as Two Factor registers them.
 *
 * @return list<string>
 */
function extrachill_users_two_factor_settings_routes(): array {
	return array(
		'/two-factor/1.0/users/(?P<user_id>[\d]+)/providers',
		'/two-factor/1.0/totp/enrollment',
		'/two-factor/1.0/totp',
		'/two-factor/1.0/generate-backup-codes',
		'/two-factor/1.0/revalidate',
	);
}

/**
 * Whether the installed Two Factor plugin ships the management surface.
 *
 * Cheap class/method checks run first, so a site on a Two Factor release
 * without these routes never boots the REST server on a page render.
 */
function extrachill_users_two_factor_settings_api_present(): bool {
	return class_exists( 'Two_Factor_Core' )
		&& is_callable( array( 'Two_Factor_Core', 'register_rest_routes' ) )
		&& is_callable( array( 'Two_Factor_Core', 'register_login_rest_routes' ) );
}

/**
 * Whether every route the Settings section calls is registered.
 *
 * A provider filtered out of Two Factor (for example the authenticator app)
 * leaves its routes unregistered, so the section hides rather than offering
 * controls that would fail.
 */
function extrachill_users_two_factor_settings_routes_registered(): bool {
	$routes = rest_get_server()->get_routes();

	foreach ( extrachill_users_two_factor_settings_routes() as $route ) {
		if ( ! isset( $routes[ $route ] ) ) {
			return false;
		}
	}

	return true;
}

/**
 * Whether Settings → Security can offer two-step sign-in management.
 *
 * When false the Settings page shows nothing about two-step sign-in. It
 * never falls back to a link to Two Factor's own screens.
 */
function extrachill_users_two_factor_settings_available(): bool {
	$available = extrachill_users_two_factor_settings_api_present()
		&& extrachill_users_two_factor_settings_routes_registered();

	/**
	 * Filter whether Settings → Security offers two-step sign-in management.
	 *
	 * @param bool $available Whether Two Factor's management routes are registered.
	 */
	return (bool) apply_filters( 'extrachill_users_two_factor_settings_available', $available );
}

/**
 * URL of the Security tab on the member Settings page.
 *
 * Use this anywhere a member is sent to manage two-step sign-in.
 */
function extrachill_users_security_settings_url(): string {
	return extrachill_users_settings_url() . '#tab-security';
}
