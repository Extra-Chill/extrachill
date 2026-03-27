<?php
/**
 * Theme Footer
 *
 * @package ExtraChill
 */
?>

</main><!-- .extrachill-content -->

<?php do_action( 'extrachill_before_footer' ); ?>

<?php do_action( 'extrachill_above_footer' ); ?>

<footer id="extra-footer" >
	<?php do_action( 'extrachill_social_links' ); ?>

	<div class="footer-menus-wrapper">
		<?php do_action( 'extrachill_footer_main_content' ); ?>
	</div>

	<?php do_action( 'extrachill_footer_below_menu' ); ?>

	<div class="footer-copyright">
		<?php $main_site_url = function_exists( 'ec_get_site_url' ) ? ec_get_site_url( 'main' ) : home_url(); ?>
		&copy; <?php echo date( 'Y' ); ?> <a href="<?php echo esc_url( $main_site_url ); ?>"><?php echo esc_html( extrachill_get_site_title() ); ?></a>. All rights reserved.
	</div>

	<?php do_action( 'extrachill_below_copyright' ); ?>
</footer>

<?php wp_footer(); ?>
</body>
</html>
