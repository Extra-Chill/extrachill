<?php
/**
 * DM Events Integration
 *
 * Complete dm-events integration: badge/button class mapping, breadcrumb override,
 * related events, theme hook bridging, CSS enqueuing, and post meta management.
 *
 * @package ExtraChillEvents
 * @since 1.0.0
 */

namespace ExtraChillEvents;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * DmEventsIntegration
 *
 * Bridges dm-events plugin with ExtraChill theme via filters and actions.
 * Enhances badge styling, button classes, breadcrumbs, and related events
 * without modifying dm-events templates.
 *
 * @since 1.0.0
 */
class DmEventsIntegration {

    public function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        if (class_exists('DmEvents\Core\Taxonomy_Badges')) {
            add_filter('dm_events_badge_wrapper_classes', array($this, 'add_wrapper_classes'), 10, 2);
            add_filter('dm_events_badge_classes', array($this, 'add_badge_classes'), 10, 4);
        }

        if (class_exists('DmEvents\Core\Breadcrumbs')) {
            add_filter('dm_events_breadcrumbs', array($this, 'override_breadcrumbs'), 10, 2);
        }

        add_filter('dm_events_modal_button_classes', array($this, 'add_modal_button_classes'), 10, 2);
        add_filter('dm_events_ticket_button_classes', array($this, 'add_ticket_button_classes'), 10, 1);

        add_action('dm_events_related_events', array($this, 'display_related_events'), 10, 1);
        add_action('dm_events_before_single_event', array($this, 'before_single_event'));
        add_action('dm_events_after_single_event', array($this, 'after_single_event'));

        add_filter('extrachill_post_meta', array($this, 'hide_post_meta_for_events'), 10, 3);

        add_action('wp_enqueue_scripts', array($this, 'enqueue_single_post_styles'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_calendar_styles'));
    }

    /**
     * Add theme-compatible wrapper class to badge container
     *
     * @hook dm_events_badge_wrapper_classes
     * @param array $wrapper_classes Default wrapper classes from dm-events
     * @param int $post_id Event post ID
     * @return array Enhanced wrapper classes with theme compatibility
     * @since 1.0.0
     */
    public function add_wrapper_classes($wrapper_classes, $post_id) {
        $wrapper_classes[] = 'taxonomy-badges';
        return $wrapper_classes;
    }

    /**
     * Map festival/location taxonomies to theme badge classes
     *
     * Enables custom colors from theme's badge-colors.css via taxonomy-specific
     * classes (e.g., festival-bonnaroo, location-charleston).
     *
     * @hook dm_events_badge_classes
     * @param array $badge_classes Default badge classes from dm-events
     * @param string $taxonomy_slug Taxonomy name (festival, venue, location, etc.)
     * @param \WP_Term $term The taxonomy term object
     * @param int $post_id Event post ID
     * @return array Enhanced badge classes with taxonomy-specific styling
     * @since 1.0.0
     */
    public function add_badge_classes($badge_classes, $taxonomy_slug, $term, $post_id) {
        $badge_classes[] = 'taxonomy-badge';

        switch ($taxonomy_slug) {
            case 'festival':
                $badge_classes[] = 'festival-badge';
                $badge_classes[] = 'festival-' . esc_attr($term->slug);
                break;

            case 'location':
                $badge_classes[] = 'location-badge';
                $badge_classes[] = 'location-' . esc_attr($term->slug);
                break;
        }

        return $badge_classes;
    }

    /**
     * Add theme button classes to dm-events modal buttons
     *
     * Maps WordPress admin button classes (button-primary/secondary) to theme
     * button styling classes. Primary buttons get button-1 (blue accent) with
     * large size, secondary buttons get button-3 (neutral) with medium size.
     *
     * @hook dm_events_modal_button_classes
     * @param array $classes Default button classes from dm-events
     * @param string $button_type Button type ('primary' or 'secondary')
     * @return array Enhanced button classes with theme styling
     * @since 1.0.0
     */
    public function add_modal_button_classes($classes, $button_type) {
        switch ($button_type) {
            case 'primary':
                $classes[] = 'button-1';
                $classes[] = 'button-large';
                break;
            case 'secondary':
                $classes[] = 'button-3';
                $classes[] = 'button-medium';
                break;
        }
        return $classes;
    }

    /**
     * Add theme button classes to dm-events ticket button
     *
     * Applies primary theme button styling (button-1) with large size
     * to ticket purchase links for prominent call-to-action appearance.
     *
     * @hook dm_events_ticket_button_classes
     * @param array $classes Default button classes from dm-events
     * @return array Enhanced button classes with theme styling
     * @since 1.0.0
     */
    public function add_ticket_button_classes($classes) {
        $classes[] = 'button-1';
        $classes[] = 'button-large';
        return $classes;
    }

    /**
     * Override dm-events breadcrumbs with theme breadcrumb system
     *
     * Replaces dm-events breadcrumbs with theme's display_breadcrumbs() function
     * for consistent breadcrumb styling across site.
     *
     * @hook dm_events_breadcrumbs
     * @param string|null $breadcrumbs Plugin's default breadcrumb HTML
     * @param int $post_id Event post ID
     * @return string|null Theme breadcrumb HTML if available, otherwise plugin default
     * @since 1.0.0
     */
    public function override_breadcrumbs($breadcrumbs, $post_id) {
        if (function_exists('display_breadcrumbs')) {
            ob_start();
            display_breadcrumbs();
            return ob_get_clean();
        }

        return $breadcrumbs;
    }

    /**
     * Display related events by festival and venue taxonomies
     *
     * Uses theme's related posts function to show events from same festival/venue.
     * Only applies on blog ID 7 (events.extrachill.com).
     *
     * @hook dm_events_related_events
     * @param int $event_id Event post ID
     * @return void
     * @since 1.0.0
     */
    public function display_related_events($event_id) {
        if (get_current_blog_id() !== 7) {
            return;
        }

        if (function_exists('extrachill_display_related_posts')) {
            extrachill_display_related_posts('festival', $event_id);
            extrachill_display_related_posts('venue', $event_id);
        }
    }

    /**
     * Bridge dm-events before-event hook to theme's before-content hook
     *
     * @hook dm_events_before_single_event
     * @return void
     * @since 1.0.0
     */
    public function before_single_event() {
        do_action('extrachill_before_body_content');
    }

    /**
     * Bridge dm-events after-event hook to theme's after-content hook
     *
     * @hook dm_events_after_single_event
     * @return void
     * @since 1.0.0
     */
    public function after_single_event() {
        do_action('extrachill_after_body_content');
    }

    /**
     * Hide post meta for dm_events post type
     *
     * Event meta handled by dm-events plugin, prevents duplicate display.
     *
     * @hook extrachill_post_meta
     * @param string $default_meta Default post meta HTML from theme
     * @param int $post_id Post ID
     * @param string $post_type Post type
     * @return string Empty for dm_events, unchanged for other post types
     * @since 1.0.0
     */
    public function hide_post_meta_for_events($default_meta, $post_id, $post_type) {
        if ($post_type === 'dm_events') {
            return '';
        }
        return $default_meta;
    }

    /**
     * Enqueue single event page styles
     *
     * Loads theme's single-post.css and plugin's single-event.css
     * for dm_events post type pages only.
     *
     * @hook wp_enqueue_scripts
     * @return void
     * @since 1.0.0
     */
    public function enqueue_single_post_styles() {
        if (!is_singular('dm_events')) {
            return;
        }

        $theme_dir = get_template_directory();
        $theme_uri = get_template_directory_uri();
        $single_post_css = $theme_dir . '/assets/css/single-post.css';

        if (file_exists($single_post_css)) {
            wp_enqueue_style(
                'extrachill-single-post',
                $theme_uri . '/assets/css/single-post.css',
                array('extrachill-style'),
                filemtime($single_post_css)
            );
        }

        $single_event_css = EXTRACHILL_EVENTS_PLUGIN_DIR . 'assets/css/single-event.css';

        if (file_exists($single_event_css)) {
            wp_enqueue_style(
                'extrachill-events-single',
                EXTRACHILL_EVENTS_PLUGIN_URL . 'assets/css/single-event.css',
                array('extrachill-style'),
                filemtime($single_event_css)
            );
        }
    }

    /**
     * Enqueue calendar styles for events homepage
     *
     * Only loads on blog ID 7 (events.extrachill.com) homepage.
     *
     * @hook wp_enqueue_scripts
     * @return void
     * @since 1.0.0
     */
    public function enqueue_calendar_styles() {
        if (get_current_blog_id() !== 7 || !is_front_page()) {
            return;
        }

        $calendar_css = EXTRACHILL_EVENTS_PLUGIN_DIR . 'assets/css/calendar.css';

        if (file_exists($calendar_css)) {
            wp_enqueue_style(
                'extrachill-events-calendar',
                EXTRACHILL_EVENTS_PLUGIN_URL . 'assets/css/calendar.css',
                array('extrachill-style'),
                filemtime($calendar_css)
            );
        }
    }
}