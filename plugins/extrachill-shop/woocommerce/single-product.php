<?php
/**
 * Single Product Template
 *
 * Uses Extra Chill theme architecture with custom product layout.
 *
 * @package ExtraChillShop
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>

<div class="main-content">
	<?php
	while ( have_posts() ) :
		the_post();
		wc_get_template_part( 'content', 'single-product' );
	endwhile;
	?>
</div>

<?php
get_footer();
