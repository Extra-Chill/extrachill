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
                <a href="https://extrachill.com">Network</a>
                <ul class="sub-menu">
                    <li class="menu-item">
                        <a href="https://extrachill.com/blog">Blog</a>
                    </li>
                    <li class="menu-item">
                        <a href="https://community.extrachill.com">Community</a>
                    </li>
                     <li class="menu-item">
                         <a href="https://events.extrachill.com">Events Calendar</a>
                     </li>
                    <li class="menu-item">
                        <a href="https://artist.extrachill.com">Artist Platform</a>
                    </li>
                    <li class="menu-item">
                        <a href="https://newsletter.extrachill.com">Newsletter</a>
                    </li>
                    <li class="menu-item">
                        <a href="https://shop.extrachill.com">Shop</a>
                    </li>
                </ul>
            </li>
        </ul>
    </div>

    <div class="footer-menu-column">
        <ul class="footer-column-menu">
            <li class="menu-item menu-item-has-children">
                <a href="https://extrachill.com/blog">Explore</a>
                <ul class="sub-menu">
                    <li class="menu-item">
                        <a href="https://extrachill.com/category/interviews/">Interviews</a>
                    </li>
                    <li class="menu-item">
                        <a href="https://extrachill.com/category/live-music-reviews/">Live Reviews</a>
                    </li>
                    <li class="menu-item">
                        <a href="https://extrachill.com/festival-wire">Festival Wire</a>
                    </li>
                    <li class="menu-item">
                        <a href="https://extrachill.com/category/song-meanings/">Song Meanings</a>
                    </li>
                    <li class="menu-item">
                        <a href="https://extrachill.com/category/music-news/">Music News</a>
                    </li>
                </ul>
            </li>
        </ul>
    </div>

    <div class="footer-menu-column footer-menu-newsletter">
        <h3><?php esc_html_e( 'Subscribe', 'extrachill' ); ?></h3>
        <?php do_action( 'extrachill_render_newsletter_form', 'navigation' ); ?>
    </div>
</div>
