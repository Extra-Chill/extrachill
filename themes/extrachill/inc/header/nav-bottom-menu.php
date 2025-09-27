<?php
/**
 * Hardcoded Bottom Navigation Menu
 *
 * Bottom navigation links for the flyout menu system.
 * Simple footer-style links without WordPress menu system overhead.
 *
 * @package ExtraChill
 * @since 69.57
 */
?>

<li class="menu-footer-links">
    <a href="<?php echo esc_url(get_permalink(get_page_by_path('about'))); ?>">About</a>
    <a href="<?php echo esc_url(get_permalink(get_page_by_path('contact'))); ?>">Contact</a>
</li>