<?php
/**
 * Raffle Frontend Counter
 *
 * Displays progress bar showing remaining raffle tickets on product pages.
 *
 * @package ExtraChillShop
 */

defined( 'ABSPATH' ) || exit;

/**
 * Display raffle ticket counter on product page
 */
function extrachill_shop_display_raffle_counter() {
    global $product;

    // Only run if product exists and has "raffle" tag
    if ( ! $product || ! has_term( 'raffle', 'product_tag', $product->get_id() ) ) {
        return;
    }

    // Get current stock and max tickets
    $current_stock = $product->get_stock_quantity();
    $max_tickets   = get_post_meta( $product->get_id(), '_raffle_max_tickets', true );

    // Validate data - if no max tickets set, don't display
    if ( ! $max_tickets || $max_tickets <= 0 ) {
        return;
    }

    // Calculate percentage (ensure we don't divide by zero)
    $remaining  = absint( $current_stock );
    $max        = absint( $max_tickets );
    $percentage = ( $max > 0 ) ? ( ( $remaining / $max ) * 100 ) : 0;

    // Determine color state based on stock level
    $state_class = '';
    if ( $percentage > 50 ) {
        $state_class = 'high-stock';
    } elseif ( $percentage > 25 ) {
        $state_class = 'medium-stock';
    } else {
        $state_class = 'low-stock';
    }

    // Output HTML
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
