<?php
/**
 * Product Card Template
 *
 * Custom product card for Extra Chill shop with artist taxonomy badges.
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
			<?php if ( $product->get_image_id() ) : ?>
				<?php echo $product->get_image( 'woocommerce_thumbnail' ); ?>
			<?php else : ?>
				<div class="product-placeholder-image"></div>
			<?php endif; ?>
		</a>
	</div>

	<div class="product-card-content">
		<?php
		$artist_terms = get_the_terms( get_the_ID(), 'artist' );
		if ( $artist_terms && ! is_wp_error( $artist_terms ) ) :
			?>
		<div class="taxonomy-badges">
			<?php foreach ( $artist_terms as $term ) : ?>
				<a href="<?php echo esc_url( get_term_link( $term ) ); ?>"
					class="taxonomy-badge artist-badge artist-<?php echo esc_attr( $term->slug ); ?>">
					<?php echo esc_html( $term->name ); ?>
				</a>
			<?php endforeach; ?>
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
