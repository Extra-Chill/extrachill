<?php
/**
 * DM Events Plugin Integration Class
 *
 * Provides seamless integration between the DM Events WordPress plugin
 * and ExtraChill themes' badge styling system. Hooks into the plugin's
 * badge rendering filters to inject theme-compatible CSS classes, enabling
 * DM Events taxonomies to display with ExtraChill's custom colors and styling.
 *
 * @package ExtraChillEvents
 * @since 1.0.0
 */

namespace ExtraChillEvents;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * DM Events Integration Class
 *
 * FUNCTIONALITY:
 * - Maps DM Events taxonomy badges to ExtraChill's badge class structure
 * - Enables festival-specific colors (e.g., Bonnaroo, Coachella) from badge-colors.css
 * - Converts venue taxonomies to location styling for regional color coding
 * - Maintains plugin compatibility while adding theme-specific enhancements
 *
 * TAXONOMY MAPPING:
 * - festival → festival-badge festival-{slug} (e.g., festival-bonnaroo)
 * - venue → location-badge location-{slug} (e.g., location-charleston)
 * - other taxonomies → category-badge category-{slug}-badge
 *
 * TECHNICAL DETAILS:
 * - Hooks: dm_events_badge_classes, dm_events_badge_wrapper_classes, dm_events_breadcrumbs
 * - Preserves plugin's original classes for backwards compatibility
 * - Adds ExtraChill classes alongside existing ones for dual compatibility
 * - Automatically applies existing badge-colors.css styling rules
 */
class DmEventsIntegration {

    /**
     * Constructor - Initialize the integration
     */
    public function __construct() {
        $this->init_hooks();
    }

    /**
     * Initialize WordPress hooks for DM Events integration
     */
    private function init_hooks() {
        // Badge wrapper integration
        if (class_exists('DmEvents\Core\Taxonomy_Badges')) {
            add_filter('dm_events_badge_wrapper_classes', array($this, 'add_wrapper_classes'), 10, 2);
            add_filter('dm_events_badge_classes', array($this, 'add_badge_classes'), 10, 4);
        }

        // Breadcrumb integration
        if (class_exists('DmEvents\Core\Breadcrumbs')) {
            add_filter('dm_events_breadcrumbs', array($this, 'override_breadcrumbs'), 10, 2);
        }
    }

    /**
     * Add ExtraChill-compatible classes to DM Events badge wrapper
     *
     * @param array $wrapper_classes Default wrapper classes from plugin
     * @param int $post_id Event post ID
     * @return array Enhanced wrapper classes including theme compatibility
     */
    public function add_wrapper_classes($wrapper_classes, $post_id) {
        // Add ExtraChill's taxonomy-badges class for consistent styling
        $wrapper_classes[] = 'taxonomy-badges';

        return $wrapper_classes;
    }

    /**
     * Add ExtraChill-compatible classes to individual DM Events badges
     *
     * Maps plugin taxonomy structure to ExtraChill's badge class patterns,
     * enabling automatic application of custom festival and location colors
     * from the theme's badge-colors.css stylesheet.
     *
     * @param array $badge_classes Default badge classes from plugin
     * @param string $taxonomy_slug Taxonomy name (festival, venue, etc.)
     * @param \WP_Term $term The taxonomy term object
     * @param int $post_id Event post ID
     * @return array Enhanced badge classes with theme compatibility
     */
    public function add_badge_classes($badge_classes, $taxonomy_slug, $term, $post_id) {
        // Always add base taxonomy-badge class for ExtraChill styling
        $badge_classes[] = 'taxonomy-badge';

        // Only map taxonomies that have custom colors in ExtraChill's badge-colors.css
        switch ($taxonomy_slug) {
            case 'festival':
                // Festival badges get custom colors: taxonomy-badge festival-badge festival-{slug}
                $badge_classes[] = 'festival-badge';
                $badge_classes[] = 'festival-' . esc_attr($term->slug);
                break;

            case 'location':
                // Location badges get custom colors: taxonomy-badge location-badge location-{slug}
                $badge_classes[] = 'location-badge';
                $badge_classes[] = 'location-' . esc_attr($term->slug);
                break;

            // No default case - other taxonomies use plugin's default styling
        }

        return $badge_classes;
    }

    /**
     * Override DM Events breadcrumbs with ExtraChill's breadcrumb system
     *
     * Replaces the plugin's default breadcrumb output with the theme's comprehensive
     * breadcrumb system when available, ensuring consistent navigation experience
     * across all pages in ExtraChill themes.
     *
     * @param string|null $breadcrumbs Plugin's default breadcrumb HTML (null = use plugin default)
     * @param int $post_id Event post ID
     * @return string|null Theme breadcrumb HTML or null to use plugin default
     */
    public function override_breadcrumbs($breadcrumbs, $post_id) {
        // Use ExtraChill's breadcrumb system if available
        if (function_exists('display_breadcrumbs')) {
            ob_start();
            display_breadcrumbs();
            return ob_get_clean();
        }

        // Fall back to plugin's default breadcrumbs
        return $breadcrumbs;
    }
}