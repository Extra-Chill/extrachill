/**
 * OrdersTab - Order management with shipping label integration
 */

/**
 * External dependencies
 */
import {
	ActionRow,
	Badge,
	FieldGroup,
	InlineStatus,
	Panel,
	PanelHeader,
} from '@extrachill/components';

/**
 * WordPress dependencies
 */
import { useState, useEffect } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { purchaseShippingLabel, quoteShippingLabel } from '../api';

const DEFAULT_PARCEL = {
	weight: 16,
	mass_unit: 'oz',
	length: 10,
	width: 8,
	height: 4,
};

const money = ( value ) => `$${ Number( value || 0 ).toFixed( 2 ) }`;

const ORDER_FILTERS = [
	{ id: 'all', label: 'All' },
	{ id: 'needs_fulfillment', label: 'Needs Fulfillment' },
	{ id: 'completed', label: 'Completed' },
];

const OrdersTab = ( {
	seller,
	orders,
	loading,
	error,
	filter,
	selectedOrder,
	onFilterChange,
	onSelectOrder,
	onMarkShipped,
	onRefund,
	onRefresh,
} ) => {
	const [ trackingInput, setTrackingInput ] = useState( '' );
	const [ actionLoading, setActionLoading ] = useState( false );
	const [ actionError, setActionError ] = useState( '' );
	const [ showRefundConfirm, setShowRefundConfirm ] = useState( false );
	const [ refundAmount, setRefundAmount ] = useState( '' );
	const [ labelPurchasing, setLabelPurchasing ] = useState( false );
	const [ labelSuccess, setLabelSuccess ] = useState( null );
	const [ parcel, setParcel ] = useState( DEFAULT_PARCEL );
	const [ labelQuote, setLabelQuote ] = useState( null );
	const [ quoting, setQuoting ] = useState( false );
	const [ confirmOverage, setConfirmOverage ] = useState( false );

	useEffect( () => {
		setTrackingInput( '' );
		setActionError( '' );
		setShowRefundConfirm( false );
		setRefundAmount( '' );
		setLabelSuccess( null );
		setParcel( {
			...DEFAULT_PARCEL,
			...( selectedOrder?.label_parcel || {} ),
		} );
		setLabelQuote( null );
		setConfirmOverage( false );
		// Prefill only when another order is opened.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ selectedOrder?.id ] );

	const updateParcel = ( field, value ) => {
		setParcel( ( current ) => ( { ...current, [ field ]: value } ) );
		// A new parcel needs a new price.
		setLabelQuote( null );
		setConfirmOverage( false );
	};

	const handleQuoteLabel = async () => {
		if ( ! selectedOrder || ! seller ) {
			return;
		}
		setQuoting( true );
		setActionError( '' );
		try {
			setLabelQuote(
				await quoteShippingLabel( selectedOrder.id, seller, parcel )
			);
		} catch ( err ) {
			setActionError( err?.message || 'Failed to price the label.' );
		} finally {
			setQuoting( false );
		}
	};

	const handleMarkShipped = async () => {
		if ( ! selectedOrder ) {
			return;
		}
		setActionLoading( true );
		setActionError( '' );
		try {
			await onMarkShipped( selectedOrder.id, trackingInput || null );
			onSelectOrder( null );
		} catch ( err ) {
			setActionError( err?.message || 'Failed to mark as shipped.' );
		} finally {
			setActionLoading( false );
		}
	};

	const handleRefund = async () => {
		if ( ! selectedOrder ) {
			return;
		}
		const amount = Number( refundAmount );
		if ( ! Number.isFinite( amount ) || amount <= 0 ) {
			setActionError( 'Enter a refund amount greater than $0.00.' );
			return;
		}
		if ( amount > refundable + 0.000001 ) {
			setActionError(
				`You can refund up to ${ money( refundable ) } on this order.`
			);
			return;
		}
		setActionLoading( true );
		setActionError( '' );
		try {
			// A full refund sends no amount, so the server refunds exactly what is left.
			await onRefund(
				selectedOrder.id,
				amount >= refundable ? null : amount
			);
			setShowRefundConfirm( false );
			onSelectOrder( null );
		} catch ( err ) {
			setActionError( err?.message || 'Refund failed.' );
		} finally {
			setActionLoading( false );
		}
	};

	const handlePrintLabel = async () => {
		if ( ! selectedOrder || ! seller ) {
			return;
		}
		setLabelPurchasing( true );
		setActionError( '' );
		setLabelSuccess( null );
		try {
			const result = await purchaseShippingLabel(
				selectedOrder.id,
				seller,
				{ parcel, confirmOverage }
			);
			setLabelSuccess( result );
			setTrackingInput( result.tracking_number || '' );
			if ( result.label_url ) {
				window.open(
					result.label_url,
					'_blank',
					'noopener,noreferrer'
				);
			}
			onRefresh();
		} catch ( err ) {
			setActionError(
				err?.message || 'Failed to purchase shipping label.'
			);
		} finally {
			setLabelPurchasing( false );
		}
	};

	const formatDate = ( dateStr ) => {
		if ( ! dateStr ) {
			return '';
		}
		const date = new Date( dateStr );
		return date.toLocaleDateString( 'en-US', {
			month: 'short',
			day: 'numeric',
			year: 'numeric',
		} );
	};

	const formatAddress = ( address ) => {
		if ( ! address ) {
			return '';
		}
		const parts = [
			address.address_1,
			address.address_2,
			[ address.city, address.state, address.postcode ]
				.filter( Boolean )
				.join( ', ' ),
			address.country,
		].filter( Boolean );
		return parts.join( '\n' );
	};

	// On hold for a bank dispute: no shipping, labels or refunds until it is settled.
	const disputed = !! selectedOrder?.disputed;
	const refundable = Number(
		selectedOrder?.refundable ?? selectedOrder?.order_total ?? 0
	);
	const canMarkShipped =
		selectedOrder &&
		! disputed &&
		( selectedOrder.status === 'processing' ||
			selectedOrder.status === 'on-hold' );
	const canRefund =
		selectedOrder &&
		! disputed &&
		selectedOrder.status !== 'refunded' &&
		refundable > 0;

	const openRefundForm = () => {
		setRefundAmount( refundable.toFixed( 2 ) );
		setActionError( '' );
		setShowRefundConfirm( true );
	};

	const statusLabel = ( order ) =>
		order?.disputed ? 'disputed' : order?.status;

	const getOrderStatusTone = ( status ) => {
		if ( status === 'disputed' ) {
			return 'error';
		}

		if ( status === 'completed' ) {
			return 'success';
		}

		if ( status === 'processing' || status === 'on-hold' ) {
			return 'warning';
		}

		if ( status === 'refunded' ) {
			return 'muted';
		}

		return 'default';
	};

	return (
		<Panel className="ec-asm__orders">
			<PanelHeader
				actions={
					<button
						type="button"
						className="button-2 button-small"
						onClick={ onRefresh }
						disabled={ loading }
					>
						Refresh
					</button>
				}
			/>

			<div className="ec-asm__orders-filters">
				{ ORDER_FILTERS.map( ( f ) => (
					<button
						key={ f.id }
						type="button"
						className={ `ec-asm__filter${ filter === f.id ? ' is-active' : '' }` }
						onClick={ () => onFilterChange( f.id ) }
					>
						{ f.label }
					</button>
				) ) }
			</div>

			{ loading && (
				<InlineStatus tone="info">Loading orders…</InlineStatus>
			) }
			{ error && <InlineStatus tone="error">{ error }</InlineStatus> }

			{ ! loading && ! seller && <p>Select a shop to view orders.</p> }

			{ ! loading && seller && orders.length === 0 && (
				<p>No orders found.</p>
			) }

			{ ! selectedOrder && orders.length > 0 && (
				<div className="ec-asm__order-list">
					{ orders.map( ( order ) => (
						<div
							key={ order.id }
							className="ec-asm__order-card"
							onClick={ () => onSelectOrder( order ) }
							onKeyDown={ ( e ) =>
								e.key === 'Enter' && onSelectOrder( order )
							}
							role="button"
							tabIndex={ 0 }
						>
							<div className="ec-asm__order-card-header">
								<span className="ec-asm__order-number">
									#{ order.number }
								</span>
								<Badge
									tone={ getOrderStatusTone(
										statusLabel( order )
									) }
									variant="solid"
								>
									{ statusLabel( order ) }
								</Badge>
							</div>
							<div className="ec-asm__order-card-meta">
								<span>{ order.customer?.name }</span>
								<span>
									{ formatDate( order.date_created ) }
								</span>
							</div>
							<div className="ec-asm__order-card-footer">
								<span>
									{ order.items?.length || 0 } item
									{ ( order.items?.length || 0 ) !== 1
										? 's'
										: '' }
								</span>
								<span className="ec-asm__order-payout">
									$
									{ ( order.seller_payout || 0 ).toFixed(
										2
									) }
								</span>
							</div>
						</div>
					) ) }
				</div>
			) }

			{ selectedOrder && (
				<div className="ec-asm__order-detail">
					<div className="ec-asm__order-detail-header">
						<button
							type="button"
							className="button-2 button-small"
							onClick={ () => onSelectOrder( null ) }
						>
							&larr; Back
						</button>
						<h4>Order #{ selectedOrder.number }</h4>
						<Badge
							tone={ getOrderStatusTone(
								statusLabel( selectedOrder )
							) }
							variant="solid"
						>
							{ statusLabel( selectedOrder ) }
						</Badge>
					</div>

					{ disputed && (
						<InlineStatus tone="error">
							<strong>Disputed payment.</strong> The buyer
							disputed this payment with their bank. Don&apos;t
							ship this order. Respond to the dispute in your
							payout dashboard (on the Payments tab) before the
							deadline shown there. The order stays on hold until
							the dispute is settled.
						</InlineStatus>
					) }

					{ actionError && (
						<InlineStatus tone="error">
							{ actionError }
						</InlineStatus>
					) }

					<div className="ec-asm__order-sections">
						<div className="ec-asm__order-section">
							<h5>Customer</h5>
							<p>
								<strong>
									{ selectedOrder.customer?.name }
								</strong>
								<br />
								{ selectedOrder.customer?.email }
							</p>
						</div>

						<div className="ec-asm__order-section">
							<h5>Shipping Address</h5>
							<p style={ { whiteSpace: 'pre-line' } }>
								{ formatAddress(
									selectedOrder.customer?.address
								) }
							</p>
						</div>

						<div className="ec-asm__order-section">
							<h5>Items</h5>
							<table className="ec-asm__order-items">
								<thead>
									<tr>
										<th>Product</th>
										<th>Qty</th>
										<th>Total</th>
									</tr>
								</thead>
								<tbody>
									{ ( selectedOrder.items || [] ).map(
										( item, idx ) => (
											<tr key={ idx }>
												<td>{ item.name }</td>
												<td>{ item.quantity }</td>
												<td>
													$
													{ (
														item.total || 0
													).toFixed( 2 ) }
												</td>
											</tr>
										)
									) }
								</tbody>
							</table>
							<p className="ec-asm__order-payout-total">
								<strong>Your Payout:</strong> $
								{ ( selectedOrder.seller_payout || 0 ).toFixed(
									2
								) }
							</p>
							{ Number( selectedOrder.refunded_total ) > 0 && (
								<p className="ec-asm__muted">
									Refunded so far:{ ' ' }
									{ money( selectedOrder.refunded_total ) }
								</p>
							) }
						</div>

						{ selectedOrder.tracking_number && (
							<div className="ec-asm__order-section">
								<h5>Tracking</h5>
								<p>{ selectedOrder.tracking_number }</p>
							</div>
						) }
					</div>

					<div className="ec-asm__order-actions">
						{ canMarkShipped && (
							<div className="ec-asm__ship-form">
								{ /* Shipping Label Section */ }
								{ ! selectedOrder.tracking_number &&
									! labelSuccess && (
										<div className="ec-asm__label-section">
											{ selectedOrder.ships_free_only ? (
												<>
													<p className="ec-asm__ships-free-notice">
														<strong>
															Ships Free Order
														</strong>
													</p>
													<p className="ec-asm__muted">
														This order contains only
														&ldquo;Ships Free&rdquo;
														items. Ship these
														yourself without a
														label.
													</p>
												</>
											) : (
												<>
													<div className="ec-asm__label-parcel">
														<FieldGroup label="Parcel weight">
															<input
																type="number"
																min="0.1"
																step="0.1"
																value={
																	parcel.weight
																}
																onChange={ (
																	e
																) =>
																	updateParcel(
																		'weight',
																		e.target
																			.value
																	)
																}
															/>
															<select
																value={
																	parcel.mass_unit
																}
																onChange={ (
																	e
																) =>
																	updateParcel(
																		'mass_unit',
																		e.target
																			.value
																	)
																}
															>
																<option value="oz">
																	oz
																</option>
																<option value="lb">
																	lb
																</option>
															</select>
														</FieldGroup>
														<FieldGroup label="Box size (inches, L × W × H)">
															{ [
																'length',
																'width',
																'height',
															].map( ( side ) => (
																<input
																	key={ side }
																	type="number"
																	min="0.1"
																	step="0.1"
																	aria-label={
																		side
																	}
																	value={
																		parcel[
																			side
																		]
																	}
																	onChange={ (
																		e
																	) =>
																		updateParcel(
																			side,
																			e
																				.target
																				.value
																		)
																	}
																/>
															) ) }
														</FieldGroup>
													</div>

													{ ! labelQuote && (
														<button
															type="button"
															className="button-2 button-small"
															onClick={
																handleQuoteLabel
															}
															disabled={
																quoting ||
																actionLoading
															}
														>
															{ quoting
																? 'Checking price...'
																: 'Check Label Price' }
														</button>
													) }

													{ labelQuote && (
														<div className="ec-asm__label-quote">
															<p>
																<strong>
																	{
																		labelQuote.carrier
																	}{ ' ' }
																	{
																		labelQuote.service
																	}{ ' ' }
																	label:
																</strong>{ ' ' }
																{ money(
																	labelQuote.cost
																) }
																<br />
																Shipping the
																buyer paid:{ ' ' }
																{ money(
																	labelQuote.shipping_held
																) }
															</p>
															{ labelQuote.exceeds_shipping &&
																! labelQuote.can_purchase && (
																	<InlineStatus tone="warning">
																		This
																		label
																		costs
																		more
																		than the
																		shipping
																		the
																		buyer
																		paid, so
																		it
																		can&apos;t
																		be
																		bought
																		through
																		Extra
																		Chill.
																		Ship the
																		order
																		yourself
																		and mark
																		it
																		shipped
																		with a
																		tracking
																		number;
																		the{ ' ' }
																		{ money(
																			labelQuote.shipping_held
																		) }{ ' ' }
																		shipping
																		is paid
																		out to
																		you.
																	</InlineStatus>
																) }
															{ labelQuote.requires_confirmation && (
																<label
																	className="ec-asm__label-overage"
																	htmlFor="ec-asm-confirm-overage"
																>
																	<input
																		id="ec-asm-confirm-overage"
																		type="checkbox"
																		checked={
																			confirmOverage
																		}
																		onChange={ (
																			e
																		) =>
																			setConfirmOverage(
																				e
																					.target
																					.checked
																			)
																		}
																	/>{ ' ' }
																	Buy anyway:
																	Extra Chill
																	pays the{ ' ' }
																	{ money(
																		labelQuote.cost -
																			labelQuote.shipping_held
																	) }{ ' ' }
																	difference.
																</label>
															) }
															{ labelQuote.can_purchase && (
																<button
																	type="button"
																	className="button-1 button-small"
																	onClick={
																		handlePrintLabel
																	}
																	disabled={
																		labelPurchasing ||
																		actionLoading ||
																		( labelQuote.requires_confirmation &&
																			! confirmOverage )
																	}
																>
																	{ labelPurchasing
																		? 'Purchasing Label...'
																		: `Buy Label (${ money(
																				labelQuote.cost
																			) })` }
																</button>
															) }
														</div>
													) }
												</>
											) }
										</div>
									) }

								{ /* Label Success State */ }
								{ labelSuccess && (
									<div className="ec-asm__label-success">
										<p>
											<strong>Label Purchased!</strong>
										</p>
										<p>
											Tracking:{ ' ' }
											{ labelSuccess.tracking_number }
										</p>
										<a
											href={ labelSuccess.label_url }
											target="_blank"
											rel="noopener noreferrer"
											className="button-2 button-small"
										>
											Reprint Label
										</a>
									</div>
								) }

								{ /* Existing Label (from previous purchase) */ }
								{ selectedOrder.label_url && ! labelSuccess && (
									<div className="ec-asm__label-existing">
										<p>
											<strong>
												Label Already Purchased
											</strong>
										</p>
										<p>
											Tracking:{ ' ' }
											{ selectedOrder.tracking_number }
										</p>
										<a
											href={ selectedOrder.label_url }
											target="_blank"
											rel="noopener noreferrer"
											className="button-2 button-small"
										>
											Reprint Label
										</a>
									</div>
								) }

								<hr className="ec-asm__divider" />

								<FieldGroup
									label={ `Tracking Number ${ selectedOrder.tracking_number || labelSuccess ? '' : '(optional)' }` }
								>
									<input
										type="text"
										value={ trackingInput }
										onChange={ ( e ) =>
											setTrackingInput( e.target.value )
										}
										placeholder="e.g. 1Z999AA10123456784"
										disabled={
											!! selectedOrder.tracking_number ||
											!! labelSuccess
										}
									/>
								</FieldGroup>
								<button
									type="button"
									className="button-1 button-small"
									onClick={ handleMarkShipped }
									disabled={
										actionLoading || labelPurchasing
									}
								>
									{ actionLoading
										? 'Processing...'
										: 'Mark as Shipped' }
								</button>
							</div>
						) }

						{ canRefund && ! showRefundConfirm && (
							<button
								type="button"
								className="button-danger button-small"
								onClick={ openRefundForm }
								disabled={ actionLoading }
							>
								Refund Order
							</button>
						) }

						{ showRefundConfirm && (
							<div className="ec-asm__refund-confirm">
								<FieldGroup
									label="Refund amount ($)"
									help={
										<span className="ec-asm__muted">
											Up to { money( refundable ) }. For a
											partial refund, Extra Chill&apos;s
											fee is returned in proportion.
										</span>
									}
								>
									<input
										type="number"
										min="0.01"
										max={ refundable.toFixed( 2 ) }
										step="0.01"
										value={ refundAmount }
										onChange={ ( e ) =>
											setRefundAmount( e.target.value )
										}
										disabled={ actionLoading }
									/>
								</FieldGroup>
								<p>
									<strong>Are you sure?</strong> This will
									refund{ ' ' }
									{ money( Number( refundAmount ) || 0 ) } to
									the buyer
									{ Number( refundAmount ) >= refundable
										? ' (the full amount left on this order)'
										: '' }
									. This can&apos;t be undone.
								</p>
								<ActionRow className="ec-asm__actions">
									<button
										type="button"
										className="button-danger button-small"
										onClick={ handleRefund }
										disabled={ actionLoading }
									>
										{ actionLoading
											? 'Processing...'
											: 'Yes, Refund' }
									</button>
									<button
										type="button"
										className="button-2 button-small"
										onClick={ () =>
											setShowRefundConfirm( false )
										}
										disabled={ actionLoading }
									>
										Cancel
									</button>
								</ActionRow>
							</div>
						) }
					</div>
				</div>
			) }
		</Panel>
	);
};

export default OrdersTab;
