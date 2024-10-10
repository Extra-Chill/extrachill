<?php
/**
 * The left sidebar widget area.
 *
 * @package ThemeGrill
 * @subpackage ColorMag
 * @since ColorMag 1.0
 */
?>

<div id="secondary"<?php echo colormag_schema_markup( 'sidebar' ); ?>>
	<?php do_action( 'colormag_before_sidebar' ); ?>
		<?php
			if( is_page_template( 'page-templates/contact.php' ) ) {
				$sidebar = 'colormag_contact_page_sidebar';
			}
			else {
				$sidebar = 'colormag_left_sidebar';
			}
		?>

		<?php if ( ! dynamic_sidebar( $sidebar ) ) :
			if ( $sidebar == 'colormag_contact_page_sidebar' ) {
				$sidebar_display = __('Contact Page', 'colormag-pro');
			} else {
				$sidebar_display = __('Left', 'colormag-pro');
			}
			the_widget( 'WP_Widget_Text',
				array(
					'title'  => __( 'Example Widget', 'colormag-pro' ),
					'text'   => sprintf( __( 'This is an example widget to show how the %s Sidebar looks by default. You can add custom widgets from the %swidgets screen%s in the admin. If custom widgets is added than this will be replaced by those widgets.', 'colormag-pro' ), $sidebar_display, current_user_can( 'edit_theme_options' ) ? '<a href="' . admin_url( 'widgets.php' ) . '">' : '', current_user_can( 'edit_theme_options' ) ? '</a>' : '' ),
					'filter' => true,
				),
				array(
					'before_widget' => '<aside class="widget widget_text clearfix">',
					'after_widget'  => '</aside>',
					'before_title'  => '<h3 class="widget-title"><span>',
					'after_title'   => '</span></h3>'
				)
			);
		endif; ?>

	<?php do_action( 'colormag_after_sidebar' ); ?>
</div>
