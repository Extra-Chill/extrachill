<?php
/**
 * Hardcoded Footer Bottom Menu
 *
 * Bottom footer menu with legal/policy links.
 * Clean HTML structure without WordPress menu system overhead.
 *
 * @package ExtraChill
 * @since 69.57
 */
?>

<div class="footer-extra-menu">
    <div class="menu-footer-bottom-container">
        <ul class="menu">
            <li class="menu-item">
                <a href="<?php echo esc_url(get_permalink(get_page_by_path('affiliate-disclosure'))); ?>">Affiliate Disclosure</a>
            </li>
            <li class="menu-item menu-item-privacy-policy">
                <a rel="privacy-policy" href="<?php echo esc_url(get_privacy_policy_url()); ?>">Privacy Policy</a>
            </li>
            <li class="menu-item">
                <a href="<?php echo esc_url(get_permalink(get_page_by_path('shipping-and-returns'))); ?>">Shipping &amp; Returns Policy</a>
            </li>
        </ul>
    </div>
</div>