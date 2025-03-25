<?php
/**
 * footer.php
 *
 * 
 *
 */
?>

</main><!-- #main -->

<?php do_action( 'extrachill_before_footer' ); ?>

<footer id="extra-footer" >
    <!-- Social Media Links -->
    <?php include get_template_directory() . '/social-links.php'; ?>
    <!-- Widget Areas -->
    <div class="footer-widget-areas">
        <?php for ( $i = 1; $i <= 5; $i++ ) : ?>
            <?php if ( is_active_sidebar( 'footer-' . $i ) ) {
                dynamic_sidebar( 'footer-' . $i );
            } ?>
            <?php if ( has_nav_menu( 'footer-' . $i ) ) {
                wp_nav_menu( array( 'theme_location' => 'footer-' . $i ) );
            } ?>
        <?php endfor; ?>
    </div>
    <!-- Copyright -->
    <div class="footer-copyright">
        &copy; <?php echo date( 'Y' ); ?> <a href="https://extrachill.com">Extra Chill</a>. All rights reserved.
    </div>
    <!-- New Footer Menu Location -->
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
