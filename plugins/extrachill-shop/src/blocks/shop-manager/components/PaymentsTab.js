/**
 * PaymentsTab - payout account setup and the current Extra Chill fees.
 */

/**
 * External dependencies
 */
import { ActionRow, InlineStatus, Panel } from '@extrachill/components';

const PaymentsTab = ( {
	seller,
	status,
	fees,
	loading,
	error,
	sellerAgreementUrl,
	sellerAgreementAccepted,
	onSellerAgreementChange,
	onConnect,
	onOpenDashboard,
	onRefresh,
} ) => {
	const connected = !! status?.connected;
	const canReceivePayments = !! status?.can_receive_payments;

	const showPaymentsNote = seller && connected && ! canReceivePayments;
	const sellsTickets =
		seller && ( 'venue' === seller.type || 'promoter' === seller.type );

	return (
		<Panel className="ec-asm__payments">
			{ showPaymentsNote && (
				<InlineStatus tone="warning">
					<strong>Note:</strong> Products stay as drafts until your
					payout account can receive payments.
				</InlineStatus>
			) }

			{ sellsTickets && (
				<InlineStatus tone="info">
					This payout account also receives your ticket sales. Ticket
					prices are set on each event; ticket payouts and refunds
					show in your payout dashboard.
				</InlineStatus>
			) }

			{ fees && (
				<div>
					<h4>Fees</h4>
					<ul>
						<li>{ fees.merch_summary }</li>
						{ sellsTickets && <li>{ fees.ticket_summary }</li> }
					</ul>
					<p className="ec-asm__muted">
						The fee is taken automatically when the buyer pays.
						Payment processing fees on merch come out of your payout
						account separately.
						{ sellsTickets &&
							' On tickets, the buyer’s service fee covers them.' }
					</p>
				</div>
			) }

			{ loading && (
				<InlineStatus tone="info">Loading payments…</InlineStatus>
			) }
			{ error && <InlineStatus tone="error">{ error }</InlineStatus> }

			{ seller ? (
				<div className="ec-asm__stripe">
					<div className="ec-asm__stripe-status">
						<div>
							<strong>Status:</strong>{ ' ' }
							{ connected
								? status?.status || 'connected'
								: 'not connected' }
						</div>
						{ connected ? (
							<ul>
								<li>
									Charges enabled:{ ' ' }
									{ status?.charges_enabled ? 'yes' : 'no' }
								</li>
								<li>
									Payouts enabled:{ ' ' }
									{ status?.payouts_enabled ? 'yes' : 'no' }
								</li>
								<li>
									Details submitted:{ ' ' }
									{ status?.details_submitted ? 'yes' : 'no' }
								</li>
								<li>
									Can receive payments:{ ' ' }
									{ canReceivePayments ? 'yes' : 'no' }
								</li>
							</ul>
						) : null }
					</div>

					{ ! connected && (
						<label
							className="ec-checkbox-row"
							htmlFor="ec-asm-seller-agreement"
						>
							<input
								id="ec-asm-seller-agreement"
								type="checkbox"
								checked={ !! sellerAgreementAccepted }
								onChange={ ( event ) =>
									onSellerAgreementChange(
										event.target.checked
									)
								}
								disabled={ loading }
							/>
							<span>
								I agree to the{ ' ' }
								<a
									href={ sellerAgreementUrl }
									target="_blank"
									rel="noopener noreferrer"
								>
									Seller Agreement
								</a>
								.
							</span>
						</label>
					) }

					<ActionRow className="ec-asm__actions">
						{ ! connected ? (
							<button
								type="button"
								className="button-1 button-small"
								onClick={ onConnect }
								disabled={
									loading || ! sellerAgreementAccepted
								}
							>
								Connect payouts
							</button>
						) : (
							<button
								type="button"
								className="button-2 button-small"
								onClick={ onOpenDashboard }
								disabled={ loading }
							>
								Open payout dashboard
							</button>
						) }
						<button
							type="button"
							className="button-2 button-small"
							onClick={ onRefresh }
							disabled={ loading }
						>
							Refresh Status
						</button>
					</ActionRow>
				</div>
			) : (
				<p>Select a shop to manage payments.</p>
			) }
		</Panel>
	);
};

export default PaymentsTab;
