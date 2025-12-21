<?php
/**
 * DM Events Integration
 *
 * Complete datamachine-events integration: badge/button class mapping, breadcrumb override,
 * related events, theme hook bridging, CSS enqueuing, and post meta management.
 *
 * @package ExtraChillEvents
 * @since 0.1.0
 */

namespace ExtraChillEvents;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * DataMachineEventsIntegration
 *
 * Bridges datamachine-events plugin with ExtraChill theme via filters and actions.
 * Enhances badge styling, button classes, breadcrumbs, and related events
 * without modifying datamachine-events templates.
 *
 * @since 0.1.0
 */
class DataMachineEventsIntegration {

    public function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        if (class_exists('DataMachineEvents\\Blocks\\Calendar\\Taxonomy_Badges')) {
            add_filter('datamachine_events_badge_wrapper_classes', array($this, 'add_wrapper_classes'), 10, 2);
            add_filter('datamachine_events_badge_classes', array($this, 'add_badge_classes'), 10, 4);
            add_filter('datamachine_events_excluded_taxonomies', array($this, 'exclude_taxonomies'), 10, 2);
        }

        // Register taxonomies for datamachine_events post type
        $this->register_event_taxonomies();

        add_filter('datamachine_events_modal_button_classes', array($this, 'add_modal_button_classes'), 10, 2);
        add_filter('datamachine_events_ticket_button_classes', array($this, 'add_ticket_button_classes'), 10, 1);
        add_filter('datamachine_events_more_info_button_classes', array($this, 'add_more_info_button_classes'), 10, 1);
        add_filter('datamachine_events_archive_title', array($this, 'filter_archive_title'), 10, 2);

        add_filter('extrachill_post_meta', array($this, 'hide_post_meta_for_events'), 10, 3);
        add_filter('extrachill_taxonomy_badges_skip_term', array($this, 'skip_duplicate_promoter'), 10, 4);

        add_action('wp_enqueue_scripts', array($this, 'enqueue_single_post_styles'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_calendar_styles'));
    }

    /**
     * Register taxonomies for datamachine_events post type
     *
     * Associates existing theme taxonomies with the datamachine_events post type
     * so they appear in the admin sidebar during post editing.
     *
     * @return void
     */
    private function register_event_taxonomies() {
        if (!class_exists('DataMachineEvents\\Core\\Event_Post_Type')) {
            return;
        }

        $post_type = \DataMachineEvents\Core\Event_Post_Type::POST_TYPE;

        // Register location taxonomy
        if (taxonomy_exists('location')) {
            register_taxonomy_for_object_type('location', $post_type);
        }

        // Register artist taxonomy
        if (taxonomy_exists('artist')) {
            register_taxonomy_for_object_type('artist', $post_type);
        }

        // Register festival taxonomy
        if (taxonomy_exists('festival')) {
            register_taxonomy_for_object_type('festival', $post_type);
        }
    }

    /**
     * Add theme-compatible wrapper class to badge container
     *
     * @hook datamachine_events_badge_wrapper_classes
     * @param array $wrapper_classes Default wrapper classes from datamachine-events
     * @param int $post_id Event post ID
     * @return array Enhanced wrapper classes with theme compatibility
     * @since 0.1.0
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
     * @hook datamachine_events_badge_classes
     * @param array $badge_classes Default badge classes from datamachine-events
     * @param string $taxonomy_slug Taxonomy name (festival, venue, location, etc.)
     * @param \WP_Term $term The taxonomy term object
     * @param int $post_id Event post ID
     * @return array Enhanced badge classes with taxonomy-specific styling
     * @since 0.1.0
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

            case 'venue':
                $badge_classes[] = 'venue-badge';
                $badge_classes[] = 'venue-' . esc_attr($term->slug);
                break;
        }

        return $badge_classes;
    }

    /**
     * Exclude taxonomies from badge and modal display
     *
     * Artist taxonomy excluded to prevent redundant display with artist-specific metadata.
     *
     * @hook datamachine_events_excluded_taxonomies
     * @param array  $excluded Array of taxonomy slugs to exclude
     * @param string $context  Context identifier: 'badge', 'modal'
     * @return array Enhanced exclusion array
     * @since 0.1.0
     */
    public function exclude_taxonomies($excluded, $context = '') {
        $excluded[] = 'artist';

        if ($context !== 'modal') {
            return array_values(array_unique($excluded));
        }

        if (!class_exists('DataMachineEvents\\Core\\Event_Post_Type')) {
            return array_values(array_unique($excluded));
        }

        $taxonomies = get_object_taxonomies(\DataMachineEvents\Core\Event_Post_Type::POST_TYPE, 'names');
        if (empty($taxonomies) || is_wp_error($taxonomies)) {
            return array_values(array_unique($excluded));
        }

        foreach ($taxonomies as $taxonomy_slug) {
            if ($taxonomy_slug === 'location') {
                continue;
            }

            $excluded[] = $taxonomy_slug;
        }

        return array_values(array_unique($excluded));
    }

    /**
     * Add theme button classes to datamachine-events modal buttons
     *
     * Maps WordPress admin button classes (button-primary/secondary) to theme
     * button styling classes. Primary buttons get button-1 (blue accent) with
     * large size, secondary buttons get button-3 (neutral) with medium size.
     *
     * @hook datamachine_events_modal_button_classes
     * @param array $classes Default button classes from datamachine-events
     * @param string $button_type Button type ('primary' or 'secondary')
     * @return array Enhanced button classes with theme styling
     * @since 0.1.0
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
     * Add theme button classes to datamachine-events ticket button
     *
     * Applies primary theme button styling (button-1) with large size
     * to ticket purchase links for prominent call-to-action appearance.
     *
     * @hook datamachine_events_ticket_button_classes
     * @param array $classes Default button classes from datamachine-events
     * @return array Enhanced button classes with theme styling
     * @since 0.1.0
     */
    public function add_ticket_button_classes($classes) {
        $classes[] = 'button-1';
        $classes[] = 'button-large';
        return $classes;
    }

    /**
     * Add theme button classes to datamachine-events more info button
     *
     * Applies neutral theme button styling (button-3) with small size
     * to calendar card "More Info" links for secondary call-to-action appearance.
     *
     * @hook datamachine_events_more_info_button_classes
     * @param array $classes Default button classes from datamachine-events
     * @return array Enhanced button classes with theme styling
     * @since 0.1.0
     */
    public function add_more_info_button_classes($classes) {
        $classes[] = 'button-3';
        $classes[] = 'button-small';
        return $classes;
    }

    public function filter_archive_title($title, $context) {
        $suffix = 'Events Calendar';

        if ($title === $suffix) {
            return 'Live Music Calendar';
        }

        if (strlen($title) > strlen($suffix) && substr($title, -strlen($suffix)) === $suffix) {
            $prefix = rtrim(substr($title, 0, -strlen($suffix)));
            return $prefix . ' Live Music Calendar';
        }

        return $title;
    }

    /**
     * Hide post meta for datamachine_events post type
     *
     * Event meta handled by datamachine-events plugin, prevents duplicate display.
     *
     * @hook extrachill_post_meta
     * @param string $default_meta Default post meta HTML from theme
     * @param int $post_id Post ID
     * @param string $post_type Post type
     * @return string Empty for datamachine_events, unchanged for other post types
     * @since 0.1.0
     */
    public function hide_post_meta_for_events($default_meta, $post_id, $post_type) {
        if ($post_type === 'datamachine_events') {
            return '';
        }
        return $default_meta;
    }

    /**
     * Enqueue single event page styles
     *
     * Loads three CSS files for datamachine_events post type:
     * 1. Theme's single-post.css (post layout and typography)
     * 2. Theme's sidebar.css (sidebar styling)
     * 3. Plugin's single-event.css (event-specific card treatment and action buttons)
     *
     * @hook wp_enqueue_scripts
     * @return void
     * @since 0.1.0
     */
    public function enqueue_single_post_styles() {
        if (!is_singular('datamachine_events')) {
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

        $sidebar_css = $theme_dir . '/assets/css/sidebar.css';

        if (file_exists($sidebar_css)) {
            wp_enqueue_style(
                'extrachill-sidebar',
                $theme_uri . '/assets/css/sidebar.css',
                array('extrachill-root', 'extrachill-style'),
                filemtime($sidebar_css)
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
     * @since 0.1.0
     */
    public function enqueue_calendar_styles() {
        $events_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'events' ) : null;
        if ( ! $events_blog_id || get_current_blog_id() !== $events_blog_id || ! is_front_page() ) {
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

    /**
     * Skip promoter badge if name matches venue name
     *
     * Prevents redundant display when promoter and venue are the same entity.
     * Mirrors logic from datamachine-events Calendar block Taxonomy_Badges.
     *
     * @hook extrachill_taxonomy_badges_skip_term
     * @param bool $skip Whether to skip this term
     * @param WP_Term $term The term being rendered
     * @param string $taxonomy The taxonomy slug
     * @param int $post_id The post ID
     * @return bool True to skip promoter matching venue, unchanged otherwise
     */
    public function skip_duplicate_promoter($skip, $term, $taxonomy, $post_id) {
        if ($taxonomy !== 'promoter') {
            return $skip;
        }

        $venue_terms = get_the_terms($post_id, 'venue');
        if (!$venue_terms || is_wp_error($venue_terms)) {
            return $skip;
        }

        $venue_name = $venue_terms[0]->name;
        if (strcasecmp(trim($term->name), trim($venue_name)) === 0) {
            return true;
        }

        return $skip;
    }
}
