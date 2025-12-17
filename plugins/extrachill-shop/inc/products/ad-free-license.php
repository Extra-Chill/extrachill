<?php
/**
 * Ad-Free License WooCommerce Integration
 *
 * Adds username field and checkout validation for the ad-free license product.
 * Grants the license on order completion by delegating to ec_create_ad_free_license().
 *
 * @package ExtraChillShop
 * @since 0.1.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'woocommerce_before_add_to_cart_button', 'extrachill_shop_add_community_username_field' );
add_filter( 'woocommerce_add_to_cart_validation', 'extrachill_shop_validate_ad_free_add_to_cart', 10, 3 );
add_filter( 'woocommerce_add_cart_item_data', 'extrachill_shop_save_username_to_cart', 10, 3 );

add_action( 'woocommerce_cart_updated', 'extrachill_shop_save_username_cart_on_cart' );
add_action( 'woocommerce_check_cart_items', 'extrachill_shop_validate_username_cart' );
add_filter( 'woocommerce_get_item_data', 'extrachill_shop_display_username_cart', 10, 2 );
add_action( 'woocommerce_cart_item_name', 'extrachill_shop_cart_username_input', 20, 3 );

add_action( 'woocommerce_checkout_create_order_line_item', 'extrachill_shop_add_username_to_order_item', 10, 4 );
add_action( 'woocommerce_payment_complete', 'extrachill_shop_auto_complete_ad_free_order', 20 );
add_action( 'woocommerce_order_status_completed', 'extrachill_shop_handle_ad_free_purchase', 10 );

function extrachill_shop_is_ad_free_license_product_id( $product_id ) {
	$ad_free_product_id = function_exists( 'extrachill_shop_get_ad_free_license_product_id' )
		? absint( extrachill_shop_get_ad_free_license_product_id() )
		: 0;

	return $ad_free_product_id && absint( $product_id ) === $ad_free_product_id;
}

function extrachill_shop_get_default_community_username() {
	if ( ! is_user_logged_in() ) {
		return '';
	}

	$user = wp_get_current_user();
	return $user ? (string) $user->user_login : '';
}

function extrachill_shop_get_posted_community_username() {
	if ( empty( $_POST['community_username'] ) ) {
		return '';
	}

	return (string) sanitize_text_field( wp_unslash( $_POST['community_username'] ) );
}

function extrachill_shop_add_community_username_field() {
	global $product;
	if ( ! $product ) {
		return;
	}

	if ( ! extrachill_shop_is_ad_free_license_product_id( $product->get_id() ) ) {
		return;
	}

	$username = extrachill_shop_get_default_community_username();
	?>
	<div class="community-username-field">
		<label for="community_username">Community Username <abbr>*</abbr></label>
		<input type="text" name="community_username" id="community_username" value="<?php echo esc_attr( $username ); ?>" required placeholder="Community Username">
		<p class="description">Enter the community username that should receive the ad-free license.</p>
	</div>
	<?php
}

function extrachill_shop_validate_ad_free_add_to_cart( $passed, $product_id, $quantity ) {
	if ( ! extrachill_shop_is_ad_free_license_product_id( $product_id ) ) {
		return $passed;
	}

	$username = extrachill_shop_get_posted_community_username();
	if ( empty( $username ) ) {
		wc_add_notice( 'Please enter a valid Community Username for the Ad-Free License.', 'error' );
		return false;
	}

	$user = get_user_by( 'login', $username );
	if ( ! $user ) {
		wc_add_notice( 'That Community Username does not exist.', 'error' );
		return false;
	}

	return $passed;
}

function extrachill_shop_save_username_to_cart( $cart_item_data, $product_id, $variation_id ) {
	if ( ! extrachill_shop_is_ad_free_license_product_id( $product_id ) ) {
		return $cart_item_data;
	}

	$username = extrachill_shop_get_posted_community_username();
	if ( empty( $username ) ) {
		$username = extrachill_shop_get_default_community_username();
	}

	if ( ! empty( $username ) ) {
		$cart_item_data['community_username'] = $username;
	}

	return $cart_item_data;
}

function extrachill_shop_add_username_to_order_item( $item, $cart_item_key, $values, $order ) {
	if ( empty( $values['community_username'] ) ) {
		return;
	}

	$item->add_meta_data( 'community_username', sanitize_text_field( $values['community_username'] ), true );
}

function extrachill_shop_display_username_cart( $item_data, $cart_item ) {
	if ( empty( $cart_item['product_id'] ) || ! extrachill_shop_is_ad_free_license_product_id( $cart_item['product_id'] ) ) {
		return $item_data;
	}

	if ( empty( $cart_item['community_username'] ) ) {
		return $item_data;
	}

	$item_data[] = array(
		'key'   => 'Community Username',
		'value' => esc_html( $cart_item['community_username'] ),
	);

	return $item_data;
}

function extrachill_shop_cart_username_input( $name, $cart_item, $cart_item_key ) {
	if ( empty( $cart_item['product_id'] ) || ! extrachill_shop_is_ad_free_license_product_id( $cart_item['product_id'] ) ) {
		return $name;
	}

	$value = isset( $cart_item['community_username'] ) ? (string) $cart_item['community_username'] : '';
	if ( empty( $value ) ) {
		$value = extrachill_shop_get_default_community_username();
	}

	$name .= sprintf(
		'<p><label>Community Username:<br><input type="text" name="community_username[%s]" value="%s" required></label></p>',
		esc_attr( $cart_item_key ),
		esc_attr( $value )
	);

	return $name;
}

function extrachill_shop_save_username_cart_on_cart() {
	if ( empty( $_POST['community_username'] ) || ! is_array( $_POST['community_username'] ) ) {
		return;
	}

	$posted = wp_unslash( $_POST['community_username'] );

	foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
		if ( empty( $cart_item['product_id'] ) || ! extrachill_shop_is_ad_free_license_product_id( $cart_item['product_id'] ) ) {
			continue;
		}

		if ( ! isset( $posted[ $cart_item_key ] ) ) {
			continue;
		}

		WC()->cart->cart_contents[ $cart_item_key ]['community_username'] = sanitize_text_field( $posted[ $cart_item_key ] );
	}
}

function extrachill_shop_validate_username_cart() {
	foreach ( WC()->cart->get_cart() as $cart_item ) {
		if ( empty( $cart_item['product_id'] ) || ! extrachill_shop_is_ad_free_license_product_id( $cart_item['product_id'] ) ) {
			continue;
		}

		$username = isset( $cart_item['community_username'] ) ? (string) $cart_item['community_username'] : '';
		if ( empty( $username ) ) {
			wc_add_notice( 'Please enter a valid Community Username for the Ad-Free License.', 'error' );
			continue;
		}

		$user = get_user_by( 'login', $username );
		if ( ! $user ) {
			wc_add_notice( 'That Community Username does not exist.', 'error' );
		}
	}
}

function extrachill_shop_auto_complete_ad_free_order( $order_id ) {
	if ( ! $order_id ) {
		return;
	}

	$order = wc_get_order( $order_id );
	if ( ! $order || 'processing' !== $order->get_status() ) {
		return;
	}

	$items = $order->get_items();
	if ( 1 !== count( $items ) ) {
		return;
	}

	$item = array_values( $items )[0];
	if ( ! extrachill_shop_is_ad_free_license_product_id( $item->get_product_id() ) ) {
		return;
	}

	$order->update_status( 'completed', 'Auto-completed: Ad-Free License only.' );
}

function extrachill_shop_handle_ad_free_purchase( $order_id ) {
	if ( ! $order_id ) {
		return;
	}

	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return;
	}

	if ( $order->get_meta( '_ec_ad_free_license_processed', true ) ) {
		return;
	}

	if ( ! function_exists( 'ec_create_ad_free_license' ) ) {
		error_log( 'extrachill-shop: ec_create_ad_free_license() not found.' );
		return;
	}

	foreach ( $order->get_items() as $item ) {
		if ( ! extrachill_shop_is_ad_free_license_product_id( $item->get_product_id() ) ) {
			continue;
		}

		$username = (string) $item->get_meta( 'community_username', true );
		$username = sanitize_text_field( $username );

		if ( empty( $username ) ) {
			error_log( "extrachill-shop: missing community_username meta for order {$order_id}." );
			break;
		}

		$order_data = array(
			'order_id'   => $order_id,
			'timestamp'  => current_time( 'mysql' ),
			'product_id' => $item->get_product_id(),
		);

		$result = ec_create_ad_free_license( $username, $order_data );
		if ( is_wp_error( $result ) ) {
			error_log( 'extrachill-shop: license creation failed for order ' . $order_id . ': ' . $result->get_error_message() );
			break;
		}

		$order->update_meta_data( '_ec_ad_free_license_processed', 1 );
		$order->add_order_note( "Ad-free license granted for username: {$username}." );
		$order->save();
		break;
	}
}
