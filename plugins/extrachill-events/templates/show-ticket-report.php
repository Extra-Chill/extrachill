<?php
/**
 * Show sales report in the host tools on /guest-list (extrachill#1746).
 *
 * Rendered by extrachill_events_render_guest_list_door() for people who
 * manage the event. Numbers come from the extrachill/get-show-ticket-report
 * ability; money and codes only when Shop shares them (seller authority).
 * The door list CSV download sits here too.
 *
 * Ships no CSS of its own: theme classes, allow-listed in
 * RsvpPassDesignSystemTest.php.
 *
 * @package ExtraChillEvents
 *
 * @var int        $event_id          Event post ID.
 * @var array|null $ticket_report     extrachill/get-show-ticket-report result, or null for a show with no tickets.
 * @var string     $door_list_csv_url Nonced door list CSV download link.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$report_currency = strtoupper( (string) ( $ticket_report['currency'] ?? 'usd' ) );
$report_money    = static function ( int $minor ) use ( $report_currency ): string {
	return $report_currency . ' ' . number_format_i18n( $minor / 100, 2 );
};
?>
<section id="ec-show-ticket-report" class="ec-surface-card ec-card-padding">
	<h3><?php esc_html_e( 'Sales report', 'extrachill-events' ); ?></h3>
	<?php if ( $ticket_report ) : ?>
		<p>
		<?php
		printf(
			/* translators: 1: tickets sold, 2: tickets refunded. */
			esc_html__( 'Tickets sold: %1$d. Refunded: %2$d.', 'extrachill-events' ),
			(int) $ticket_report['sold'],
			(int) $ticket_report['refunded']
		);
		?>
		</p>
		<p>
		<?php
		printf(
			/* translators: 1: tickets checked in, 2: tickets sold, 3: check-in percent. */
			esc_html__( 'Checked in: %1$d of %2$d tickets (%3$d%%).', 'extrachill-events' ),
			(int) $ticket_report['checked_in'],
			(int) $ticket_report['sold'],
			(int) $ticket_report['check_in_rate']
		);
		?>
		</p>
		<?php if ( $ticket_report['money_available'] ) : ?>
			<table>
				<tbody>
					<tr><th scope="row"><?php esc_html_e( 'Gross', 'extrachill-events' ); ?></th><td><?php echo esc_html( $report_money( (int) $ticket_report['gross_minor'] ) ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Refunds', 'extrachill-events' ); ?></th><td><?php echo esc_html( $report_money( (int) $ticket_report['refunds_minor'] ) ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Extra Chill fees', 'extrachill-events' ); ?></th><td><?php echo esc_html( $report_money( (int) $ticket_report['fees_minor'] ) ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Net', 'extrachill-events' ); ?></th><td><?php echo esc_html( $report_money( (int) $ticket_report['net_minor'] ) ); ?></td></tr>
				</tbody>
			</table>
		<?php else : ?>
			<p class="notice notice-info"><?php esc_html_e( 'Money and code totals show to people who manage the seller\'s payouts.', 'extrachill-events' ); ?></p>
		<?php endif; ?>
		<?php if ( $ticket_report['ticket_types'] ) : ?>
			<h4><?php esc_html_e( 'By ticket type', 'extrachill-events' ); ?></h4>
			<table>
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Type', 'extrachill-events' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Sold', 'extrachill-events' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Limit', 'extrachill-events' ); ?></th>
						<?php if ( $ticket_report['money_available'] ) : ?>
							<th scope="col"><?php esc_html_e( 'Revenue', 'extrachill-events' ); ?></th>
						<?php endif; ?>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $ticket_report['ticket_types'] as $report_type ) : ?>
						<tr>
							<td><?php echo esc_html( (string) $report_type['name'] ); ?></td>
							<td><?php echo esc_html( number_format_i18n( (int) $report_type['sold'] ) ); ?></td>
							<td><?php echo esc_html( $report_type['quantity'] > 0 ? number_format_i18n( (int) $report_type['quantity'] ) : __( 'None', 'extrachill-events' ) ); ?></td>
							<?php if ( $ticket_report['money_available'] ) : ?>
								<td><?php echo esc_html( $report_money( (int) $report_type['revenue_minor'] ) ); ?></td>
							<?php endif; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php if ( $ticket_report['money_available'] && $ticket_report['codes'] ) : ?>
			<h4><?php esc_html_e( 'By code', 'extrachill-events' ); ?></h4>
			<table>
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Code', 'extrachill-events' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Tickets', 'extrachill-events' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Limit', 'extrachill-events' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Discount given', 'extrachill-events' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $ticket_report['codes'] as $report_code ) : ?>
						<tr>
							<td><?php echo esc_html( (string) $report_code['code'] ); ?></td>
							<td><?php echo esc_html( number_format_i18n( (int) $report_code['uses'] ) ); ?></td>
							<td><?php echo esc_html( $report_code['max_uses'] > 0 ? number_format_i18n( (int) $report_code['max_uses'] ) : __( 'None', 'extrachill-events' ) ); ?></td>
							<td><?php echo esc_html( $report_money( (int) $report_code['discount_minor'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	<?php else : ?>
		<p><?php esc_html_e( 'This show has no ticket sales yet.', 'extrachill-events' ); ?></p>
	<?php endif; ?>
	<p><a class="button-2 button-small" href="<?php echo esc_url( $door_list_csv_url ); ?>"><?php esc_html_e( 'Download door list (CSV)', 'extrachill-events' ); ?></a></p>
</section>
