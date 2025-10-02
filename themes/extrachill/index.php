<?php
/**
 * Template Router - Central template dispatch system with plugin override support
 *
 * @package ExtraChill
 * @since 69.57
 */

if ( is_front_page() || is_home() ) {
    $template = apply_filters( 'extrachill_template_homepage',
        get_template_directory() . '/inc/home/templates/front-page.php'
    );
    include $template;

} elseif ( is_single() ) {
    $template = apply_filters( 'extrachill_template_single_post',
        get_template_directory() . '/inc/single/single-post.php'
    );
    include $template;

} elseif ( is_page() ) {
    $template = apply_filters( 'extrachill_template_page',
        get_template_directory() . '/inc/single/single-page.php'
    );
    include $template;

} elseif ( is_archive() || is_category() || is_tag() || is_author() || is_date() ) {
    $template = apply_filters( 'extrachill_template_archive',
        get_template_directory() . '/inc/archives/archive.php'
    );
    include $template;

} elseif ( is_search() ) {
    $template = apply_filters( 'extrachill_template_search',
        get_template_directory() . '/inc/archives/search/search.php'
    );
    include $template;

} elseif ( is_404() ) {
    $template = apply_filters( 'extrachill_template_404',
        get_template_directory() . '/inc/core/templates/404.php'
    );
    include $template;

} else {
    $template = apply_filters( 'extrachill_template_fallback',
        get_template_directory() . '/inc/core/templates/404.php'
    );
    include $template;
}
?>
