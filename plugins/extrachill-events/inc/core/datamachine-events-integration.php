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
        if (class_exists('DataMachineEvents\Core\Taxonomy_Badges')) {
            add_filter('datamachine_events_badge_wrapper_classes', array($this, 'add_wrapper_classes'), 10, 2);
            add_filter('datamachine_events_badge_classes', array($this, 'add_badge_classes'), 10, 4);
            add_filter('datamachine_events_excluded_taxonomies', array($this, 'exclude_venue_taxonomy'));
        }

        if (class_exists('DataMachineEvents\Core\Breadcrumbs')) {
            add_filter('datamachine_events_breadcrumbs', array($this, 'override_breadcrumbs'), 10, 2);
        }

        add_filter('datamachine_events_modal_button_classes', array($this, 'add_modal_button_classes'), 10, 2);
        add_filter('datamachine_events_ticket_button_classes', array($this, 'add_ticket_button_classes'), 10, 1);

        add_filter('extrachill_related_posts_taxonomies', array($this, 'filter_event_taxonomies'), 10, 3);
        add_filter('extrachill_related_posts_allowed_taxonomies', array($this, 'allow_event_taxonomies'), 10, 2);
        add_filter('extrachill_related_posts_query_args', array($this, 'filter_event_query_args'), 10, 4);
        add_filter('extrachill_related_posts_tax_query', array($this, 'exclude_venue_from_location'), 10, 5);

        add_action('datamachine_events_action_buttons', array($this, 'render_share_button'), 10, 2);

        add_filter('extrachill_post_meta', array($this, 'hide_post_meta_for_events'), 10, 3);

        add_action('wp_enqueue_scripts', array($this, 'enqueue_single_post_styles'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_calendar_styles'));
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
        }

        return $badge_classes;
    }

    /**
     * Exclude venue and artist taxonomies from badge display
     *
     * Venue taxonomy displayed separately via dedicated metadata fields.
     * Artist taxonomy excluded to prevent redundant display with artist-specific metadata.
     *
     * @hook datamachine_events_excluded_taxonomies
     * @param array $excluded Array of taxonomy slugs to exclude
     * @return array Enhanced exclusion array with venue and artist taxonomies
     * @since 0.1.0
     */
    public function exclude_venue_taxonomy($excluded) {
        $excluded[] = 'venue';
        $excluded[] = 'artist';
        return $excluded;
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
     * Override datamachine-events breadcrumbs with theme breadcrumb system
     *
     * Replaces datamachine-events breadcrumbs with theme's extrachill_breadcrumbs() function
     * for consistent breadcrumb styling across site.
     *
     * @hook datamachine_events_breadcrumbs
     * @param string|null $breadcrumbs Plugin's default breadcrumb HTML
     * @param int $post_id Event post ID
     * @return string Theme breadcrumb HTML
     * @since 0.1.0
     */
    public function override_breadcrumbs($breadcrumbs, $post_id) {
        ob_start();
        extrachill_breadcrumbs();
        return ob_get_clean();
    }

    /**
     * Use venue and location taxonomies for event posts
     *
     * Order matters:
     * 1. venue - Shows upcoming events at same venue
     * 2. location - Shows upcoming events in same location (different venues)
     *
     * @hook extrachill_related_posts_taxonomies
     * @param array  $taxonomies Default taxonomies (artist, venue)
     * @param int    $post_id    Current post ID
     * @param string $post_type  Current post type
     * @return array Modified taxonomies for event posts
     * @since 0.1.0
     */
    public function filter_event_taxonomies($taxonomies, $post_id, $post_type) {
        if (get_current_blog_id() === 7 && $post_type === 'datamachine_events') {
            return array('venue', 'location');
        }
        return $taxonomies;
    }

    /**
     * Allow location taxonomy in related posts whitelist
     *
     * @hook extrachill_related_posts_allowed_taxonomies
     * @param array  $allowed   Default allowed taxonomies
     * @param string $post_type Current post type
     * @return array Modified allowed taxonomies
     * @since 0.1.0
     */
    public function allow_event_taxonomies($allowed, $post_type) {
        if ($post_type === 'datamachine_events') {
            return array_merge($allowed, array('location'));
        }
        return $allowed;
    }

    /**
     * Modify query for event posts: change post type and add upcoming events filter
     *
     * Only shows future events by adding meta_query comparing event datetime
     * to current datetime. Orders by event date ascending (soonest first).
     *
     * @hook extrachill_related_posts_query_args
     * @param array  $query_args Default query args
     * @param string $taxonomy   Current taxonomy being queried
     * @param int    $post_id    Current post ID
     * @param string $post_type  Current post type
     * @return array Modified query args for event posts
     * @since 0.1.0
     */
    public function filter_event_query_args($query_args, $taxonomy, $post_id, $post_type) {
        if (get_current_blog_id() !== 7 || $post_type !== 'datamachine_events') {
            return $query_args;
        }

        $query_args['post_type'] = 'datamachine_events';

        $query_args['meta_query'] = array(
            array(
                'key'     => '_datamachine_event_datetime',
                'value'   => current_time('mysql'),
                'compare' => '>=',
                'type'    => 'DATETIME',
            ),
        );

        $query_args['meta_key'] = '_datamachine_event_datetime';
        $query_args['orderby']  = 'meta_value';
        $query_args['order']    = 'ASC';

        return $query_args;
    }

    /**
     * Exclude same venue when showing location-based related events
     *
     * When displaying location-based related events, exclude events at the same venue
     * to provide variety. This prevents showing duplicate venue events in both sections.
     *
     * @hook extrachill_related_posts_tax_query
     * @param array  $tax_query Tax query array
     * @param string $taxonomy  Current taxonomy being queried
     * @param int    $term_id   Current term ID
     * @param int    $post_id   Current post ID
     * @param string $post_type Current post type
     * @return array Modified tax query with venue exclusion for location queries
     * @since 0.1.0
     */
    public function exclude_venue_from_location($tax_query, $taxonomy, $term_id, $post_id, $post_type) {
        if (get_current_blog_id() !== 7 || $post_type !== 'datamachine_events' || $taxonomy !== 'location') {
            return $tax_query;
        }

        $venue_terms = get_the_terms($post_id, 'venue');
        if (!$venue_terms || is_wp_error($venue_terms)) {
            return $tax_query;
        }

        $venue_term_ids = wp_list_pluck($venue_terms, 'term_id');
        
        $tax_query[] = array(
            'taxonomy' => 'venue',
            'field'    => 'term_id',
            'terms'    => $venue_term_ids,
            'operator' => 'NOT IN',
        );

        return $tax_query;
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

        $share_css = get_template_directory() . '/assets/css/share.css';

        if (file_exists($share_css)) {
            wp_enqueue_style(
                'extrachill-share',
                get_template_directory_uri() . '/assets/css/share.css',
                array('extrachill-style'),
                filemtime($share_css)
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

    /**
     * Render share button in event action buttons container
     *
     * Displays share button alongside ticket button using flexbox container.
     * Only applies on blog ID 7 (events.extrachill.com).
     *
     * @hook datamachine_events_action_buttons
     * @param int $post_id Event post ID
     * @param string $ticket_url Ticket URL (may be empty)
     * @return void
     * @since 0.1.0
     */
    public function render_share_button($post_id, $ticket_url) {
        if (get_current_blog_id() !== 7) {
            return;
        }

        extrachill_share_button(array(
            'share_url' => get_permalink($post_id),
            'share_title' => get_the_title($post_id),
            'button_size' => 'button-large'
        ));
    }
}