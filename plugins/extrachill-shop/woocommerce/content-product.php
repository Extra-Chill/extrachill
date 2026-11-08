<?php
/**
 * Product Card Template
 *
 * Custom product card for Extra Chill shop with canonical artist badges.
 *
 * @package ExtraChillShop
 * @version 3.6.0
 */

defined( 'ABSPATH' ) || exit;

global $product;

if ( empty( $product ) || ! $product->is_visible() ) {
	return;
}
?>
<li <?php wc_product_class( '', $product ); ?>>
	<div class="product-card-image">
		<a href="<?php echo esc_url( get_permalink() ); ?>">
		<?php echo $product->get_image( 'woocommerce_thumbnail' ); ?>
		</a>
	</div>

	<div class="product-card-content">
		<?php
		$product_seller = extrachill_shop_get_product_seller( get_the_ID() );
		$public_seller  = $product_seller ? extrachill_shop_get_public_seller( $product_seller ) : null;
		$badge_class    = $public_seller ? $public_seller['type'] . '-badge' : '';
		if ( $public_seller && 'artist' === $public_seller['type'] ) {
			$badge_artist = extrachill_shop_get_canonical_artist( $public_seller['id'] );
			$badge_class .= is_wp_error( $badge_artist ) || empty( $badge_artist['slug'] ) ? '' : ' artist-' . $badge_artist['slug'];
		}
		if ( $public_seller ) :
			?>
		<div class="taxonomy-badges">
			<a href="<?php echo esc_url( extrachill_shop_get_seller_store_url( $product_seller ) ); ?>"
			class="taxonomy-badge <?php echo esc_attr( $badge_class ); ?>">
			<?php echo esc_html( $public_seller['name'] ); ?>
			</a>
		</div>
		<?php endif; ?>

		<h2 class="woocommerce-loop-product__title">
			<a href="<?php echo esc_url( get_permalink() ); ?>">
				<?php echo get_the_title(); ?>
			</a>
		</h2>

		<?php woocommerce_template_loop_rating(); ?>

		<?php if ( $price_html = $product->get_price_html() ) : ?>
		<span class="price"><?php echo $price_html; ?></span>
		<?php endif; ?>

		<?php woocommerce_template_loop_add_to_cart(); ?>
	</div>
</li>
