<?php
/**
 * Cart Icon
 *
 * Hooks into extrachill_header_top_right at priority 25.
 *
 * @package ExtraChillShop
 */

defined( 'ABSPATH' ) || exit;

add_action( 'extrachill_header_top_right', 'extrachill_shop_display_cart_icon', 25 );

function extrachill_shop_display_cart_icon() {
	$cart_url = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/cart' );
	?>
	<div class="cart-icon header-right-icon">
		<a href="<?php echo esc_url( $cart_url ); ?>" class="cart-link" title="View Cart">
			<?php echo ec_icon( 'cart', 'cart-top' ); ?>
		</a>
	</div>
	<?php
}