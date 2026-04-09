<?php
/**
 * Abilities Registration
 *
 * Registers the extrachill-newsletter ability category and loads all ability files.
 * Each file registers its own abilities on the wp_abilities_api_init hook.
 *
 * @package ExtraChillNewsletter
 * @since 0.3.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_categories_init', 'extrachill_newsletter_register_category' );

/**
 * Register newsletter ability category.
 */
function extrachill_newsletter_register_category() {
	wp_register_ability_category(
		'extrachill-newsletter',
		array(
			'label'       => __( 'Extra Chill Newsletter', 'extrachill-newsletter' ),
			'description' => __( 'Newsletter subscriptions, campaign management, Sendy integration, and settings.', 'extrachill-newsletter' ),
		)
	);
}

// Load ability files — each self-registers on wp_abilities_api_init.
require_once __DIR__ . '/subscribe.php';
require_once __DIR__ . '/sync.php';
require_once __DIR__ . '/campaign.php';
require_once __DIR__ . '/campaign-management.php';
require_once __DIR__ . '/settings.php';
