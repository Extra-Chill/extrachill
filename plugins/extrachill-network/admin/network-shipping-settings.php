<?php
/**
 * ExtraChill Network Shipping Settings
 *
 * Read-only reference for the shipping policy (carrier, rate, parcel, scope).
 *
 * The Shippo API credential is a commerce secret and is configured on the
 * network Payments surface (admin/network-payments-settings.php), which is the
 * single write path for all platform commerce credentials — see
 * `ec_network_commerce_fields()`. There is intentionally NO credential form here
 * to avoid a duplicate write path for `extrachill_shippo_api_key`.
 *
 * @package ExtraChill\Network
 */

defined( 'ABSPATH' ) || exit;

add_action( 'network_admin_menu', 'ec_add_network_shipping_menu' );

/**
 * Add shipping settings page to network admin menu
 */
function ec_add_network_shipping_menu() {
	add_submenu_page(
		EXTRACHILL_NETWORK_MENU_SLUG,
		'Shipping Settings',
		'Shipping',
		'manage_network_options',
		'extrachill-shipping',
		'ec_render_network_shipping_page'
	);
}

/**
 * Render network shipping settings page (read-only policy reference).
 */
function ec_render_network_shipping_page() {
	$shipping_ready = class_exists( '\ExtraChill\Shop\Auth\ShippoAuthProvider' )
		&& '' !== ( new \ExtraChill\Shop\Auth\ShippoAuthProvider() )->get_api_key();
	$payments_url   = add_query_arg(
		array( 'page' => 'extrachill-payments' ),
		network_admin_url( 'admin.php' )
	);
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Extra Chill Shipping Settings', 'extrachill-network' ); ?></h1>

		<div class="notice notice-info">
			<p>
				<?php
				printf(
					/* translators: %s: URL to the Payments settings page. */
					esc_html__( 'The Shippo API key is configured under %s (Commerce Settings) alongside the other platform commerce credentials.', 'extrachill-network' ),
					'<a href="' . esc_url( $payments_url ) . '">' . esc_html__( 'Payments', 'extrachill-network' ) . '</a>'
				);
				?>
				<?php if ( $shipping_ready ) : ?>
					<span style="color: #46b450; font-weight: bold;">&#10003; <?php esc_html_e( 'Shippo is configured', 'extrachill-network' ); ?></span>
				<?php else : ?>
					<span style="color: #dc3232; font-weight: bold;">&#9888; <?php esc_html_e( 'Shippo API key not set', 'extrachill-network' ); ?></span>
				<?php endif; ?>
			</p>
		</div>

		<div class="card" style="max-width: 800px;">
			<h3><?php esc_html_e( 'Shipping Configuration', 'extrachill-network' ); ?></h3>
			<table class="widefat" style="max-width: 400px;">
				<tbody>
					<tr>
						<td><strong><?php esc_html_e( 'Carrier', 'extrachill-network' ); ?></strong></td>
						<td>USPS</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Rate Selection', 'extrachill-network' ); ?></strong></td>
						<td><?php esc_html_e( 'Auto-select cheapest', 'extrachill-network' ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Customer Rate', 'extrachill-network' ); ?></strong></td>
						<td>$5 per artist</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Default Parcel', 'extrachill-network' ); ?></strong></td>
						<td>10&quot; &times; 8&quot; &times; 4&quot;, 1 lb</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Geographic Scope', 'extrachill-network' ); ?></strong></td>
						<td><?php esc_html_e( 'US Domestic Only', 'extrachill-network' ); ?></td>
					</tr>
				</tbody>
			</table>
			<p class="description">
				<?php esc_html_e( 'These values are defined in code (extrachill-shop shipping defaults). This page is a read-only reference.', 'extrachill-network' ); ?>
			</p>
		</div>
	</div>

	<style>
		.card {
			background: #fff;
			border: 1px solid #ccd0d4;
			border-radius: 4px;
			padding: 20px;
			margin: 20px 0;
		}
		.card h3 {
			margin-top: 0;
		}
	</style>
	<?php
}
