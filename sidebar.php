<?php
/**
 * The Sidebar containing the main widget areas.
 *
 * @package ThemeGrill
 * @subpackage ColorMag
 * @since ColorMag 1.0
 */
?>

<aside id="secondary">
	<?php do_action( 'extrachill_before_sidebar' ); ?>
		<?php
			if( is_page_template( 'page-templates/contact.php' ) ) {
				$sidebar = 'colormag_contact_page_sidebar';
			}
			else {
				$sidebar = 'colormag_right_sidebar';
			}
		?>

		<?php if ( ! dynamic_sidebar( $sidebar ) ) :
			if ( $sidebar == 'colormag_contact_page_sidebar' ) {
				$sidebar_display = __('Contact Page', 'colormag-pro');
			} else {
				$sidebar_display = __('Right', 'colormag-pro');
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
			?>
			<aside class="widget widget_recent_posts clearfix">
				<?php echo my_recent_posts_shortcode(); ?>
			</aside>
		<?php endif; ?>

	<?php do_action( 'extrachill_after_sidebar' ); ?>
</aside>
