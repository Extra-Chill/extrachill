<?php
// File: extrachill-custom/ad-free-purchase.php

// this code allows users to purchase a license via woocommerc to remove ads from the site for a given community username

add_action('woocommerce_order_status_completed','ecc_handle_ad_free_purchase',10,1);
function ecc_handle_ad_free_purchase($order_id) {
    global $wpdb;
    $table = $wpdb->prefix.'extrachill_ad_free';
    $product_id = 90123;
    $order = wc_get_order($order_id);
    if(!$order) return;

    foreach($order->get_items() as $item) {
        if((int)$item->get_product_id() !== $product_id) {
            continue;
        }
        $username = trim($item->get_meta('community_username',true) ?: $item->get_meta('Community Username',true));
        if(empty($username)) {
            error_log("❌ No username meta for Order #{$order_id}");
            continue;
        }
        $username = sanitize_text_field($username);
        $exists = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE username=%s",$username));

        $wpdb->replace($table,[
            'username'=>$username,
            'date_purchased'=>current_time('mysql'),
            'order_id'=>$order_id
        ],['%s','%s','%d']);

        if($wpdb->last_error) {
            error_log("❌ DB error inserting ad-free for {$username}: ".$wpdb->last_error);
        } else {
            error_log("✅ Ad-free saved for '{$username}' (Order #{$order_id}) — existing? ".($exists?'yes':'no'));
        }
        break;
    }
}

add_action('woocommerce_before_add_to_cart_button','ecc_add_community_username_field');
function ecc_add_community_username_field() {
    global $product;
    if($product->get_id() !== 90123) return;

    $username = '';
    if(!empty($_COOKIE['ecc_user_session_token'])) {
        $details = get_user_details_directly(sanitize_text_field($_COOKIE['ecc_user_session_token']));
        $username = $details['username'] ?? '';
    }
    ?>
    <div class="community-username-field">
        <label for="community_username">Community Username <abbr>*</abbr></label>
        <input type="text" name="community_username" id="community_username" value="<?php echo esc_attr($username); ?>" required placeholder="Your Community Username">
        <p class="description"><?php echo $username ? 'Enter username for ad-free license.' : 'Enter your Community Username.'; ?></p>
    </div>
    <?php
}

add_action('woocommerce_payment_complete','ecc_auto_complete_ad_free_order',20);
function ecc_auto_complete_ad_free_order($order_id) {
    $order = wc_get_order($order_id);
    if(!$order || $order->get_status()!=='processing') return;

    $items = $order->get_items();
    if(count($items)===1 && (int)array_values($items)[0]->get_product_id()===90123) {
        $order->update_status('completed','Auto‑completed: Ad‑Free License only.');
    }
}

add_filter('woocommerce_add_cart_item_data','ecc_save_username_to_cart',10,3);
function ecc_save_username_to_cart($cart_item_data,$product_id) {
    if($product_id !== 90123) return $cart_item_data;

    if(!empty($_POST['community_username'])) {
        $cart_item_data['community_username'] = sanitize_text_field($_POST['community_username']);
    } elseif(!isset($cart_item_data['community_username']) && !empty($_COOKIE['ecc_user_session_token'])) {
        $details = get_user_details_directly(sanitize_text_field($_COOKIE['ecc_user_session_token']));
        if(!empty($details['username'])) {
            $cart_item_data['community_username'] = sanitize_text_field($details['username']);
        }
    }
    return $cart_item_data;
}

add_action('woocommerce_checkout_create_order_line_item','ecc_add_username_to_order_item',10,4);
function ecc_add_username_to_order_item($item,$key,$values) {
    if(!empty($values['community_username'])) {
        $item->add_meta_data('community_username',$values['community_username'],true);
    }
}

add_filter('woocommerce_get_item_data','ecc_display_username_cart',10,2);
function ecc_display_username_cart($item_data,$cart_item) {
    if((int)$cart_item['product_id']===90123 && !empty($cart_item['community_username'])) {
        $item_data[]=['key'=>'Community Username','value'=>esc_html($cart_item['community_username'])];
    }
    return $item_data;
}

add_action('woocommerce_cart_item_name','ecc_cart_username_input',20,3);
function ecc_cart_username_input($name,$cart_item,$key) {
    if((int)$cart_item['product_id']!==90123) return $name;

    $value = $cart_item['community_username'] 
           ?? ($_COOKIE['ecc_user_session_token'] 
               ? esc_attr(get_user_details_directly($_COOKIE['ecc_user_session_token'])['username'] ?? '') 
               : '');

    $name .= '<p><label>Community Username:<br><input type="text" name="community_username['.esc_attr($key).']" value="'.esc_attr($value).'" required></label></p>';
    return $name;
}

add_action('woocommerce_cart_updated','ecc_save_username_cart_on_cart');
function ecc_save_username_cart_on_cart() {
    foreach(WC()->cart->get_cart() as $key=>$item) {
        if((int)$item['product_id']===90123 && isset($_POST['community_username'][$key])) {
            WC()->cart->cart_contents[$key]['community_username'] = sanitize_text_field($_POST['community_username'][$key]);
        }
    }
}

add_action('woocommerce_check_cart_items','ecc_validate_username_cart');
function ecc_validate_username_cart() {
    foreach(WC()->cart->get_cart() as $item) {
        if((int)$item['product_id']===90123 && empty($item['community_username'])) {
            wc_add_notice('Please enter your Community Username for the Ad‑Free License before checking out.','error');
        }
    }
}
