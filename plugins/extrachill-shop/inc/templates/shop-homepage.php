<?php
/**
 * Shop Homepage Content
 *
 * Homepage content for shop.extrachill.com.
 * Hooked via extrachill_homepage_content action.
 *
 * @package ExtraChillShop
 */

defined( 'ABSPATH' ) || exit;

// Breadcrumbs
extrachill_breadcrumbs();

// Query products
$paged = get_query_var( 'paged' ) ? get_query_var( 'paged' ) : 1;

$args = array(
	'post_type'      => 'product',
	'posts_per_page' => 12,
	'paged'          => $paged,
	'post_status'    => 'publish',
);

$products = new WP_Query( $args );

if ( $products->have_posts() ) :
	?>
	<div class="woocommerce">
		<ul class="products columns-3">
			<?php
			while ( $products->have_posts() ) :
				$products->the_post();

				/**
				 * Uses WooCommerce's content-product.php template
				 * Located in: woocommerce/content-product.php
				 */
				wc_get_template_part( 'content', 'product' );
			endwhile;
			?>
		</ul>
	</div>

	<?php
	// Pagination
	the_posts_pagination(
		array(
			'mid_size'  => 2,
			'prev_text' => __( '&larr; Previous', 'extrachill-shop' ),
			'next_text' => __( 'Next &rarr;', 'extrachill-shop' ),
		)
	);

	wp_reset_postdata();
else :
	?>
	<div class="woocommerce">
		<p class="woocommerce-info"><?php esc_html_e( 'No products found.', 'extrachill-shop' ); ?></p>
	</div>
	<?php
endif;
