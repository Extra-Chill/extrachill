<?php
/**
 * Single Product Content Template
 *
 * Custom layout using Extra Chill theme patterns with direct template output.
 *
 * @package ExtraChillShop
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

global $product;

if ( post_password_required() ) {
	echo get_the_password_form();
	return;
}

$product_id        = $product->get_id();
$gallery_image_ids = $product->get_gallery_image_ids();
$main_image_id     = $product->get_image_id();
$has_images        = $main_image_id || ! empty( $gallery_image_ids );
?>

<?php do_action( 'woocommerce_before_single_product' ); ?>

<?php
// Breadcrumbs
if ( function_exists( 'extrachill_breadcrumbs' ) ) {
	extrachill_breadcrumbs();
}
?>

<article id="product-<?php echo esc_attr( $product_id ); ?>" <?php wc_product_class( 'product', $product ); ?>>

	<?php if ( $product->is_on_sale() ) : ?>
		<span class="onsale"><?php esc_html_e( 'Sale!', 'extrachill-shop' ); ?></span>
	<?php endif; ?>

	<!-- Product Gallery -->
	<div class="product-gallery">
		<?php if ( $has_images ) : ?>
			<div class="product-gallery__main-image">
				<?php
				$main_image_url = $main_image_id
					? wp_get_attachment_image_url( $main_image_id, 'large' )
					: wc_placeholder_img_src( 'large' );
				$main_image_alt = $main_image_id
					? get_post_meta( $main_image_id, '_wp_attachment_image_alt', true )
					: $product->get_name();
				$full_image_url = $main_image_id
					? wp_get_attachment_image_url( $main_image_id, 'full' )
					: '';
				?>
				<img
					id="product-main-image"
					src="<?php echo esc_url( $main_image_url ); ?>"
					alt="<?php echo esc_attr( $main_image_alt ); ?>"
					data-full-src="<?php echo esc_url( $full_image_url ); ?>"
				/>
			</div>

			<?php if ( $main_image_id || ! empty( $gallery_image_ids ) ) : ?>
				<div class="product-gallery__thumbnails">
					<?php
					$all_image_ids = array();
					if ( $main_image_id ) {
						$all_image_ids[] = $main_image_id;
					}
					$all_image_ids = array_merge( $all_image_ids, $gallery_image_ids );

					foreach ( $all_image_ids as $index => $image_id ) :
						$thumb_url = wp_get_attachment_image_url( $image_id, 'thumbnail' );
						$large_url = wp_get_attachment_image_url( $image_id, 'large' );
						$full_url  = wp_get_attachment_image_url( $image_id, 'full' );
						$alt       = get_post_meta( $image_id, '_wp_attachment_image_alt', true );
						$active    = 0 === $index ? ' active' : '';
						?>
						<div
							class="product-gallery__thumbnail<?php echo esc_attr( $active ); ?>"
							data-large-src="<?php echo esc_url( $large_url ); ?>"
							data-full-src="<?php echo esc_url( $full_url ); ?>"
						>
							<img src="<?php echo esc_url( $thumb_url ); ?>" alt="<?php echo esc_attr( $alt ); ?>" />
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

		<?php else : ?>
			<div class="product-gallery__main-image product-placeholder-image"></div>
		<?php endif; ?>
	</div>

	<!-- Product Summary -->
	<div class="product-summary">
		<h1 class="product_title"><?php the_title(); ?></h1>

		<p class="price"><?php echo $product->get_price_html(); ?></p>

		<?php woocommerce_template_single_rating(); ?>

		<?php if ( $product->get_short_description() ) : ?>
			<div class="woocommerce-product-details__short-description">
				<?php echo wp_kses_post( wpautop( $product->get_short_description() ) ); ?>
			</div>
		<?php endif; ?>

		<?php
		// Stock status
		if ( $product->is_in_stock() ) {
			$stock_html = '<p class="stock in-stock">' . esc_html__( 'In stock', 'extrachill-shop' ) . '</p>';
		} else {
			$stock_html = '<p class="stock out-of-stock">' . esc_html__( 'Out of stock', 'extrachill-shop' ) . '</p>';
		}
		echo $stock_html;
		?>

		<?php
		/**
		 * Add to cart form
		 *
		 * Uses WooCommerce's template for proper handling of simple/variable/grouped products.
		 */
		woocommerce_template_single_add_to_cart();
		?>

		<?php
		// Product meta (SKU, categories, tags)
		$sku        = $product->get_sku();
		$categories = wc_get_product_category_list( $product_id, ', ' );
		$tags       = wc_get_product_tag_list( $product_id, ', ' );

		if ( $sku || $categories || $tags ) :
			?>
			<div class="product_meta">
				<?php if ( $sku ) : ?>
					<span class="sku_wrapper">
						<?php esc_html_e( 'SKU:', 'extrachill-shop' ); ?>
						<span class="sku"><?php echo esc_html( $sku ); ?></span>
					</span>
				<?php endif; ?>

				<?php if ( $categories ) : ?>
					<span class="posted_in">
						<?php esc_html_e( 'Category:', 'extrachill-shop' ); ?>
						<?php echo wp_kses_post( $categories ); ?>
					</span>
				<?php endif; ?>

				<?php if ( $tags ) : ?>
					<span class="tagged_as">
						<?php esc_html_e( 'Tags:', 'extrachill-shop' ); ?>
						<?php echo wp_kses_post( $tags ); ?>
					</span>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</div>

	<!-- Product Tabs (Description, Reviews) -->
	<?php woocommerce_output_product_data_tabs(); ?>

	<!-- Related Products -->
	<?php woocommerce_output_related_products(); ?>

</article>

<?php do_action( 'woocommerce_after_single_product' ); ?>
