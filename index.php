<?php
/**
 * Template Router - Central template dispatch system
 *
 * Routes all page types to their appropriate template files in organized subdirectories.
 * Replaces complex template override system with clean, simple routing logic.
 *
 * @package ExtraChill
 * @since 69.57
 */

// Route to appropriate template based on page type
if ( is_front_page() || is_home() ) {
    // Homepage - both static front page and latest posts
    include get_template_directory() . '/inc/home/templates/front-page.php';

} elseif ( is_single() ) {
    // Single posts
    include get_template_directory() . '/inc/single/single-post.php';

} elseif ( is_page() ) {
    // Pages
    include get_template_directory() . '/inc/single/single-page.php';

} elseif ( is_archive() || is_category() || is_tag() || is_author() || is_date() ) {
    // All archive types
    include get_template_directory() . '/inc/archives/archive.php';

} elseif ( is_search() ) {
    // Search results - use archive template
    include get_template_directory() . '/inc/archives/archive.php';

} elseif ( is_404() ) {
    // 404 errors
    include get_template_directory() . '/404.php';

} else {
    // Unknown page type - fallback to 404
    include get_template_directory() . '/404.php';
}
?>
