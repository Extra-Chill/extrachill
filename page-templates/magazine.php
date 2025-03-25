<?php
/**
 * Template Name: Magazine Template
 *
 * Displays the Content in widgetized magazine layout like front page
 *
 * @package    ThemeGrill
 * @subpackage ColorMag
 * @since      ColorMag 1.0
 */

get_header(); ?>

<div class="front-page-top-section clearfix">
	<div class="widget_slider_area">
		<?php
		if ( is_active_sidebar( 'colormag_front_page_slider_area' ) ) {
			if ( ! dynamic_sidebar( 'colormag_front_page_slider_area' ) ):
			endif;
		}
		?>
	</div>

	<div class="widget_beside_slider">
		<?php
		if ( is_active_sidebar( 'colormag_front_page_area_beside_slider' ) ) {
			if ( ! dynamic_sidebar( 'colormag_front_page_area_beside_slider' ) ):
			endif;
		}
		?>
	</div>
</div>
<div class="main-content-section clearfix">
	<section id="primary">
		<div id="content" class="clearfix">

			<?php
			if ( is_active_sidebar( 'colormag_front_page_content_top_section' ) ) {
				if ( ! dynamic_sidebar( 'colormag_front_page_content_top_section' ) ):
				endif;
			}

			if ( is_active_sidebar( 'colormag_front_page_content_middle_left_section' ) || is_active_sidebar( 'colormag_front_page_content_middle_right_section' ) ) {
				?>
				<div class="tg-one-half">
					<?php
					if ( ! dynamic_sidebar( 'colormag_front_page_content_middle_left_section' ) ):
					endif;
					?>
				</div>
				<div class="tg-one-half tg-one-half-last">
					<?php
					if ( ! dynamic_sidebar( 'colormag_front_page_content_middle_right_section' ) ):
					endif;
					?>
				</div>
				<div class="clearfix"></div>
				<?php
			}
			if ( is_active_sidebar( 'colormag_front_page_content_bottom_section' ) ) {
				if ( ! dynamic_sidebar( 'colormag_front_page_content_bottom_section' ) ):
				endif;
			}
			?>
		</div>
	</div>
	<?php get_sidebar(); ?>
</div>

<?php get_footer(); ?>
