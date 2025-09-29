<?php
/**
 * ExtraChill Theme Custom Action Hooks
 *
 * Centralized registration of theme template action hooks.
 *
 * @package ExtraChill
 * @since 69.57
 */

function extrachill_default_footer_main_content() {
    include get_template_directory() . '/inc/footer/footer-main-menu.php';
}
add_action('extrachill_footer_main_content', 'extrachill_default_footer_main_content', 10);

function extrachill_default_below_copyright() {
    include get_template_directory() . '/inc/footer/footer-bottom-menu.php';
}
add_action('extrachill_below_copyright', 'extrachill_default_below_copyright', 10);

function extrachill_default_navigation_main_menu() {
    include get_template_directory() . '/inc/header/nav-main-menu.php';
}
add_action('extrachill_navigation_main_menu', 'extrachill_default_navigation_main_menu', 10);

function extrachill_default_navigation_bottom_menu() {
    include get_template_directory() . '/inc/header/nav-bottom-menu.php';
}
add_action('extrachill_navigation_bottom_menu', 'extrachill_default_navigation_bottom_menu', 10);

function extrachill_default_homepage_hero() {
    include get_template_directory() . '/inc/home/templates/hero.php';
}
add_action( 'extrachill_homepage_hero', 'extrachill_default_homepage_hero', 10 );

function extrachill_default_homepage_content_top() {
    include get_template_directory() . '/inc/home/templates/section-3x3-grid.php';
}
add_action( 'extrachill_homepage_content_top', 'extrachill_default_homepage_content_top', 10 );

function extrachill_default_homepage_content_middle() {
    include get_template_directory() . '/inc/home/templates/section-more-recent-posts.php';
}
add_action( 'extrachill_homepage_content_middle', 'extrachill_default_homepage_content_middle', 10 );

function extrachill_default_homepage_content_bottom() {
    include get_template_directory() . '/inc/home/templates/section-extrachill-link.php';
}
add_action( 'extrachill_homepage_content_bottom', 'extrachill_default_homepage_content_bottom', 10 );

function extrachill_default_final_left() {
    include get_template_directory() . '/inc/home/templates/section-about.php';
}
add_action( 'extrachill_home_final_left', 'extrachill_default_final_left', 10 );

function extrachill_hook_taxonomy_badges_above_title() {
    extrachill_display_taxonomy_badges( get_the_ID() );
}
add_action( 'extrachill_above_post_title', 'extrachill_hook_taxonomy_badges_above_title' );

function extrachill_hook_comments_section() {
    require_once get_template_directory() . '/inc/single/comments.php';
}
add_action( 'extrachill_comments_section', 'extrachill_hook_comments_section' );