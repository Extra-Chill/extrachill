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
        <?php do_action('extrachill_footer_main_content'); ?>
    </div>

    <?php do_action('extrachill_footer_below_menu'); ?>

    <div class="footer-copyright">
        &copy; <?php echo date( 'Y' ); ?> <a href="https://extrachill.com">Extra Chill</a>. All rights reserved.
    </div>

    <?php do_action('extrachill_below_copyright'); ?>
</footer>

<?php wp_footer(); ?>
</body>
</html>
