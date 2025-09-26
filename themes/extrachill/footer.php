<?php
/**
 * Theme Footer
 *
 * @package ExtraChill
 */
?>

</main><!-- #main -->

<?php do_action( 'extrachill_before_footer' ); ?>

<?php do_action( 'extrachill_above_footer' ); ?>

<footer id="extra-footer" >
    <?php include get_template_directory() . '/social-links.php'; ?>

    <div class="footer-menus-wrapper">
        <div class="footer-menus">
            <?php
            for ( $i = 1; $i <= 5; $i++ ) {
                $menu_location = 'footer-' . $i;
                if ( has_nav_menu( $menu_location ) ) {
                    wp_nav_menu(
                        array(
                            'theme_location'  => $menu_location,
                            'container'       => 'div',
                            'container_class' => 'footer-menu-column',
                            'menu_class'      => 'footer-column-menu',
                        )
                    );
                }
            }
            ?>
        </div>
    </div>

    <div class="footer-copyright">
        &copy; <?php echo date( 'Y' ); ?> <a href="https://extrachill.com">Extra Chill</a>. All rights reserved.
    </div>

    <?php if ( has_nav_menu( 'footer-extra' ) ) : ?>
        <div class="footer-extra-menu">
            <?php wp_nav_menu( array( 'theme_location' => 'footer-extra' ) ); ?>
        </div>
    <?php endif; ?>
</footer>



</div><!-- #page -->
<?php wp_footer(); ?>
</body>
<!-- Google Tag Manager (noscript) -->
<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-NXKDLFD"
height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
<!-- End Google Tag Manager (noscript) -->
</html>
