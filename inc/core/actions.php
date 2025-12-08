<?php
/**
 * Centralized Action Hook Registration
 *
 * Default handlers for extensible template hooks throughout theme.
 * Plugins can override by hooking at different priorities or replacing handlers.
 *
 * @package ExtraChill
 * @since 1.0.0
 */

function extrachill_default_footer_main_content() {
    include get_template_directory() . '/inc/footer/footer-main-menu.php';
}
add_action('extrachill_footer_main_content', 'extrachill_default_footer_main_content', 10);

function extrachill_default_below_copyright() {
    include get_template_directory() . '/inc/footer/footer-bottom-menu.php';
}
add_action('extrachill_below_copyright', 'extrachill_default_below_copyright', 10);

function extrachill_hook_taxonomy_badges_above_title() {

    extrachill_display_taxonomy_badges( get_the_ID() );
}
add_action( 'extrachill_above_post_title', 'extrachill_hook_taxonomy_badges_above_title' );

function extrachill_hook_comments_section() {
    require_once get_template_directory() . '/inc/single/comments.php';
}
add_action( 'extrachill_comments_section', 'extrachill_hook_comments_section' );

function extrachill_default_archive_header() {
    include get_template_directory() . '/inc/archives/archive-header.php';
}
add_action( 'extrachill_archive_header', 'extrachill_default_archive_header', 10 );

function extrachill_default_search_header() {
    include get_template_directory() . '/inc/archives/search/search-header.php';
}
add_action( 'extrachill_search_header', 'extrachill_default_search_header', 10 );

function extrachill_render_secondary_header() {
    include get_template_directory() . '/inc/header/secondary-header.php';
}
add_action( 'extrachill_after_header', 'extrachill_render_secondary_header', 5 );