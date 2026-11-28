<?php
/**
 * Paid ticket form.
 *
 * @package ExtraChillEvents
 *
 * @var int   $post_id Event post ID.
 * @var array $offer   Public ticket offer.
 * @var int   $maximum Largest quantity a buyer may pick.
 * @var int|null $available Remaining inventory less advisory checkout holds.
 * @var array[] $ticket_types Buyer-visible ticket types, remaining less holds.
 * @var string  $entered_code Code from the page URL ('' for none).
 */
defined( 'ABSPATH' ) || exit;
$currency = strtoupper( $offer['currency'] );
$decimals = in_array( strtolower( $currency ), array( 'bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf' ), true ) ? 0 : 2;
if ( in_array( strtolower( $currency ), array( 'bhd', 'jod', 'kwd', 'omr', 'tnd' ), true ) ) {
	$decimals = 3;
}
$format_price = static function ( int $minor ) use ( $currency, $decimals ): string {
	return $currency . ' ' . number_format_i18n( $minor / pow( 10, $decimals ), $decimals );
};
$code         = is_array( $offer['code'] ?? null ) ? $offer['code'] : null;
$first_open   = null;
foreach ( $ticket_types as $ticket_type ) {
	if ( null === $ticket_type['remaining'] || $ticket_type['remaining'] > 0 ) {
		$first_open = $ticket_type['id'];
		break;
	}
}
?>
<details<?php echo '' !== $entered_code ? ' open' : ''; ?>>
	<summary class="button-1"><?php esc_html_e( 'Buy tickets', 'extrachill-events' ); ?></summary>
	<?php if ( 1 === count( $ticket_types ) ) : ?>
		<p><?php echo esc_html( $format_price( (int) $ticket_types[0]['unit_price_minor'] ) ); ?>
		<?php if ( $ticket_types[0]['unit_price_minor'] < $ticket_types[0]['price_minor'] ) : ?>
			<?php /* translators: %s: price before the code. */ echo esc_html( sprintf( __( '(was %s)', 'extrachill-events' ), $format_price( (int) $ticket_types[0]['price_minor'] ) ) ); ?>
		<?php endif; ?>
		<?php if ( null !== $ticket_types[0]['remaining'] ) : ?>
			— <?php /* translators: %s: number of tickets left. */ echo esc_html( sprintf( __( '%s remaining', 'extrachill-events' ), number_format_i18n( $ticket_types[0]['remaining'] ) ) ); ?>
		<?php endif; ?>
		</p>
	<?php endif; ?>
	<?php if ( $code ) : ?>
		<p class="notice notice-success" role="status"><?php /* translators: %s: code. */ echo esc_html( sprintf( __( 'Code %s applied.', 'extrachill-events' ), $code['code'] ) ); ?></p>
	<?php elseif ( ! empty( $offer['code_invalid'] ) ) : ?>
		<p class="notice notice-error" role="alert"><?php esc_html_e( 'That code does not work for this show.', 'extrachill-events' ); ?></p>
	<?php endif; ?>
	<form method="get" action="<?php echo esc_url( (string) get_permalink( $post_id ) ); ?>">
		<p><label><?php esc_html_e( 'Have a code?', 'extrachill-events' ); ?> <input name="ticket_code" type="text" maxlength="32" autocomplete="off" value="<?php echo esc_attr( $entered_code ); ?>"></label>
		<button class="button-3 button-small" type="submit"><?php esc_html_e( 'Apply', 'extrachill-events' ); ?></button></p>
	</form>
	<form data-ticket-checkout data-event="<?php echo esc_attr( (string) $post_id ); ?>">
		<?php if ( $code ) : ?>
			<input type="hidden" name="code" value="<?php echo esc_attr( $code['code'] ); ?>">
		<?php endif; ?>
		<?php if ( 1 === count( $ticket_types ) ) : ?>
			<input type="hidden" name="ticket_type" value="<?php echo esc_attr( $ticket_types[0]['id'] ); ?>">
		<?php else : ?>
			<fieldset>
				<legend><?php esc_html_e( 'Ticket type', 'extrachill-events' ); ?></legend>
				<?php foreach ( $ticket_types as $ticket_type ) : ?>
					<?php $sold_out = null !== $ticket_type['remaining'] && $ticket_type['remaining'] < 1; ?>
					<label class="ec-checkbox-row">
						<input name="ticket_type" type="radio" value="<?php echo esc_attr( $ticket_type['id'] ); ?>" required <?php checked( $first_open, $ticket_type['id'] ); ?> <?php disabled( $sold_out ); ?>>
						<span>
							<?php echo esc_html( $ticket_type['name'] . ' · ' . $format_price( (int) $ticket_type['unit_price_minor'] ) ); ?>
							<?php if ( $ticket_type['unit_price_minor'] < $ticket_type['price_minor'] ) : ?>
								<?php /* translators: %s: price before the code. */ echo esc_html( sprintf( __( '(was %s)', 'extrachill-events' ), $format_price( (int) $ticket_type['price_minor'] ) ) ); ?>
							<?php endif; ?>
							<?php if ( $sold_out ) : ?>
								— <?php esc_html_e( 'Sold out', 'extrachill-events' ); ?>
							<?php elseif ( null !== $ticket_type['remaining'] ) : ?>
								— <?php /* translators: %s: number of tickets left. */ echo esc_html( sprintf( __( '%s remaining', 'extrachill-events' ), number_format_i18n( $ticket_type['remaining'] ) ) ); ?>
							<?php endif; ?>
						</span>
					</label>
				<?php endforeach; ?>
			</fieldset>
		<?php endif; ?>
		<p><label><?php esc_html_e( 'Quantity', 'extrachill-events' ); ?> <input name="quantity" type="number" min="1" max="<?php echo esc_attr( (string) $maximum ); ?>" value="1" required></label></p>
		<p><label><?php esc_html_e( 'Name', 'extrachill-events' ); ?> <input name="name" type="text" autocomplete="name" maxlength="100" required></label></p>
		<p><label><?php esc_html_e( 'Email', 'extrachill-events' ); ?> <input name="email" type="email" autocomplete="email" required></label></p>
		<?php $venue = 'venue' === ( $offer['seller']['type'] ?? '' ) ? get_term( (int) $offer['seller']['id'], 'venue' ) : null; ?>
		<?php if ( $venue instanceof WP_Term ) : ?>
			<label class="ec-checkbox-row"><input name="venue_show_updates" type="checkbox" value="1"><span><?php /* translators: %s: venue name. */ echo esc_html( sprintf( __( 'Get %s show updates', 'extrachill-events' ), $venue->name ) ); ?></span></label>
		<?php endif; ?>
		<label class="ec-checkbox-row">
			<input name="terms_accepted" type="checkbox" value="1" required>
			<span>
			<?php
			echo wp_kses(
				sprintf(
					/* translators: %s: Terms of Service URL. */
					__( 'I agree to the <a href="%s" target="_blank" rel="noopener noreferrer">Terms of Service</a>.', 'extrachill-events' ),
					esc_url( extrachill_events_ticket_terms_url() )
				),
				array(
					'a' => array(
						'href'   => array(),
						'target' => array(),
						'rel'    => array(),
					),
				)
			);
			?>
			</span>
		</label>
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
