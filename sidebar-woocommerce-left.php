<?php
/**
 * The WooCommerce left sidebar widget area.
 *
 * @package ThemeGrill
 * @subpackage ColorMag
 * @since ColorMag 2.2.8
 */
?>

<div id="secondary"<?php echo colormag_schema_markup( 'sidebar' ); ?>>
	<?php do_action( 'colormag_before_sidebar' ); ?>

		<?php if ( ! dynamic_sidebar( 'colormag_woocommerce_left_sidebar' ) ) :
			the_widget( 'WP_Widget_Text',
				array(
					'title'  => __( 'Example Widget', 'colormag-pro' ),
					'text'   => sprintf( __( 'This is an example widget to show how the WooCommerce Left Sidebar looks by default. You can add custom widgets from the %swidgets screen%s in the admin. If custom widgets is added than this will be replaced by those widgets.', 'colormag-pro' ), current_user_can( 'edit_theme_options' ) ? '<a href="' . admin_url( 'widgets.php' ) . '">' : '', current_user_can( 'edit_theme_options' ) ? '</a>' : '' ),
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
