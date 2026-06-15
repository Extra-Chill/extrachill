<?php
/**
 * Extra Chill Docs Ability Category
 *
 * Single owner for the `extrachill-docs` ability category.
 *
 * Abilities in this plugin (e.g. `extrachill-docs/upsert-doc-page`) assign
 * themselves to `'category' => 'extrachill-docs'`. WordPress core requires
 * the category to be registered on the dedicated categories hook
 * (`wp_abilities_api_categories_init`) BEFORE any ability assigns itself to
 * it on the later abilities hook (`wp_abilities_api_init`). Without this
 * registration, `WP_Abilities_Registry::register()` trips `_doing_it_wrong`
 * with a "category is not registered" notice on every init.
 *
 * @package ExtraChillDocs
 * @since   0.5.2
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_categories_init', 'extrachill_docs_register_ability_category' );

/**
 * Register the `extrachill-docs` ability category.
 *
 * Skipped cleanly when the Abilities API is not loaded (e.g. unit tests,
 * plugin deactivation transitions).
 *
 * @since 0.5.2
 * @return void
 */
function extrachill_docs_register_ability_category(): void {
	if ( ! function_exists( 'wp_register_ability_category' ) ) {
		return;
	}

	wp_register_ability_category(
		'extrachill-docs',
		array(
			'label'       => __( 'Extra Chill Documentation', 'extrachill-docs' ),
			'description' => __( 'Documentation sync and management operations for docs.extrachill.com', 'extrachill-docs' ),
		)
	);
}
