<?php
/**
 * DM Events Integration
 *
 * Maps dm-events taxonomy badges to ExtraChill's badge class structure.
 * Overrides breadcrumbs with theme system. Hides post meta for dm_events post type.
 * Adds related events and theme hook bridges.
 *
 * @package ExtraChillEvents
 * @since 1.0.0
 */

namespace ExtraChillEvents;

if (!defined('ABSPATH')) {
    exit;
}

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

        add_action('dm_events_related_events', array($this, 'display_related_events'), 10, 1);
        add_action('dm_events_before_single_event', array($this, 'before_single_event'));
        add_action('dm_events_after_single_event', array($this, 'after_single_event'));

        add_filter('extrachill_post_meta', array($this, 'hide_post_meta_for_events'), 10, 3);

        add_action('wp_enqueue_scripts', array($this, 'enqueue_single_post_styles'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_calendar_styles'));
    }

    /**
     * @param array $wrapper_classes Default wrapper classes from plugin
     * @param int $post_id Event post ID
     * @return array Enhanced wrapper classes including theme compatibility
     */
    public function add_wrapper_classes($wrapper_classes, $post_id) {
        $wrapper_classes[] = 'taxonomy-badges';
        return $wrapper_classes;
    }

    /**
     * Maps festival/location taxonomies to ExtraChill badge classes for custom colors.
     *
     * @param array $badge_classes Default badge classes from plugin
     * @param string $taxonomy_slug Taxonomy name (festival, venue, etc.)
     * @param \WP_Term $term The taxonomy term object
     * @param int $post_id Event post ID
     * @return array Enhanced badge classes with theme compatibility
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
     * @param string|null $breadcrumbs Plugin's default breadcrumb HTML (null = use plugin default)
     * @param int $post_id Event post ID
     * @return string|null Theme breadcrumb HTML or null to use plugin default
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
     * @param int $event_id Event post ID
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

    public function before_single_event() {
        do_action('extrachill_before_body_content');
    }

    public function after_single_event() {
        do_action('extrachill_after_body_content');
    }

    /**
     * Hide post meta for dm_events post type
     *
     * @param string $default_meta Default post meta HTML
     * @param int $post_id Post ID
     * @param string $post_type Post type
     * @return string Empty string for dm_events, original meta for others
     */
    public function hide_post_meta_for_events($default_meta, $post_id, $post_type) {
        if ($post_type === 'dm_events') {
            return '';
        }
        return $default_meta;
    }

    /**
     * Enqueue theme's single-post.css and plugin's single-event.css for dm_events post type
     */
    public function enqueue_single_post_styles() {
        if (!is_singular('dm_events')) {
            return;
        }

        // Theme's single-post.css
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

        // Plugin's single event enhancements
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
     * Enqueue calendar.css for events homepage
     */
    public function enqueue_calendar_styles() {
        // Only on events homepage (blog ID 7)
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