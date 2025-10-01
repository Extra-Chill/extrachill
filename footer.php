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
    <?php do_action( 'extrachill_social_links' ); ?>

    <div class="footer-menus-wrapper">
        <?php do_action('extrachill_footer_main_content'); ?>
    </div>

    <div class="footer-copyright">
        &copy; <?php echo date( 'Y' ); ?> <a href="https://extrachill.com">Extra Chill</a>. All rights reserved.
    </div>

    <?php do_action('extrachill_below_copyright'); ?>
</footer>

<?php wp_footer(); ?>
</body>
<!-- Google Tag Manager (noscript) -->
<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-NXKDLFD"
height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
<!-- End Google Tag Manager (noscript) -->
</html>
