<?php
/**
 * WooCommerce Ad-Free License Integration for ExtraChill Shop Plugin
 *
 * Handles WooCommerce UI integration for ad-free license purchases.
 * License creation delegated to extrachill-users plugin.
 *
 * @package ExtraChillShop
 * @since 1.0.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handle ad-free purchase when order is completed
 *
 * Delegates license creation to extrachill-users plugin.
 *
 * @param int $order_id WooCommerce order ID
 */
add_action('woocommerce_order_status_completed','extrachill_shop_handle_ad_free_purchase',10,1);
function extrachill_shop_handle_ad_free_purchase($order_id) {
    $product_id = 90123;
    $order = wc_get_order($order_id);
    if(!$order) return;

    foreach($order->get_items() as $item) {
        if((int)$item->get_product_id() !== $product_id) {
            continue;
        }

        // Get community username from order meta
        $username = trim($item->get_meta('community_username',true) ?: $item->get_meta('Community Username',true));
        if(empty($username)) {
            error_log("❌ No username meta for Order #{$order_id}");
            continue;
        }

        // Delegate license creation to users plugin
        if (!function_exists('ec_create_ad_free_license')) {
            error_log("❌ ec_create_ad_free_license() not found - extrachill-users plugin required");
            continue;
        }

        $order_data = array(
            'order_id' => $order_id,
            'timestamp' => current_time('mysql')
        );

        $result = ec_create_ad_free_license($username, $order_data);

        if (is_wp_error($result)) {
            error_log("❌ License creation failed (Order #{$order_id}): " . $result->get_error_message());
        } else {
            error_log("✅ Ad-free license created for '{$username}' (Order #{$order_id})");
        }
        break;
    }
}

add_action('woocommerce_before_add_to_cart_button','extrachill_shop_add_community_username_field');
function extrachill_shop_add_community_username_field() {
    global $product;
    if($product->get_id() !== 90123) return;

    $username = '';
    if (is_user_logged_in()) {
        $user = wp_get_current_user();
        $username = $user->user_nicename;
    }
    ?>
    <div class="community-username-field">
        <label for="community_username">Community Username <abbr>*</abbr></label>
        <input type="text" name="community_username" id="community_username" value="<?php echo esc_attr($username); ?>" required placeholder="Your Community Username">
        <p class="description"><?php echo $username ? 'Enter username for ad-free license.' : 'Enter your Community Username.'; ?></p>
    </div>
    <?php
}

add_action('woocommerce_payment_complete','extrachill_shop_auto_complete_ad_free_order',20);
function extrachill_shop_auto_complete_ad_free_order($order_id) {
    $order = wc_get_order($order_id);
    if(!$order || $order->get_status()!=='processing') return;

    $items = $order->get_items();
    if(count($items)===1 && (int)array_values($items)[0]->get_product_id()===90123) {
        $order->update_status('completed','Auto‑completed: Ad‑Free License only.');
    }
}

add_filter('woocommerce_add_cart_item_data','extrachill_shop_save_username_to_cart',10,3);
function extrachill_shop_save_username_to_cart($cart_item_data,$product_id) {
    if($product_id !== 90123) return $cart_item_data;

    if(!empty($_POST['community_username'])) {
        $cart_item_data['community_username'] = sanitize_text_field($_POST['community_username']);
    } elseif(!isset($cart_item_data['community_username']) && is_user_logged_in()) {
        $user = wp_get_current_user();
        if(!empty($user->user_nicename)) {
            $cart_item_data['community_username'] = sanitize_text_field($user->user_nicename);
        }
    }
    return $cart_item_data;
}

add_action('woocommerce_checkout_create_order_line_item','extrachill_shop_add_username_to_order_item',10,4);
function extrachill_shop_add_username_to_order_item($item,$key,$values) {
    if(!empty($values['community_username'])) {
        $item->add_meta_data('community_username',$values['community_username'],true);
    }
}

add_filter('woocommerce_get_item_data','extrachill_shop_display_username_cart',10,2);
function extrachill_shop_display_username_cart($item_data,$cart_item) {
    if((int)$cart_item['product_id']===90123 && !empty($cart_item['community_username'])) {
        $item_data[]=['key'=>'Community Username','value'=>esc_html($cart_item['community_username'])];
    }
    return $item_data;
}

add_action('woocommerce_cart_item_name','extrachill_shop_cart_username_input',20,3);
function extrachill_shop_cart_username_input($name,$cart_item,$key) {
    if((int)$cart_item['product_id']!==90123) return $name;

    $value = $cart_item['community_username'] ?? '';
    if (empty($value) && is_user_logged_in()) {
        $user = wp_get_current_user();
        $value = esc_attr($user->user_nicename);
    }

    $name .= '<p><label>Community Username:<br><input type="text" name="community_username['.esc_attr($key).']" value="'.esc_attr($value).'" required></label></p>';
    return $name;
}

add_action('woocommerce_cart_updated','extrachill_shop_save_username_cart_on_cart');
function extrachill_shop_save_username_cart_on_cart() {
    foreach(WC()->cart->get_cart() as $key=>$item) {
        if((int)$item['product_id']===90123 && isset($_POST['community_username'][$key])) {
            WC()->cart->cart_contents[$key]['community_username'] = sanitize_text_field($_POST['community_username'][$key]);
        }
    }
}

add_action('woocommerce_check_cart_items','extrachill_shop_validate_username_cart');
function extrachill_shop_validate_username_cart() {
    foreach(WC()->cart->get_cart() as $item) {
        if((int)$item['product_id']===90123 && empty($item['community_username'])) {
            wc_add_notice('Please enter your Community Username for the Ad‑Free License before checking out.','error');
        }
    }
}

