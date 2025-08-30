<?php
/**
 * footer.php
 *
 * 
 *
 */
?>

      </div><!-- .container -->
    </div><!-- .site-content -->
<?php do_action( 'extrachill_before_footer' );

if (function_exists('display_online_users_stats')) {
    display_online_users_stats();
}
?>
<footer id="extra-footer" >
    <!-- Social Media Links -->
    <?php include get_stylesheet_directory() . '/forum-features/content/social-links.php'; ?>
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
    <!-- Copyright -->
    <div class="footer-copyright">
        &copy; <?php echo date( 'Y' ); ?> <a href="https://extrachill.com">Extra Chill</a>. All rights reserved.
    </div>
    <?php if ( has_nav_menu( 'footer-extra' ) ) : ?>
        <div class="footer-extra-menu">
            <?php wp_nav_menu( array( 'theme_location' => 'footer-extra' ) ); ?>
        </div>
    <?php endif; ?>
</footer>



  </div><!-- .site -->
<?php wp_footer(); ?>
</body>
</html>
