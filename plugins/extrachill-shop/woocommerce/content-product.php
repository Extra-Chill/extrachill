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
		$artist_id = extrachill_shop_get_product_artist_id( get_the_ID() );
		$artist    = $artist_id ? extrachill_shop_get_canonical_artist( $artist_id ) : false;
		if ( $artist && ! is_wp_error( $artist ) ) :
			?>
		<div class="taxonomy-badges">
			<a href="<?php echo esc_url( extrachill_shop_get_artist_store_url( $artist_id ) ); ?>"
			class="taxonomy-badge artist-badge artist-<?php echo esc_attr( $artist['slug'] ); ?>">
			<?php echo esc_html( $artist['name'] ); ?>
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
