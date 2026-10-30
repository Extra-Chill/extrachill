<?php
/**
 * Extra Chill Network feature-provider composition.
 *
 * @package ExtraChillNetwork
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Register the plugin's default feature providers in dependency order. */
function extrachill_network_register_default_feature_providers() {
	$available = static function () {
		return true;
	};

	extrachill_network_register_feature_provider( 'migrations', 'extrachill_network_boot_migrations_provider', $available );
	extrachill_network_register_feature_provider( 'ads', 'extrachill_network_boot_ads_provider', $available );
	extrachill_network_register_feature_provider( 'experiments', 'extrachill_network_boot_experiments_provider', $available );
	extrachill_network_register_feature_provider( 'taxonomy-classification', 'extrachill_network_boot_taxonomy_provider', $available );
	extrachill_network_register_feature_provider( 'community-artist-integrations', 'extrachill_network_boot_integrations_provider', $available );
	extrachill_network_register_feature_provider( 'commerce', 'extrachill_network_boot_commerce_provider', $available );
	extrachill_network_register_feature_provider( 'presentation', 'extrachill_network_boot_presentation_provider', $available );
	extrachill_network_register_feature_provider(
		'administration',
		'extrachill_network_boot_administration_provider',
		static function () {
			return is_admin() && is_network_admin();
		}
	);
}

/** Boot migration and site-lifecycle features. */
function extrachill_network_boot_migrations_provider() {
	require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/core/cross-site-content-migration.php';
	require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/core/legacy-path-redirects.php';
	require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/core/new-site-setup.php';

	if ( function_exists( 'wp_register_ability' ) ) {
		require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/Abilities/CrossSiteContentMigrationAbilities.php';
		new \ExtraChillNetwork\Abilities\CrossSiteContentMigrationAbilities();
	}
}

/** Boot advertising policy and delivery features. */
function extrachill_network_boot_ads_provider() {
	require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/core/ad-policy.php';
	require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/integrations/ad-delivery.php';
	require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/integrations/member-ad-benefit.php';

	if ( function_exists( 'wp_register_ability' ) ) {
		require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/Abilities/AdPolicyAbility.php';
		new \ExtraChillNetwork\Abilities\AdPolicyAbility();
	}
}

/** Boot experiment assignment features. */
function extrachill_network_boot_experiments_provider() {
	require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/core/experiments.php';

	if ( function_exists( 'wp_register_ability' ) ) {
		require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/Abilities/ExperimentAssignmentAbility.php';
		new \ExtraChillNetwork\Abilities\ExperimentAssignmentAbility();
	}
}

/** Boot network taxonomy and classification features. */
function extrachill_network_boot_taxonomy_provider() {
	require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/taxonomy/register.php';
	require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/taxonomy/genre.php';
	require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/taxonomy/network-terms.php';
	require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/taxonomy/term-classification.php';

	if ( function_exists( 'wp_register_ability' ) ) {
		require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/Abilities/TaxonomyCountAbilities.php';
		require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/Abilities/NetworkTermAbilities.php';
		new \ExtraChillNetwork\Abilities\TaxonomyCountAbilities();
		new \ExtraChillNetwork\Abilities\NetworkTermAbilities();
	}
}

/** Boot integrations that coordinate product-owned data across sites. */
function extrachill_network_boot_integrations_provider() {
	require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/integrations/artist-profile-discussions.php';
	require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/integrations/artist-term-binding-deletion.php';
	require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/NetworkStats/bootstrap.php';
	require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/NetworkStats/helpers.php';
}

/** Boot optional commerce authentication adapters. */
function extrachill_network_boot_commerce_provider() {
	require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/commerce/auth/bootstrap.php';
}

/** Boot theme, linking, asset, and generated-media presentation features. */
function extrachill_network_boot_presentation_provider() {
	require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/cross-site-links/cross-site-links.php';
	require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/cross-site-links/network-bridge.php';
	require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/theme/footer-main-menu.php';
	require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/theme/network-dropdown.php';
	require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/theme/site-title.php';
	require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/theme/admin-menu.php';
	require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/theme/404-content.php';
	require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/theme/dns-prefetch.php';
	require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/theme/emoji-deprecation.php';
	require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/theme/filter-bar.php';
	require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/theme/footer-links.php';
	require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/theme/social-links.php';
	require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/community-activity/community-activity.php';
	require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/community-activity/sidebar-widget.php';
	require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/assets.php';
	require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/cache/badge-count-warmer.php';

	if ( function_exists( 'wp_register_ability' ) ) {
		require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/Abilities/QRCodeAbility.php';
		new \ExtraChillNetwork\Abilities\QRCodeAbility();
	}

	if ( defined( 'DATAMACHINE_VERSION' ) ) {
		require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/og-cards/og-cards.php';

		if ( function_exists( 'wp_register_ability' ) ) {
			require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/Abilities/OgCardRegenerationAbility.php';
			new \ExtraChillNetwork\Abilities\OgCardRegenerationAbility();
		}
	}
}

/** Boot network-administration screens. */
function extrachill_network_boot_administration_provider() {
	require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'admin/network-menu.php';
	require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'admin/legacy-admin-tools-redirect.php';
	require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'admin/network-security-settings.php';
	require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'admin/network-payments-settings.php';
	require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'admin/network-oauth-settings.php';
	require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'admin/network-shipping-settings.php';
	require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'admin/network-integrations-settings.php';
	require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'admin/network-ad-settings.php';
}
