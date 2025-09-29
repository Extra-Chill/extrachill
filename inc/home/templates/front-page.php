<?php
/**
 * Homepage Template
 *
 * Hook-based homepage with plugin override capability and modular content sections.
 * Loaded via universal template routing system in index.php.
 *
 * @package ExtraChill
 * @since 69.57
 */

get_header();

$custom_homepage_content = apply_filters( 'extrachill_homepage_content', false );

if ( $custom_homepage_content !== false ) {
    echo $custom_homepage_content;
} else {

    require_once get_template_directory() . '/inc/home/homepage-queries.php';


    ?>
    <div id="mediavine-settings" data-blocklist-all="1"></div>
    <?php do_action( 'extrachill_homepage_hero' ); ?>
    <?php do_action( 'extrachill_homepage_after_hero' ); ?>
    <?php do_action( 'extrachill_homepage_content_top' ); ?>
    <?php do_action( 'extrachill_homepage_content_middle' ); ?>
    <?php do_action( 'extrachill_homepage_content_bottom' ); ?>
    <?php do_action( 'extrachill_home_final_left' ); ?>
    <?php do_action( 'extrachill_home_final_right' ); ?>
    <?php
}

get_footer();
?>
