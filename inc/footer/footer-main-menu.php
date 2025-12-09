<?php
/**
 * Footer Main Menu
 *
 * Network-centric footer navigation structure.
 * Clean HTML structure without WordPress menu system overhead.
 *
 * @package ExtraChill
 * @since 1.0.0
 */
?>

<div class="footer-menus">
    <div class="footer-menu-column">
        <ul class="footer-column-menu">
             <li class="menu-item menu-item-has-children">
                 <a href="<?php echo esc_url( ec_get_site_url( 'main' ) ); ?>">Network</a>
                 <ul class="sub-menu">
                     <li class="menu-item">
                         <a href="<?php echo esc_url( ec_get_site_url( 'main' ) ); ?>/blog">Blog</a>
                     </li>
                     <li class="menu-item">
                         <a href="<?php echo esc_url( ec_get_site_url( 'community' ) ); ?>">Community</a>
                     </li>
                     <li class="menu-item">
                         <a href="<?php echo esc_url( ec_get_site_url( 'events' ) ); ?>">Events Calendar</a>
                     </li>
                     <li class="menu-item">
                         <a href="<?php echo esc_url( ec_get_site_url( 'artist' ) ); ?>">Artist Platform</a>
                     </li>
                     <li class="menu-item">
                         <a href="<?php echo esc_url( ec_get_site_url( 'newsletter' ) ); ?>">Newsletter</a>
                     </li>
                     <li class="menu-item">
                         <a href="<?php echo esc_url( ec_get_site_url( 'shop' ) ); ?>">Shop</a>
                     </li>
                 </ul>
             </li>
        </ul>
    </div>

    <div class="footer-menu-column">
        <ul class="footer-column-menu">
            <li class="menu-item menu-item-has-children">
                <a href="<?php echo esc_url( ec_get_site_url( 'main' ) ); ?>/blog">Explore</a>
                <ul class="sub-menu">
                    <li class="menu-item">
                        <a href="<?php echo esc_url( ec_get_site_url( 'main' ) ); ?>/category/interviews/">Interviews</a>
                    </li>
                    <li class="menu-item">
                        <a href="<?php echo esc_url( ec_get_site_url( 'main' ) ); ?>/category/live-music-reviews/">Live Reviews</a>
                    </li>
                    <li class="menu-item">
                        <a href="<?php echo esc_url( ec_get_site_url( 'main' ) ); ?>/festival-wire">Festival Wire</a>
                    </li>
                    <li class="menu-item">
                        <a href="<?php echo esc_url( ec_get_site_url( 'main' ) ); ?>/category/song-meanings/">Song Meanings</a>
                    </li>
                    <li class="menu-item">
                        <a href="<?php echo esc_url( ec_get_site_url( 'main' ) ); ?>/category/music-news/">Music News</a>
                    </li>
                </ul>
            </li>
        </ul>
    </div>

    <div class="footer-menu-column">
        <ul class="footer-column-menu">
             <li class="menu-item menu-item-has-children">
                 <a href="<?php echo esc_url( ec_get_site_url( 'main' ) ); ?>/about/">About</a>
                 <ul class="sub-menu">
                      <li class="menu-item">
                          <a href="<?php echo esc_url( ec_get_site_url( 'docs' ) ); ?>">Documentation</a>
                      </li>
                     <li class="menu-item">
                         <a href="<?php echo esc_url( ec_get_site_url( 'main' ) ); ?>/contact/">Contact Us</a>
                     </li>
                     <li class="menu-item">
                         <a href="<?php echo esc_url( ec_get_site_url( 'main' ) ); ?>/about/in-the-press/">In the Press</a>
                     </li>
                     <li class="menu-item">
                         <a href="<?php echo esc_url( ec_get_site_url( 'main' ) ); ?>/contribute">Contribute</a>
                     </li>
                 </ul>
             </li>
        </ul>
    </div>
</div>

<?php
/**
 * Footer Newsletter Below Menu
 *
 * Renders the newsletter form centered below the footer menus.
 *
 * @package ExtraChill
 * @since 1.0.0
 */

add_action( 'extrachill_footer_below_menu', function() {
    ?>
    <div class="footer-newsletter-below-menu">
        <?php do_action( 'extrachill_render_newsletter_form', 'navigation' ); ?>
    </div>
    <?php
}, 10 );

