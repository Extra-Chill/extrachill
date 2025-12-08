<?php
/**
 * Artist Products List
 *
 * Frontend template for listing and managing artist's products in the
 * WooCommerce My Account area. Uses REST API for delete operations.
 *
 * @package ExtraChillShop
 * @since 0.2.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Render the artist products list.
 */
function extrachill_shop_render_products_list() {
	if ( ! extrachill_shop_user_is_artist() ) {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'You do not have permission to view this page.', 'extrachill-shop' ) . '</p></div>';
		return;
	}

	$products       = extrachill_shop_get_user_artist_products();
	$user_artists   = extrachill_shop_get_user_artists();
	$stripe_status  = extrachill_shop_get_stripe_account_status();
	$stripe_ready   = $stripe_status['can_receive_payments'];
	$account_status = $stripe_status['status'];
	$settings_url   = wc_get_account_endpoint_url( 'artist-settings' );
	?>

	<div class="extrachill-artist-products">
		<?php if ( ! $stripe_ready ) : ?>
			<div class="notice notice-info">
				<p>
					<strong><?php esc_html_e( 'Stripe Account Required', 'extrachill-shop' ); ?></strong><br>
					<?php if ( 'pending' === $account_status || 'restricted' === $account_status ) : ?>
						<?php esc_html_e( 'Your Stripe account setup is incomplete. Please finish onboarding to receive payments for your products.', 'extrachill-shop' ); ?>
					<?php else : ?>
						<?php esc_html_e( 'Connect your Stripe account to receive payments when customers purchase your products.', 'extrachill-shop' ); ?>
					<?php endif; ?>
					<a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Go to Settings', 'extrachill-shop' ); ?></a>
				</p>
			</div>
		<?php endif; ?>

		<div class="extrachill-artist-products__header">
			<h2><?php esc_html_e( 'My Products', 'extrachill-shop' ); ?></h2>
			<a href="<?php echo esc_url( wc_get_account_endpoint_url( 'artist-products' ) . '?action=new' ); ?>" class="button">
				<?php esc_html_e( 'Add New Product', 'extrachill-shop' ); ?>
			</a>
		</div>

		<?php if ( empty( $products ) ) : ?>
			<div class="notice notice-info">
				<p><?php esc_html_e( 'You haven\'t added any products yet.', 'extrachill-shop' ); ?></p>
			</div>
		<?php else : ?>
			<table class="extrachill-artist-products__table shop_table">
				<thead>
					<tr>
						<th class="product-thumbnail"><?php esc_html_e( 'Image', 'extrachill-shop' ); ?></th>
						<th class="product-name"><?php esc_html_e( 'Product', 'extrachill-shop' ); ?></th>
						<th class="product-price"><?php esc_html_e( 'Price', 'extrachill-shop' ); ?></th>
						<th class="product-status"><?php esc_html_e( 'Status', 'extrachill-shop' ); ?></th>
						<th class="product-actions"><?php esc_html_e( 'Actions', 'extrachill-shop' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $products as $product_post ) : ?>
						<?php
						$product   = wc_get_product( $product_post->ID );
						$edit_url  = wc_get_account_endpoint_url( 'artist-products' ) . '?action=edit&product_id=' . $product_post->ID;
						$store_url = get_permalink( $product_post->ID );
						?>
						<tr>
							<td class="product-thumbnail">
								<?php
								$thumbnail = $product->get_image( 'thumbnail' );
								echo wp_kses_post( $thumbnail );
								?>
							</td>
							<td class="product-name">
								<a href="<?php echo esc_url( $store_url ); ?>">
									<?php echo esc_html( $product->get_name() ); ?>
								</a>
								<?php if ( count( $user_artists ) > 1 ) : ?>
									<?php
									$artist_id   = extrachill_shop_get_product_artist_id( $product_post->ID );
									$artist_data = $artist_id ? extrachill_shop_get_artist_profile_by_slug_via_id( $artist_id ) : null;
									if ( $artist_data ) :
										?>
										<span class="product-artist">
											<?php echo esc_html( $artist_data['name'] ); ?>
										</span>
									<?php endif; ?>
								<?php endif; ?>
							</td>
							<td class="product-price">
								<?php echo wp_kses_post( $product->get_price_html() ); ?>
							</td>
							<td class="product-status">
								<?php
								$status       = $product_post->post_status;
								$status_label = extrachill_shop_get_product_status_label( $status );
								$status_class = 'status-' . esc_attr( $status );
								?>
								<span class="product-status-badge <?php echo esc_attr( $status_class ); ?>">
									<?php echo esc_html( $status_label ); ?>
								</span>
							</td>
							<td class="product-actions">
								<a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small">
									<?php esc_html_e( 'Edit', 'extrachill-shop' ); ?>
								</a>
								<button type="button" class="button button-small button-delete" data-product-id="<?php echo esc_attr( $product_post->ID ); ?>">
									<?php esc_html_e( 'Delete', 'extrachill-shop' ); ?>
								</button>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>

	<?php
	extrachill_shop_products_list_inline_scripts();
}

/**
 * Get human-readable product status label.
 *
 * @param string $status Post status.
 * @return string Status label.
 */
function extrachill_shop_get_product_status_label( $status ) {
	$statuses = array(
		'publish' => __( 'Published', 'extrachill-shop' ),
		'pending' => __( 'Pending Review', 'extrachill-shop' ),
		'draft'   => __( 'Draft', 'extrachill-shop' ),
		'private' => __( 'Private', 'extrachill-shop' ),
		'trash'   => __( 'Trashed', 'extrachill-shop' ),
	);

	return isset( $statuses[ $status ] ) ? $statuses[ $status ] : $status;
}

/**
 * Output inline scripts for products list using REST API.
 */
function extrachill_shop_products_list_inline_scripts() {
	$api_base = esc_url( rest_url( 'extrachill/v1' ) );
	?>
	<script>
	(function() {
		var API_BASE = '<?php echo $api_base; ?>';

		document.querySelectorAll('.button-delete').forEach(function(btn) {
			btn.addEventListener('click', function(e) {
				e.preventDefault();
				if (!confirm('<?php echo esc_js( __( 'Are you sure you want to delete this product?', 'extrachill-shop' ) ); ?>')) {
					return;
				}

				var productId = this.dataset.productId;
				var row = this.closest('tr');
				var button = this;

				button.disabled = true;
				button.textContent = '<?php echo esc_js( __( 'Deleting...', 'extrachill-shop' ) ); ?>';

				fetch(API_BASE + '/shop/products/' + productId, {
					method: 'DELETE',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/json'
					}
				})
				.then(function(res) {
					if (!res.ok) {
						return res.json().then(function(err) {
							throw new Error(err.message || '<?php echo esc_js( __( 'Failed to delete product.', 'extrachill-shop' ) ); ?>');
						});
					}
					return res.json();
				})
				.then(function(data) {
					if (data.deleted) {
						row.remove();
						var tbody = document.querySelector('.extrachill-artist-products__table tbody');
						if (tbody && tbody.children.length === 0) {
							location.reload();
						}
					}
				})
				.catch(function(err) {
					alert(err.message || '<?php echo esc_js( __( 'An error occurred.', 'extrachill-shop' ) ); ?>');
					button.disabled = false;
					button.textContent = '<?php echo esc_js( __( 'Delete', 'extrachill-shop' ) ); ?>';
				});
			});
		});
	})();
	</script>
	<?php
}
