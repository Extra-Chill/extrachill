/**
 * PaymentsTab - Stripe Connect integration for artist payments
 */

const PaymentsTab = ( {
	artistId,
	status,
	loading,
	error,
	onConnect,
	onOpenDashboard,
	onRefresh,
} ) => {
	const connected = !! status?.connected;
	const canReceivePayments = !! status?.can_receive_payments;

	const showPaymentsNote = artistId && connected && ! canReceivePayments;

	return (
		<div className="ec-asm__panel ec-asm__payments">
			<h3>Payments</h3>

			{ showPaymentsNote && (
				<div className="notice notice-info">
					<p>
						<strong>Note:</strong> Products stay as drafts until your Stripe account can receive payments.
					</p>
				</div>
			) }

			{ loading && <p>Loading</p> }
			{ error && <div className="notice notice-error"><p>{ error }</p></div> }

			{ artistId ? (
				<div className="ec-asm__stripe">
					<div className="ec-asm__stripe-status">
						<div>
							<strong>Status:</strong> { connected ? ( status?.status || 'connected' ) : 'not connected' }
						</div>
						{ connected ? (
							<ul>
								<li>Charges enabled: { status?.charges_enabled ? 'yes' : 'no' }</li>
								<li>Payouts enabled: { status?.payouts_enabled ? 'yes' : 'no' }</li>
								<li>Details submitted: { status?.details_submitted ? 'yes' : 'no' }</li>
								<li>Can receive payments: { canReceivePayments ? 'yes' : 'no' }</li>
							</ul>
						) : null }
					</div>

					<div className="ec-asm__actions">
						{ ! connected ? (
							<button
								type="button"
								className="button-1 button-medium"
								onClick={ onConnect }
								disabled={ loading }
							>
								Connect Stripe
							</button>
						) : (
							<button
								type="button"
								className="button-2 button-medium"
								onClick={ onOpenDashboard }
								disabled={ loading }
							>
								Open Stripe Dashboard
							</button>
						) }
						<button
							type="button"
							className="button-2 button-medium"
							onClick={ onRefresh }
							disabled={ loading }
						>
							Refresh Status
						</button>
					</div>
				</div>
			) : (
				<p>Select an artist to manage payments.</p>
			) }
		</div>
	);
};

export default PaymentsTab;
