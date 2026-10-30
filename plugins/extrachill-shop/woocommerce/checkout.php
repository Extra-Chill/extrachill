<?php
/**
 * Checkout Page Template
 *
 * Simple wrapper for WooCommerce checkout page with breadcrumbs and proper structure.
 * The [woocommerce_checkout] shortcode renders the actual checkout content.
 *
 * @package ExtraChillShop
 */

defined( 'ABSPATH' ) || exit;

get_header();

// Breadcrumbs
extrachill_breadcrumbs();
?>

<div class="woocommerce">
	<?php
	while ( have_posts() ) :
		the_post();
		the_content();
	endwhile;
	?>
</div>

<?php
get_footer();
