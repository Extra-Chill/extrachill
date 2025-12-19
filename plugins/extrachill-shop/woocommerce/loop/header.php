<?php
/**
 * Product Loop Header
 *
 * Adds a shared archive header actions slot beside the title.
 *
 * @package ExtraChillShop
 * @version 8.6.0
 */

defined( 'ABSPATH' ) || exit;

?>
<header class="woocommerce-products-header">
	<div class="archive-header-row">
		<?php if ( apply_filters( 'woocommerce_show_page_title', true ) ) : ?>
			<h1 class="woocommerce-products-header__title page-title"><?php woocommerce_page_title(); ?></h1>
		<?php endif; ?>

		<div class="archive-header-actions">
			<?php do_action( 'extrachill_archive_header_actions' ); ?>
		</div>
	</div>

	<?php
	/**
	 * Hook: woocommerce_archive_description.
	 */
	do_action( 'woocommerce_archive_description' );
	?>
</header>
