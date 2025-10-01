<?php
/**
 * Hardcoded Footer Menu
 *
 * Clean, maintainable footer menu structure without WordPress menu system overhead.
 * Direct HTML control for optimal performance and easy maintenance.
 *
 * @package ExtraChill
 * @since 69.57
 */
?>

<div class="footer-menus">
    <div class="footer-menu-column">
        <ul class="footer-column-menu">
            <li class="menu-item menu-item-has-children">
                <a href="<?php echo esc_url(home_url('/all')); ?>">The Latest</a>
                <ul class="sub-menu">
                    <li class="menu-item">
                        <a href="<?php echo esc_url(home_url('/festival-wire')); ?>">Festival Wire</a>
                    </li>
                    <li class="menu-item">
                        <a href="https://events.extrachill.com">Calendar</a>
                    </li>
                    <li class="menu-item">
                        <a href="<?php echo esc_url(get_category_link(get_cat_ID('interviews'))); ?>">Interviews</a>
                    </li>
                    <li class="menu-item">
                        <a href="<?php echo esc_url(get_category_link(get_cat_ID('live-music-reviews'))); ?>">Live Music Reviews</a>
                    </li>
                    <li class="menu-item">
                        <a href="<?php echo esc_url(home_url('/newsletters')); ?>">Newsletters</a>
                    </li>
                </ul>
            </li>
        </ul>
    </div>

    <div class="footer-menu-column">
        <ul class="footer-column-menu">
            <li class="menu-item menu-item-has-children">
                <a href="#">The Rabbit Hole</a>
                <ul class="sub-menu">
                    <li class="menu-item">
                        <a href="<?php echo esc_url(get_category_link(get_cat_ID('song-meanings'))); ?>">Song Meanings</a>
                    </li>
                    <li class="menu-item">
                        <a href="<?php echo esc_url(home_url('/artist/grateful-dead')); ?>">Grateful Dead</a>
                    </li>
                    <li class="menu-item">
                        <a href="<?php echo esc_url(get_category_link(get_cat_ID('musical-curiosities'))); ?>">Musical Curiosities</a>
                    </li>
                    <li class="menu-item">
                        <a href="<?php echo esc_url(get_category_link(get_cat_ID('famous-guitars'))); ?>">Famous Guitars</a>
                    </li>
                </ul>
            </li>
        </ul>
    </div>

    <div class="footer-menu-column">
        <ul class="footer-column-menu">
            <li class="menu-item menu-item-has-children">
                <a href="https://community.extrachill.com/">Community</a>
                <ul class="sub-menu">
                    <li class="menu-item">
                        <a href="https://community.extrachill.com/recent">Recent Activity</a>
                    </li>
                    <li class="menu-item">
                        <a href="https://community.extrachill.com/r/local-scenes">Local Scenes</a>
                    </li>
                    <li class="menu-item">
                        <a href="https://community.extrachill.com/r/music-discovery">Music Discussion</a>
                    </li>
                    <li class="menu-item">
                        <a href="https://community.extrachill.com/r/music-festivals">Music Festivals</a>
                    </li>
                    <li class="menu-item">
                        <a href="https://community.extrachill.com/r/music-industry">Music Industry</a>
                    </li>
                </ul>
            </li>
        </ul>
    </div>

    <div class="footer-menu-column">
        <ul class="footer-column-menu">
            <li class="menu-item menu-item-has-children">
                <a href="<?php echo esc_url(get_permalink(get_page_by_path('about'))); ?>">About</a>
                <ul class="sub-menu">
                    <li class="menu-item">
                        <a href="<?php echo esc_url(get_permalink(get_page_by_path('contact-us'))); ?>">Contact Us</a>
                    </li>
                    <li class="menu-item">
                        <a href="<?php echo esc_url(get_permalink(get_page_by_path('about/the-history-of-extra-chill'))); ?>">History of Extra Chill</a>
                    </li>
                    <li class="menu-item">
                        <a href="<?php echo esc_url(get_permalink(get_page_by_path('festival'))); ?>">Extra Chill Fest</a>
                    </li>
                    <li class="menu-item">
                        <a href="<?php echo esc_url(get_category_link(get_cat_ID('extra-chill-presents'))); ?>">Extra Chill Presents</a>
                    </li>
                    <li class="menu-item">
                        <a href="<?php echo esc_url(get_permalink(get_page_by_path('about/in-the-press'))); ?>">In the Press</a>
                    </li>
                </ul>
            </li>
        </ul>
    </div>
</div>