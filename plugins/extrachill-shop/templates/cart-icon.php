<?php
/**
 * Cart Icon Template
 *
 * Simple cart icon that hooks into extrachill_header_top_right
 *
 * @package ExtraChillShop
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

/**
 * Hook cart icon into theme header top-right area
 */
add_action('extrachill_header_top_right', 'extrachill_shop_display_cart_icon');

/**
 * Display simple cart icon linking to shop
 */
function extrachill_shop_display_cart_icon() {
    // Get shop URL - fallback to /shop if no shop page set
    $shop_url = function_exists('wc_get_page_id') ? get_permalink(wc_get_page_id('shop')) : home_url('/shop');

    ?>
    <div class="cart-icon">
        <a href="<?php echo esc_url($shop_url); ?>" class="cart-link" title="Visit Shop">
            <svg class="cart-top">
                <use href="<?php echo get_template_directory_uri(); ?>/fonts/fontawesome.svg?v=1.5#cart-shopping"></use>
            </svg>
        </a>
    </div>
    <?php
}