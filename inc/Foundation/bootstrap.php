<?php
/**
 * Generic network foundation bootstrap and feature-provider lifecycle.
 *
 * @package ExtraChillNetwork\Foundation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Boot the APIs required by every network context.
 *
 * @return void
 */
function extrachill_network_boot_foundation() {
	static $booted = false;

	if ( $booted ) {
		return;
	}

	require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/core/blog-ids.php';
	require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/link-pages/site-integration.php';
	require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/media/image-optimization.php';
	require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/core/mail.php';
	require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/core/service-assertions.php';
	require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/core/cross-site-rest.php';
	require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/core/ability-site-affinity.php';
	require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/core/frontend-path-resolver.php';
	require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/core/extrachill-turnstile.php';
	require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/core/oauth-helpers.php';
	require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/core/object-cache-config.php';
	require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/core/upload-url-host.php';
	require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/core/mcp-transport-redirect.php';

	if ( function_exists( 'wp_register_ability' ) ) {
		require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/Abilities/CategoryRegistration.php';
		require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/Abilities/NetworkMediaAbilities.php';
		require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/Abilities/MailAbilities.php';
		require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/Editor/BlogResolver.php';
		require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/Editor/Permissions.php';
		require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/Editor/LoadEnvelope.php';
		require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/Abilities/CommentEditorAbilities.php';

		new \ExtraChillNetwork\Abilities\NetworkMediaAbilities();
		new \ExtraChillNetwork\Abilities\MailAbilities();
		new \ExtraChillNetwork\Abilities\CommentEditorAbilities();
	}

	$booted = true;
}

/**
 * Register one named feature provider.
 *
 * A duplicate name is rejected so the first registration remains authoritative.
 *
 * @param string   $name         Stable provider name.
 * @param mixed $boot         Provider bootstrap callback.
 * @param mixed $is_available Predicate for optional prerequisites.
 * @return bool True when registered, false for an invalid or duplicate name.
 */
function extrachill_network_register_feature_provider( $name, $boot, $is_available ) {
	$name = trim( (string) $name );

	if ( '' === $name || ! is_callable( $boot ) || ! is_callable( $is_available ) ) {
		return false;
	}

	if ( ! isset( $GLOBALS['extrachill_network_feature_providers'] ) ) {
		$GLOBALS['extrachill_network_feature_providers'] = array();
	}

	if ( isset( $GLOBALS['extrachill_network_feature_providers'][ $name ] ) ) {
		return false;
	}

	$GLOBALS['extrachill_network_feature_providers'][ $name ] = array(
		'boot'         => $boot,
		'is_available' => $is_available,
		'status'       => 'registered',
	);

	return true;
}

/**
 * Boot all registered providers without allowing one optional feature to stop others.
 *
 * @return array<string,string> Provider statuses keyed by name.
 */
function extrachill_network_boot_feature_providers() {
	$providers = $GLOBALS['extrachill_network_feature_providers'] ?? array();
	$statuses  = array();

	foreach ( $providers as $name => $provider ) {
		if ( 'registered' !== $provider['status'] ) {
			$statuses[ $name ] = $provider['status'];
			continue;
		}

		try {
			if ( ! call_user_func( $provider['is_available'] ) ) {
				$GLOBALS['extrachill_network_feature_providers'][ $name ]['status'] = 'skipped';
				$statuses[ $name ] = 'skipped';
				do_action( 'extrachill_network_feature_provider_skipped', $name );
				continue;
			}

			call_user_func( $provider['boot'] );
			$GLOBALS['extrachill_network_feature_providers'][ $name ]['status'] = 'booted';
			$statuses[ $name ] = 'booted';
		} catch ( \Throwable $error ) {
			$GLOBALS['extrachill_network_feature_providers'][ $name ]['status'] = 'failed';
			$statuses[ $name ] = 'failed';
			do_action( 'extrachill_network_feature_provider_failed', $name, $error );
		}
	}

	return $statuses;
}
