<?php
/**
 * Single Product tabs using Shared-Tabs Component
 *
 * This template overrides WooCommerce's default tabs to use the theme's
 * shared-tabs component for visual consistency across the Extra Chill platform.
 *
 * @see https://woocommerce.com/document/template-structure/
 * @package ExtraChillShop
 * @version 9.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Filter tabs and allow third parties to add their own.
 *
 * Each tab is an array containing title, callback and priority.
 *
 * @see woocommerce_default_product_tabs()
 */
$product_tabs = apply_filters( 'woocommerce_product_tabs', array() );

if ( ! empty( $product_tabs ) ) :
	// Enqueue shared-tabs assets (registered by theme)
	wp_enqueue_style( 'extrachill-shared-tabs' );
	wp_enqueue_script( 'extrachill-shared-tabs' );
	$is_first = true;
	?>

	<div class="shared-tabs-component">
		<div class="shared-tabs-buttons-container">
			<?php foreach ( $product_tabs as $key => $product_tab ) : ?>
				<div class="shared-tab-item">
					<button
						type="button"
						class="shared-tab-button<?php echo $is_first ? ' active' : ''; ?>"
						data-tab="tab-<?php echo esc_attr( $key ); ?>"
					>
						<?php echo wp_kses_post( apply_filters( 'woocommerce_product_' . $key . '_tab_title', $product_tab['title'], $key ) ); ?>
						<span class="shared-tab-arrow<?php echo $is_first ? ' open' : ''; ?>"></span>
					</button>
					<div
						class="shared-tab-pane"
						id="tab-<?php echo esc_attr( $key ); ?>"
						<?php echo $is_first ? ' style="display:block;"' : ''; ?>
					>
						<?php
						if ( isset( $product_tab['callback'] ) ) {
							call_user_func( $product_tab['callback'], $key, $product_tab );
						}
						?>
					</div>
				</div>
				<?php $is_first = false; ?>
			<?php endforeach; ?>
		</div>
		<div class="shared-desktop-tab-content-area" style="display: none;"></div>
	</div>

	<?php do_action( 'woocommerce_product_after_tabs' ); ?>

<?php endif; ?>
