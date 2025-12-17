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

extrachill_breadcrumbs();
extrachill_filter_bar();

// Get filter params
$current_artist = isset( $_GET['artist'] ) ? sanitize_text_field( wp_unslash( $_GET['artist'] ) ) : '';
$current_sort   = isset( $_GET['sort'] ) ? sanitize_key( $_GET['sort'] ) : 'recent';
$current_search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
$paged          = get_query_var( 'paged' ) ? get_query_var( 'paged' ) : 1;

// Build query args
$args = array(
	'post_type'      => 'product',
	'posts_per_page' => 12,
	'paged'          => $paged,
	'post_status'    => 'publish',
);

// Search parameter
if ( $current_search ) {
	$args['s'] = $current_search;
}

// Artist filter
if ( $current_artist ) {
	$args['tax_query'] = array(
		array(
			'taxonomy' => 'artist',
			'field'    => 'slug',
			'terms'    => $current_artist,
		),
	);
}

// Sort handling
switch ( $current_sort ) {
	case 'oldest':
		$args['orderby'] = 'date';
		$args['order']   = 'ASC';
		break;
	case 'price-asc':
		$args['meta_key'] = '_price';
		$args['orderby']  = 'meta_value_num';
		$args['order']    = 'ASC';
		break;
	case 'price-desc':
		$args['meta_key'] = '_price';
		$args['orderby']  = 'meta_value_num';
		$args['order']    = 'DESC';
		break;
	case 'random':
		$args['orderby'] = 'rand';
		break;
	case 'popular':
		$args['meta_key'] = 'ec_post_views';
		$args['orderby']  = 'meta_value_num';
		$args['order']    = 'DESC';
		break;
	case 'recent':
	default:
		$args['orderby'] = 'date';
		$args['order']   = 'DESC';
		break;
}

$products = new WP_Query( $args );

if ( $products->have_posts() ) :
	?>
	<div class="woocommerce" id="shop-catalog-container">
		<ul class="products columns-3" id="shop-products-grid">
			<?php
			while ( $products->have_posts() ) :
				$products->the_post();
				wc_get_template_part( 'content', 'product' );
			endwhile;
			?>
		</ul>

		<?php
		// Build pagination with filter params preserved
		$pagination_args = array(
			'total'     => $products->max_num_pages,
			'current'   => $paged,
			'mid_size'  => 2,
			'prev_text' => __( '&larr; Previous', 'extrachill-shop' ),
			'next_text' => __( 'Next &rarr;', 'extrachill-shop' ),
			'add_args'  => array(),
		);

		// Preserve filter params in pagination links
		if ( $current_artist ) {
			$pagination_args['add_args']['artist'] = $current_artist;
		}
		if ( $current_sort && 'recent' !== $current_sort ) {
			$pagination_args['add_args']['sort'] = $current_sort;
		}
		if ( $current_search ) {
			$pagination_args['add_args']['s'] = $current_search;
		}

		$pagination_links = paginate_links( $pagination_args );

		if ( $pagination_links ) :
			?>
			<nav class="pagination-links" id="shop-pagination">
				<?php echo $pagination_links; ?>
			</nav>
			<?php
		endif;
		?>
	</div>

	<?php
	wp_reset_postdata();
else :
	?>
	<div class="woocommerce" id="shop-catalog-container">
		<p class="woocommerce-info"><?php esc_html_e( 'No products found.', 'extrachill-shop' ); ?></p>
	</div>
	<?php
endif;
