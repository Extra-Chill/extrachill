<?php
get_header();

// Allow plugins to completely override homepage content
$custom_homepage_content = apply_filters( 'extrachill_homepage_content', false );

if ( $custom_homepage_content !== false ) {
    // Plugin provided custom content - use it instead
    echo $custom_homepage_content;
} else {
    // Default homepage behavior

    /**
     * Homepage Content Query & Processing
     *
     * Load homepage queries from dedicated file for better organization
     */
    require_once get_template_directory() . '/inc/home/homepage-queries.php';

    // Festival Wire ticker handled by ExtraChill News Wire plugin via extrachill_homepage_after_hero hook

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
