<?php
/**
 * Artist Orders View
 *
 * Displays orders containing the artist's products in the WooCommerce My Account area.
 * Shows order details, line items, and calculated artist payouts.
 *
 * @package ExtraChillShop
 * @since 0.2.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Render the artist orders view.
 */
function extrachill_shop_render_artist_orders() {
	if ( ! extrachill_shop_user_is_artist() ) {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'You do not have permission to view this page.', 'extrachill-shop' ) . '</p></div>';
		return;
	}

	$user_artists = extrachill_shop_get_user_artists();
	$artist_ids   = wp_list_pluck( $user_artists, 'ID' );

	if ( empty( $artist_ids ) ) {
		echo '<div class="notice notice-info"><p>' . esc_html__( 'No artist profiles linked to your account.', 'extrachill-shop' ) . '</p></div>';
		return;
	}

	$orders = extrachill_shop_get_orders_for_artists( $artist_ids );
	?>

	<div class="extrachill-artist-orders">
		<h2><?php esc_html_e( 'Artist Orders', 'extrachill-shop' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Orders containing your products.', 'extrachill-shop' ); ?></p>

		<?php if ( empty( $orders ) ) : ?>
			<div class="notice notice-info">
				<p><?php esc_html_e( 'No orders yet for your products.', 'extrachill-shop' ); ?></p>
			</div>
		<?php else : ?>
			<table class="extrachill-artist-orders__table shop_table shop_table_responsive">
				<thead>
					<tr>
						<th class="order-number"><?php esc_html_e( 'Order', 'extrachill-shop' ); ?></th>
						<th class="order-date"><?php esc_html_e( 'Date', 'extrachill-shop' ); ?></th>
						<th class="order-status"><?php esc_html_e( 'Status', 'extrachill-shop' ); ?></th>
						<th class="order-items"><?php esc_html_e( 'Your Items', 'extrachill-shop' ); ?></th>
						<th class="order-total"><?php esc_html_e( 'Your Earnings', 'extrachill-shop' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $orders as $order_data ) : ?>
						<?php
						$order = $order_data['order'];
						$items = $order_data['items'];
						$total = $order_data['artist_total'];
						?>
						<tr>
							<td class="order-number" data-title="<?php esc_attr_e( 'Order', 'extrachill-shop' ); ?>">
								#<?php echo esc_html( $order->get_order_number() ); ?>
							</td>
							<td class="order-date" data-title="<?php esc_attr_e( 'Date', 'extrachill-shop' ); ?>">
								<?php echo esc_html( wc_format_datetime( $order->get_date_created() ) ); ?>
							</td>
							<td class="order-status" data-title="<?php esc_attr_e( 'Status', 'extrachill-shop' ); ?>">
								<span class="order-status-badge status-<?php echo esc_attr( $order->get_status() ); ?>">
									<?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?>
								</span>
							</td>
							<td class="order-items" data-title="<?php esc_attr_e( 'Your Items', 'extrachill-shop' ); ?>">
								<ul class="order-items-list">
									<?php foreach ( $items as $item ) : ?>
										<li>
											<?php echo esc_html( $item['name'] ); ?> × <?php echo esc_html( $item['quantity'] ); ?>
											<span class="item-payout"><?php echo wp_kses_post( wc_price( $item['artist_payout'] ) ); ?></span>
										</li>
									<?php endforeach; ?>
								</ul>
							</td>
							<td class="order-total" data-title="<?php esc_attr_e( 'Your Earnings', 'extrachill-shop' ); ?>">
								<?php echo wp_kses_post( wc_price( $total ) ); ?>
								<?php if ( 'completed' !== $order->get_status() && 'processing' !== $order->get_status() ) : ?>
									<span class="payout-pending"><?php esc_html_e( '(pending)', 'extrachill-shop' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php
			$stats = extrachill_shop_calculate_artist_earnings_stats( $orders );
			?>
			<div class="extrachill-artist-orders__summary">
				<div class="summary-item">
					<span class="label"><?php esc_html_e( 'Total Orders', 'extrachill-shop' ); ?></span>
					<span class="value"><?php echo esc_html( $stats['total_orders'] ); ?></span>
				</div>
				<div class="summary-item">
					<span class="label"><?php esc_html_e( 'Total Earnings', 'extrachill-shop' ); ?></span>
					<span class="value"><?php echo wp_kses_post( wc_price( $stats['total_earnings'] ) ); ?></span>
				</div>
				<div class="summary-item">
					<span class="label"><?php esc_html_e( 'Pending Payout', 'extrachill-shop' ); ?></span>
					<span class="value"><?php echo wp_kses_post( wc_price( $stats['pending_payout'] ) ); ?></span>
				</div>
			</div>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Get orders containing products from specified artists.
 *
 * @param array $artist_ids Array of artist profile IDs.
 * @return array Array of order data with filtered items.
 */
function extrachill_shop_get_orders_for_artists( $artist_ids ) {
	if ( empty( $artist_ids ) ) {
		return array();
	}

	global $wpdb;

	$artist_ids_placeholders = implode( ',', array_fill( 0, count( $artist_ids ), '%d' ) );

	$product_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT DISTINCT post_id FROM {$wpdb->postmeta}
			WHERE meta_key = '_artist_profile_id'
			AND meta_value IN ($artist_ids_placeholders)",
			...$artist_ids
		)
	);

	if ( empty( $product_ids ) ) {
		return array();
	}

	$orders = wc_get_orders(
		array(
			'limit'   => 50,
			'orderby' => 'date',
			'order'   => 'DESC',
			'status'  => array( 'completed', 'processing', 'on-hold', 'pending' ),
		)
	);

	$filtered_orders = array();

	foreach ( $orders as $order ) {
		$order_items  = array();
		$artist_total = 0;

		foreach ( $order->get_items() as $item ) {
			$product_id = $item->get_product_id();

			if ( ! in_array( $product_id, $product_ids, true ) ) {
				continue;
			}

			$line_total    = $item->get_total();
			$artist_payout = extrachill_shop_calculate_artist_payout( $line_total, $product_id );

			$order_items[] = array(
				'product_id'    => $product_id,
				'name'          => $item->get_name(),
				'quantity'      => $item->get_quantity(),
				'line_total'    => $line_total,
				'artist_payout' => $artist_payout,
			);

			$artist_total += $artist_payout;
		}

		if ( ! empty( $order_items ) ) {
			$filtered_orders[] = array(
				'order'        => $order,
				'items'        => $order_items,
				'artist_total' => $artist_total,
			);
		}
	}

	return $filtered_orders;
}

/**
 * Calculate earnings statistics from orders.
 *
 * @param array $orders Array of order data from extrachill_shop_get_orders_for_artists().
 * @return array Statistics array with total_orders, total_earnings, pending_payout.
 */
function extrachill_shop_calculate_artist_earnings_stats( $orders ) {
	$stats = array(
		'total_orders'   => count( $orders ),
		'total_earnings' => 0,
		'pending_payout' => 0,
	);

	foreach ( $orders as $order_data ) {
		$order  = $order_data['order'];
		$status = $order->get_status();
		$total  = $order_data['artist_total'];

		$stats['total_earnings'] += $total;

		if ( ! in_array( $status, array( 'completed', 'processing' ), true ) ) {
			$stats['pending_payout'] += $total;
		}
	}

	return $stats;
}
