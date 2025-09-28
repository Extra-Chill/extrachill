<?php
/**
 * Template Router - Central template dispatch system with plugin override support
 *
 * Each route now supports filter hooks allowing plugins to completely override
 * template files at the routing level rather than content level.
 *
 * @package ExtraChill
 * @since 69.57
 */

// Route to appropriate template based on page type with plugin override support

if ( is_front_page() || is_home() ) {
    // Homepage - both static front page and latest posts
    $template = apply_filters( 'extrachill_template_homepage',
        get_template_directory() . '/inc/home/templates/front-page.php'
    );
    include $template;

} elseif ( is_single() ) {
    // Single posts
    $template = apply_filters( 'extrachill_template_single_post',
        get_template_directory() . '/inc/single/single-post.php'
    );
    include $template;

} elseif ( is_page() ) {
    // Pages
    $template = apply_filters( 'extrachill_template_page',
        get_template_directory() . '/inc/single/single-page.php'
    );
    include $template;

} elseif ( is_archive() || is_category() || is_tag() || is_author() || is_date() ) {
    // All archive types
    $template = apply_filters( 'extrachill_template_archive',
        get_template_directory() . '/inc/archives/archive.php'
    );
    include $template;

} elseif ( is_search() ) {
    // Search results - use archive template
    $template = apply_filters( 'extrachill_template_search',
        get_template_directory() . '/inc/archives/archive.php'
    );
    include $template;

} elseif ( is_404() ) {
    // 404 errors
    $template = apply_filters( 'extrachill_template_404',
        get_template_directory() . '/404.php'
    );
    include $template;

} else {
    // Unknown page type - fallback to 404
    $template = apply_filters( 'extrachill_template_fallback',
        get_template_directory() . '/404.php'
    );
    include $template;
}
?>
