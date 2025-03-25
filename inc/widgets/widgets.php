<?php
/**
 * Contains all the functions related to sidebar and widget.
 *
 * @package    ThemeGrill
 * @subpackage ColorMag
 * @since      ColorMag 1.0
 */
add_action( 'widgets_init', 'colormag_widgets_init' );

/**
 * Function to register the widget areas(sidebar) and widgets.
 */
function colormag_widgets_init() {

	/**
	 * Registering widget areas for front page
	 */
	// Registering main right sidebar
	register_sidebar( array(
		'name'          => __( 'Right Sidebar', 'colormag-pro' ),
		'id'            => 'colormag_right_sidebar',
		'description'   => __( 'Shows widgets at Right side.', 'colormag-pro' ),
		'before_widget' => '<aside id="%1$s" class="widget %2$s clearfix">',
		'after_widget'  => '</aside>',
		'before_title'  => '<h3 class="widget-title"><span>',
		'after_title'   => '</span></h3>',
	) );


}
