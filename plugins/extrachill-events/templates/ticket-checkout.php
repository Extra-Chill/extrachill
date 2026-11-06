<?php
/**
 * Paid ticket form.
 *
 * @package ExtraChillEvents
 *
 * @var int   $post_id Event post ID.
 * @var array $offer   Public ticket offer.
 * @var int   $maximum Largest quantity a buyer may pick.
 */
defined( 'ABSPATH' ) || exit;
$currency = strtoupper( $offer['currency'] );
$decimals = in_array( strtolower( $currency ), array( 'bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf' ), true ) ? 0 : 2;
if ( in_array( strtolower( $currency ), array( 'bhd', 'jod', 'kwd', 'omr', 'tnd' ), true ) ) {
	$decimals = 3;
}
?>
<details>
	<summary class="button-1"><?php esc_html_e( 'Buy tickets', 'extrachill-events' ); ?></summary>
	<p><?php echo esc_html( $currency . ' ' . number_format_i18n( $offer['price_minor'] / pow( 10, $decimals ), $decimals ) ); ?>
	<?php if ( null !== $offer['remaining'] ) : ?>
		— <?php /* translators: %s: number of tickets left. */ echo esc_html( sprintf( __( '%s remaining', 'extrachill-events' ), number_format_i18n( $offer['remaining'] ) ) ); ?>
	<?php endif; ?>
	</p>
	<form data-ticket-checkout data-event="<?php echo esc_attr( (string) $post_id ); ?>">
		<p><label><?php esc_html_e( 'Quantity', 'extrachill-events' ); ?> <input name="quantity" type="number" min="1" max="<?php echo esc_attr( (string) $maximum ); ?>" value="1" required></label></p>
		<p><label><?php esc_html_e( 'Name', 'extrachill-events' ); ?> <input name="name" type="text" autocomplete="name" maxlength="100" required></label></p>
		<p><label><?php esc_html_e( 'Email', 'extrachill-events' ); ?> <input name="email" type="email" autocomplete="email" required></label></p>
		<?php
		if ( function_exists( 'ec_render_turnstile_widget' ) ) {
			echo wp_kses_post( ec_render_turnstile_widget( array( 'data-appearance' => 'interaction-only' ) ) );
		}
		?>
		<button class="button-1" type="submit"><?php esc_html_e( 'Buy tickets', 'extrachill-events' ); ?></button>
		<p class="notice notice-error" role="alert" data-ticket-error hidden></p>
		<noscript><?php esc_html_e( 'Please enable JavaScript to buy tickets.', 'extrachill-events' ); ?></noscript>
	</form>
</details>
