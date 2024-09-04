<?php

/**
 * Register the widget area before the main content.
 */
function extrachill_register_top_widget_area() {
    register_sidebar( array(
        'name'          => __( 'Before Main', 'colormag' ),
        'id'            => 'before-main',
        'description'   => __( 'Widgets added here will appear before the main content.', 'colormag' ),
        'before_widget' => '<div id="%1$s" class="widget %2$s">',
        'after_widget'  => '</div>',
        'before_title'  => '<h2 class="widget-title">',
        'after_title'   => '</h2>',
    ) );
}
add_action( 'widgets_init', 'extrachill_register_top_widget_area' );

/**
 * Display the widget area before the main content.
 */
function extrachill_display_before_main_widget_area() {
    if ( is_active_sidebar( 'before-main' ) ) {
        echo '<div id="before-main-widget-area" class="before-main-widget-area">';
        dynamic_sidebar( 'before-main' );
        echo '</div>';
    }
}
add_action( 'colormag_before_main', 'extrachill_display_before_main_widget_area' );
