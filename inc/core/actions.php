<?php
/**
 * ExtraChill Theme Custom Action Hooks
 *
 * Centralized registration of CUSTOM template action hooks only.
 * WordPress core actions and filters remain in functions.php.
 *
 * @package ExtraChill
 * @since 69.57
 */

// Footer Menu Actions
/**
 * Default footer main content handler
 * Includes hardcoded main footer menu for performance while allowing plugin extensibility
 *
 * @since 69.57
 */
function extrachill_default_footer_main_content() {
    include get_template_directory() . '/inc/footer/footer-main-menu.php';
}
add_action('extrachill_footer_main_content', 'extrachill_default_footer_main_content', 10);

/**
 * Default below copyright handler
 * Includes hardcoded bottom footer menu (legal/policy links)
 *
 * @since 69.57
 */
function extrachill_default_below_copyright() {
    include get_template_directory() . '/inc/footer/footer-bottom-menu.php';
}
add_action('extrachill_below_copyright', 'extrachill_default_below_copyright', 10);

// Navigation Menu Actions
/**
 * Default navigation main menu handler
 * Includes hardcoded main navigation for performance while allowing plugin extensibility
 *
 * @since 69.57
 */
function extrachill_default_navigation_main_menu() {
    include get_template_directory() . '/inc/header/nav-main-menu.php';
}
add_action('extrachill_navigation_main_menu', 'extrachill_default_navigation_main_menu', 10);

/**
 * Default navigation bottom menu handler
 * Includes hardcoded bottom navigation links (About, Contact, Merch Store)
 *
 * @since 69.57
 */
function extrachill_default_navigation_bottom_menu() {
    include get_template_directory() . '/inc/header/nav-bottom-menu.php';
}
add_action('extrachill_navigation_bottom_menu', 'extrachill_default_navigation_bottom_menu', 10);

// Homepage Section Actions
/**
 * Default homepage hero section handler
 * Includes hero template for homepage layout
 *
 * @since 69.57
 */
function extrachill_default_homepage_hero() {
    include get_template_directory() . '/inc/home/templates/hero.php';
}
add_action( 'extrachill_homepage_hero', 'extrachill_default_homepage_hero', 10 );

/**
 * Default homepage top content section handler
 * Includes 3x3 grid template for featured content
 *
 * @since 69.57
 */
function extrachill_default_homepage_content_top() {
    include get_template_directory() . '/inc/home/templates/section-3x3-grid.php';
}
add_action( 'extrachill_homepage_content_top', 'extrachill_default_homepage_content_top', 10 );

/**
 * Default homepage middle content section handler
 * Includes recent posts template for additional content
 *
 * @since 69.57
 */
function extrachill_default_homepage_content_middle() {
    include get_template_directory() . '/inc/home/templates/section-more-recent-posts.php';
}
add_action( 'extrachill_homepage_content_middle', 'extrachill_default_homepage_content_middle', 10 );

/**
 * Default homepage bottom content section handler
 * Includes ExtraChill link section template
 *
 * @since 69.57
 */
function extrachill_default_homepage_content_bottom() {
    include get_template_directory() . '/inc/home/templates/section-extrachill-link.php';
}
add_action( 'extrachill_homepage_content_bottom', 'extrachill_default_homepage_content_bottom', 10 );

/**
 * Default homepage final left section handler
 * Includes about section template for homepage footer area
 *
 * @since 69.57
 */
function extrachill_default_final_left() {
    include get_template_directory() . '/inc/home/templates/section-about.php';
}
add_action( 'extrachill_home_final_left', 'extrachill_default_final_left', 10 );

// Post Content Actions
/**
 * Hook taxonomy badges above post title
 * Displays artist, venue, festival taxonomy badges for current post
 *
 * @since 69.57
 */
function extrachill_hook_taxonomy_badges_above_title() {
    extrachill_display_taxonomy_badges( get_the_ID() );
}
add_action( 'extrachill_above_post_title', 'extrachill_hook_taxonomy_badges_above_title' );

/**
 * Hook comments section for posts
 * Includes enhanced comments system with community integration
 *
 * @since 69.57
 */
function extrachill_hook_comments_section() {
    require_once get_template_directory() . '/inc/single/comments.php';
}
add_action( 'extrachill_comments_section', 'extrachill_hook_comments_section' );