<?php
/**
 * Raffle Progress Bar
 *
 * Shows remaining tickets for products with "raffle" tag.
 * Color states: high-stock (>50%), medium-stock (25-50%), low-stock (<25%).
 *
 * @package ExtraChillShop
 */

defined( 'ABSPATH' ) || exit;

function extrachill_shop_display_raffle_counter() {
    global $product;

    if ( ! $product || ! has_term( 'raffle', 'product_tag', $product->get_id() ) ) {
        return;
    }

    $current_stock = $product->get_stock_quantity();
    $max_tickets   = get_post_meta( $product->get_id(), '_raffle_max_tickets', true );

    if ( ! $max_tickets || $max_tickets <= 0 ) {
        return;
    }

    $remaining  = absint( $current_stock );
    $max        = absint( $max_tickets );
    $percentage = ( $max > 0 ) ? ( ( $remaining / $max ) * 100 ) : 0;

    $state_class = '';
    if ( $percentage > 50 ) {
        $state_class = 'high-stock';
    } elseif ( $percentage > 25 ) {
        $state_class = 'medium-stock';
    } else {
        $state_class = 'low-stock';
    }

    ?>
    <div class="extrachill-raffle-progress <?php echo esc_attr( $state_class ); ?>">
        <div class="raffle-progress-header">
            <span class="raffle-icon">🎟️</span>
            <span class="raffle-text">
                <?php
                printf(
                    /* translators: %1$d: remaining tickets, %2$d: total tickets */
                    esc_html__( '%1$d/%2$d tickets remaining', 'extrachill-shop' ),
                    $remaining,
                    $max
                );
                ?>
            </span>
        </div>
        <div class="raffle-progress-bar">
            <div class="raffle-progress-fill" style="width: <?php echo esc_attr( $percentage ); ?>%"></div>
        </div>
    </div>
    <?php
}
add_action( 'woocommerce_single_product_summary', 'extrachill_shop_display_raffle_counter', 25 );
