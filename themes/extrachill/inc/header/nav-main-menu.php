<?php
/**
 * Hardcoded Main Navigation Menu
 *
 * Primary navigation items for the flyout menu system.
 *
 * @package ExtraChill
 * @since 69.57
 */
?>

<li class="menu-community-link">
    <a href="https://community.extrachill.com">Visit Forum</a>
</li>
<li class="menu-item">
    <a href="https://events.extrachill.com">Live Music Calendar</a>
</li>
<li class="menu-item">
    <a href="<?php echo esc_url(home_url('/festival-wire')); ?>">Festival Wire</a>
</li>
<li class="menu-item menu-item-has-children">
    <a href="#">Latest Blog Content <svg class="submenu-indicator"><use href="<?php echo get_template_directory_uri(); ?>/assets/fonts/extrachill.svg?v=1.5#angle-down-solid"></use></svg></a>
    <ul class="sub-menu">
        <li class="menu-item">
            <a href="<?php echo esc_url(get_category_link(get_cat_ID('live-music-reviews'))); ?>">Live Music Reviews</a>
        </li>
        <li class="menu-item">
            <a href="<?php echo esc_url(get_category_link(get_cat_ID('music-news'))); ?>">Music News</a>
        </li>
        <li class="menu-item">
            <a href="<?php echo esc_url(get_category_link(get_cat_ID('interviews'))); ?>">Interviews</a>
        </li>
        <li class="menu-item">
            <a href="<?php echo esc_url(get_category_link(get_cat_ID('song-meanings'))); ?>">Song Meanings</a>
        </li>
        <li class="menu-item">
            <a href="<?php echo esc_url(home_url('/artist/grateful-dead')); ?>">Grateful Dead</a>
        </li>
    </ul>
</li>