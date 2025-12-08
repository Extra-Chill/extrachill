<?php
/**
 * Product Form
 *
 * Frontend form for creating and editing products in the artist dashboard.
 * Uses REST API endpoints for image upload and product save.
 *
 * @package ExtraChillShop
 * @since 0.2.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Render the product form.
 *
 * @param int $product_id Optional. Product ID for editing.
 */
function extrachill_shop_render_product_form( $product_id = 0 ) {
	if ( ! extrachill_shop_user_is_artist() ) {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'You do not have permission to view this page.', 'extrachill-shop' ) . '</p></div>';
		return;
	}

	// Block new product creation if Stripe is not connected.
	$is_edit = $product_id > 0;
	if ( ! $is_edit && ! extrachill_shop_is_stripe_connected() ) {
		$settings_url = wc_get_account_endpoint_url( 'artist-settings' );
		echo '<div class="notice notice-info">';
		echo '<p><strong>' . esc_html__( 'Connect Stripe to List Products', 'extrachill-shop' ) . '</strong></p>';
		echo '<p>' . esc_html__( 'You need to connect your Stripe account before you can create products for sale.', 'extrachill-shop' ) . '</p>';
		echo '<p><a href="' . esc_url( $settings_url ) . '" class="button button-primary">' . esc_html__( 'Go to Settings', 'extrachill-shop' ) . '</a></p>';
		echo '</div>';
		return;
	}

	$product      = $is_edit ? wc_get_product( $product_id ) : null;
	$user_artists = extrachill_shop_get_user_artists();

	if ( $is_edit && ! $product ) {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Product not found.', 'extrachill-shop' ) . '</p></div>';
		return;
	}

	if ( $is_edit && ! extrachill_shop_user_can_manage_product( $product_id ) ) {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'You do not have permission to edit this product.', 'extrachill-shop' ) . '</p></div>';
		return;
	}

	$product_data = array(
		'name'            => $is_edit ? $product->get_name() : '',
		'description'     => $is_edit ? $product->get_description() : '',
		'short_desc'      => $is_edit ? $product->get_short_description() : '',
		'price'           => $is_edit ? $product->get_regular_price() : '',
		'sale_price'      => $is_edit ? $product->get_sale_price() : '',
		'manage_stock'    => $is_edit ? $product->get_manage_stock() : false,
		'stock_quantity'  => $is_edit ? $product->get_stock_quantity() : '',
		'artist_id'       => $is_edit ? extrachill_shop_get_product_artist_id( $product_id ) : '',
		'image_id'        => $is_edit ? $product->get_image_id() : '',
		'gallery_ids'     => $is_edit ? $product->get_gallery_image_ids() : array(),
	);

	if ( count( $user_artists ) === 1 ) {
		$product_data['artist_id'] = $user_artists[0]['ID'];
	}
	?>

	<div class="extrachill-product-form">
		<h2>
			<?php echo $is_edit ? esc_html__( 'Edit Product', 'extrachill-shop' ) : esc_html__( 'Add New Product', 'extrachill-shop' ); ?>
		</h2>

		<form id="extrachill-product-form" method="post" enctype="multipart/form-data">
			<input type="hidden" name="product_id" id="product_id" value="<?php echo esc_attr( $product_id ); ?>">

			<?php if ( count( $user_artists ) > 1 ) : ?>
				<div class="form-row">
					<label for="artist_id"><?php esc_html_e( 'Artist', 'extrachill-shop' ); ?> <span class="required">*</span></label>
					<select name="artist_id" id="artist_id" required>
						<option value=""><?php esc_html_e( 'Select an artist...', 'extrachill-shop' ); ?></option>
						<?php foreach ( $user_artists as $artist ) : ?>
							<option value="<?php echo esc_attr( $artist['ID'] ); ?>" <?php selected( $product_data['artist_id'], $artist['ID'] ); ?>>
								<?php echo esc_html( $artist['post_title'] ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
			<?php else : ?>
				<input type="hidden" name="artist_id" id="artist_id" value="<?php echo esc_attr( $product_data['artist_id'] ); ?>">
			<?php endif; ?>

			<div class="form-row">
				<label for="product_name"><?php esc_html_e( 'Product Name', 'extrachill-shop' ); ?> <span class="required">*</span></label>
				<input type="text" name="product_name" id="product_name" value="<?php echo esc_attr( $product_data['name'] ); ?>" required>
			</div>

			<div class="form-row">
				<label for="product_description"><?php esc_html_e( 'Description', 'extrachill-shop' ); ?></label>
				<textarea name="product_description" id="product_description" rows="5"><?php echo esc_textarea( $product_data['description'] ); ?></textarea>
			</div>

			<div class="form-row">
				<label for="product_short_desc"><?php esc_html_e( 'Short Description', 'extrachill-shop' ); ?></label>
				<textarea name="product_short_desc" id="product_short_desc" rows="2"><?php echo esc_textarea( $product_data['short_desc'] ); ?></textarea>
			</div>

			<div class="form-row form-row-half">
				<label for="product_price"><?php esc_html_e( 'Price ($)', 'extrachill-shop' ); ?> <span class="required">*</span></label>
				<input type="number" name="product_price" id="product_price" value="<?php echo esc_attr( $product_data['price'] ); ?>" step="0.01" min="0" required>
			</div>

			<div class="form-row form-row-half">
				<label for="product_sale_price"><?php esc_html_e( 'Sale Price ($)', 'extrachill-shop' ); ?></label>
				<input type="number" name="product_sale_price" id="product_sale_price" value="<?php echo esc_attr( $product_data['sale_price'] ); ?>" step="0.01" min="0">
			</div>

			<div class="form-row">
				<label>
					<input type="checkbox" name="manage_stock" id="manage_stock" value="1" <?php checked( $product_data['manage_stock'] ); ?>>
					<?php esc_html_e( 'Track inventory', 'extrachill-shop' ); ?>
				</label>
			</div>

			<div class="form-row stock-quantity-row" style="<?php echo $product_data['manage_stock'] ? '' : 'display:none;'; ?>">
				<label for="stock_quantity"><?php esc_html_e( 'Stock Quantity', 'extrachill-shop' ); ?></label>
				<input type="number" name="stock_quantity" id="stock_quantity" value="<?php echo esc_attr( $product_data['stock_quantity'] ); ?>" min="0">
			</div>

			<div class="form-row">
				<label><?php esc_html_e( 'Product Image', 'extrachill-shop' ); ?> <span class="required">*</span></label>
				<div class="product-image-upload">
					<input type="hidden" name="product_image_id" id="product_image_id" value="<?php echo esc_attr( $product_data['image_id'] ); ?>">
					<div id="product-image-preview">
						<?php if ( $product_data['image_id'] ) : ?>
							<?php echo wp_get_attachment_image( $product_data['image_id'], 'thumbnail' ); ?>
						<?php endif; ?>
					</div>
					<input type="file" name="product_image" id="product_image" accept="image/jpeg,image/png,image/webp">
					<p class="description"><?php esc_html_e( 'Minimum 800x800px. JPEG, PNG, or WebP.', 'extrachill-shop' ); ?></p>
				</div>
			</div>

			<div class="form-row">
				<label><?php esc_html_e( 'Gallery Images', 'extrachill-shop' ); ?></label>
				<div class="gallery-images-upload">
					<input type="hidden" name="gallery_image_ids" id="gallery_image_ids" value="<?php echo esc_attr( implode( ',', $product_data['gallery_ids'] ) ); ?>">
					<div id="gallery-images-preview">
						<?php foreach ( $product_data['gallery_ids'] as $gallery_id ) : ?>
							<div class="gallery-image" data-id="<?php echo esc_attr( $gallery_id ); ?>">
								<?php echo wp_get_attachment_image( $gallery_id, 'thumbnail' ); ?>
								<button type="button" class="remove-gallery-image">&times;</button>
							</div>
						<?php endforeach; ?>
					</div>
					<input type="file" name="gallery_images[]" id="gallery_images" accept="image/jpeg,image/png,image/webp" multiple>
					<p class="description"><?php esc_html_e( 'Up to 4 additional images.', 'extrachill-shop' ); ?></p>
				</div>
			</div>

			<div class="form-row form-actions">
				<button type="submit" class="button button-primary">
					<?php echo $is_edit ? esc_html__( 'Update Product', 'extrachill-shop' ) : esc_html__( 'Create Product', 'extrachill-shop' ); ?>
				</button>
				<a href="<?php echo esc_url( wc_get_account_endpoint_url( 'artist-products' ) ); ?>" class="button">
					<?php esc_html_e( 'Cancel', 'extrachill-shop' ); ?>
				</a>
			</div>

			<?php if ( ! $is_edit && extrachill_shop_requires_product_approval() ) : ?>
				<p class="notice notice-info">
					<?php esc_html_e( 'Your product will be reviewed before it goes live.', 'extrachill-shop' ); ?>
				</p>
			<?php endif; ?>
		</form>
	</div>

	<?php
	extrachill_shop_product_form_inline_scripts( $product_id );
}

/**
 * Output inline scripts for product form using REST API.
 *
 * @param int $product_id Product ID (0 for new products).
 */
function extrachill_shop_product_form_inline_scripts( $product_id ) {
	$api_base    = esc_url( rest_url( 'extrachill/v1' ) );
	$redirect_to = esc_url( wc_get_account_endpoint_url( 'artist-products' ) );
	?>
	<script>
	(function() {
		var API_BASE = '<?php echo $api_base; ?>';
		var REDIRECT_URL = '<?php echo $redirect_to; ?>';
		var PRODUCT_ID = <?php echo (int) $product_id; ?>;

		var manageStock = document.getElementById('manage_stock');
		var stockRow = document.querySelector('.stock-quantity-row');

		if (manageStock && stockRow) {
			manageStock.addEventListener('change', function() {
				stockRow.style.display = this.checked ? '' : 'none';
			});
		}

		function uploadImage(file) {
			var formData = new FormData();
			formData.append('file', file);
			formData.append('context', 'content_embed');

			return fetch(API_BASE + '/media', {
				method: 'POST',
				credentials: 'same-origin',
				body: formData
			}).then(function(res) {
				if (!res.ok) {
					return res.json().then(function(err) {
						throw new Error(err.message || 'Upload failed');
					});
				}
				return res.json();
			});
		}

		function uploadImages(files) {
			var promises = [];
			for (var i = 0; i < files.length; i++) {
				promises.push(uploadImage(files[i]));
			}
			return Promise.all(promises);
		}

		var form = document.getElementById('extrachill-product-form');
		if (form) {
			form.addEventListener('submit', function(e) {
				e.preventDefault();

				var submitBtn = form.querySelector('button[type="submit"]');
				submitBtn.disabled = true;
				submitBtn.textContent = '<?php echo esc_js( __( 'Saving...', 'extrachill-shop' ) ); ?>';

				var mainImageInput = document.getElementById('product_image');
				var galleryInput = document.getElementById('gallery_images');
				var existingImageId = parseInt(document.getElementById('product_image_id').value, 10) || 0;
				var existingGalleryIds = document.getElementById('gallery_image_ids').value
					.split(',')
					.filter(function(id) { return id; })
					.map(function(id) { return parseInt(id, 10); });

				var uploadPromises = [];
				var newMainImageId = null;
				var newGalleryIds = [];

				if (mainImageInput.files.length > 0) {
					uploadPromises.push(
						uploadImage(mainImageInput.files[0]).then(function(result) {
							newMainImageId = result.attachment_id;
						})
					);
				}

				if (galleryInput.files.length > 0) {
					uploadPromises.push(
						uploadImages(galleryInput.files).then(function(results) {
							results.forEach(function(result) {
								newGalleryIds.push(result.attachment_id);
							});
						})
					);
				}

				Promise.all(uploadPromises)
					.then(function() {
						var imageId = newMainImageId || existingImageId;
						var galleryIds = existingGalleryIds.concat(newGalleryIds).slice(0, 4);

						var productData = {
							artist_id: parseInt(document.getElementById('artist_id').value, 10),
							name: document.getElementById('product_name').value,
							description: document.getElementById('product_description').value,
							short_description: document.getElementById('product_short_desc').value,
							price: parseFloat(document.getElementById('product_price').value) || 0,
							sale_price: parseFloat(document.getElementById('product_sale_price').value) || 0,
							manage_stock: document.getElementById('manage_stock').checked,
							stock_quantity: parseInt(document.getElementById('stock_quantity').value, 10) || 0,
							image_id: imageId,
							gallery_ids: galleryIds
						};

						var url = API_BASE + '/shop/products';
						var method = 'POST';

						if (PRODUCT_ID > 0) {
							url = API_BASE + '/shop/products/' + PRODUCT_ID;
							method = 'PUT';
						}

						return fetch(url, {
							method: method,
							credentials: 'same-origin',
							headers: {
								'Content-Type': 'application/json'
							},
							body: JSON.stringify(productData)
						});
					})
					.then(function(res) {
						if (!res.ok) {
							return res.json().then(function(err) {
								throw new Error(err.message || '<?php echo esc_js( __( 'Failed to save product.', 'extrachill-shop' ) ); ?>');
							});
						}
						return res.json();
					})
					.then(function() {
						window.location.href = REDIRECT_URL;
					})
					.catch(function(err) {
						alert(err.message || '<?php echo esc_js( __( 'An error occurred.', 'extrachill-shop' ) ); ?>');
						submitBtn.disabled = false;
						submitBtn.textContent = '<?php echo esc_js( __( 'Save Product', 'extrachill-shop' ) ); ?>';
					});
			});
		}

		document.querySelectorAll('.remove-gallery-image').forEach(function(btn) {
			btn.addEventListener('click', function() {
				var container = this.closest('.gallery-image');
				var id = container.dataset.id;
				container.remove();

				var hiddenInput = document.getElementById('gallery_image_ids');
				var ids = hiddenInput.value.split(',').filter(function(i) { return i && i !== id; });
				hiddenInput.value = ids.join(',');
			});
		});
	})();
	</script>
	<?php
}
