<?php
/**
 * Cart Icon
 *
 * Hooks into extrachill_header_top_right at priority 25.
 *
 * @package ExtraChillShop
 */

defined( 'ABSPATH' ) || exit;

add_action('extrachill_header_top_right', 'extrachill_shop_display_cart_icon', 25);

function extrachill_shop_display_cart_icon() {
    $cart_url = function_exists('wc_get_cart_url') ? wc_get_cart_url() : home_url('/cart');

    // Calculate SVG version for cache busting
    $svg_file = get_template_directory() . '/assets/fonts/fontawesome.svg';
    $svg_version = file_exists($svg_file) ? filemtime($svg_file) : '';

    ?>
    <div class="cart-icon header-right-icon">
        <a href="<?php echo esc_url($cart_url); ?>" class="cart-link" title="View Cart">
            <svg class="cart-top">
                <use href="<?php echo get_template_directory_uri(); ?>/assets/fonts/fontawesome.svg<?php echo $svg_version ? '?v=' . $svg_version : ''; ?>#cart-shopping"></use>
            </svg>
        </a>
    </div>
    <?php
}