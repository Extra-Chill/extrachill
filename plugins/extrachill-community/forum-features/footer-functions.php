<?php
function forum_theme_register_widget_areas() {
    for ( $i = 1; $i <= 4; $i++ ) {
        register_sidebar( array(
            'name'          => "Footer Area $i",
            'id'            => "footer-$i",
            'before_widget' => '<div class="footer-widget-area footer-area-' . $i . '">',
            'after_widget'  => '</div>',
            'before_title'  => '<h3 class="widget-title">',
            'after_title'   => '</h3>',
        ) );
    }
}
add_action( 'widgets_init', 'forum_theme_register_widget_areas' );

function forum_theme_register_menus() {
    register_nav_menus( array(
        'footer-extra' => __( 'Footer Extra Menu', 'forum-theme' ),
        'footer-1'     => __( 'Footer Menu 1', 'forum-theme' ),
        'footer-2'     => __( 'Footer Menu 2', 'forum-theme' ),
        'footer-3'     => __( 'Footer Menu 3', 'forum-theme' ),
        'footer-4'     => __( 'Footer Menu 4', 'forum-theme' ),
        'footer-5'     => __( 'Footer Menu 5', 'forum-theme' ),
    ) );
}
add_action( 'init', 'forum_theme_register_menus' );
