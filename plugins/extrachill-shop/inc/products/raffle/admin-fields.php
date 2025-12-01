<?php
/**
 * Raffle Admin Field
 *
 * Conditional "Max Raffle Tickets" field in WooCommerce inventory tab.
 * Visibility controlled by "raffle" product tag via JavaScript (assets/js/raffle-admin.js).
 *
 * @package ExtraChillShop
 */

defined( 'ABSPATH' ) || exit;
function extrachill_shop_add_raffle_max_tickets_field() {
    global $post;

    echo '<div class="options_group raffle-max-tickets-field">';

    woocommerce_wp_text_input(
        array(
            'id'                => '_raffle_max_tickets',
            'label'             => __( 'Max Raffle Tickets', 'extrachill-shop' ),
            'placeholder'       => __( 'Enter maximum tickets', 'extrachill-shop' ),
            'desc_tip'          => true,
            'description'       => __( 'Maximum raffle tickets available (required for raffle products)', 'extrachill-shop' ),
            'type'              => 'number',
            'custom_attributes' => array(
                'step' => '1',
                'min'  => '1',
            ),
        )
    );

    echo '</div>';
}
add_action( 'woocommerce_product_options_inventory_product_data', 'extrachill_shop_add_raffle_max_tickets_field' );

function extrachill_shop_save_raffle_max_tickets_field( $product_id ) {
	if ( ! current_user_can( 'edit_product', $product_id ) ) {
		return;
	}

	$max_tickets = isset( $_POST['_raffle_max_tickets'] ) ? absint( $_POST['_raffle_max_tickets'] ) : '';

    if ( $max_tickets ) {
        update_post_meta( $product_id, '_raffle_max_tickets', $max_tickets );
    } else {
        delete_post_meta( $product_id, '_raffle_max_tickets' );
    }
}
add_action( 'woocommerce_process_product_meta', 'extrachill_shop_save_raffle_max_tickets_field' );
