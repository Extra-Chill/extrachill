/**
 * OrdersTab - Order management with shipping label integration
 */

import { useState, useEffect } from '@wordpress/element';
import { purchaseShippingLabel } from '../../../shared/api/client';

const ORDER_FILTERS = [
	{ id: 'all', label: 'All' },
	{ id: 'needs_fulfillment', label: 'Needs Fulfillment' },
	{ id: 'completed', label: 'Completed' },
];

const OrdersTab = ( {
	artistId,
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
	const [ labelPurchasing, setLabelPurchasing ] = useState( false );
	const [ labelSuccess, setLabelSuccess ] = useState( null );

	useEffect( () => {
		setTrackingInput( '' );
		setActionError( '' );
		setShowRefundConfirm( false );
		setLabelSuccess( null );
	}, [ selectedOrder?.id ] );

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
		setActionLoading( true );
		setActionError( '' );
		try {
			await onRefund( selectedOrder.id );
			setShowRefundConfirm( false );
			onSelectOrder( null );
		} catch ( err ) {
			setActionError( err?.message || 'Refund failed.' );
		} finally {
			setActionLoading( false );
		}
	};

	const handlePrintLabel = async () => {
		if ( ! selectedOrder || ! artistId ) {
			return;
		}
		setLabelPurchasing( true );
		setActionError( '' );
		setLabelSuccess( null );
		try {
			const result = await purchaseShippingLabel( selectedOrder.id, artistId );
			setLabelSuccess( result );
			setTrackingInput( result.tracking_number || '' );
			if ( result.label_url ) {
				window.open( result.label_url, '_blank', 'noopener,noreferrer' );
			}
			onRefresh();
		} catch ( err ) {
			setActionError( err?.message || 'Failed to purchase shipping label.' );
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
			[ address.city, address.state, address.postcode ].filter( Boolean ).join( ', ' ),
			address.country,
		].filter( Boolean );
		return parts.join( '\n' );
	};

	const canMarkShipped = selectedOrder && ( selectedOrder.status === 'processing' || selectedOrder.status === 'on-hold' );
	const canRefund = selectedOrder && selectedOrder.status !== 'refunded';

	return (
		<div className="ec-asm__panel ec-asm__orders">
			<div className="ec-asm__form-header">
				<h3>Orders</h3>
				<button
					type="button"
					className="button-2 button-small"
					onClick={ onRefresh }
					disabled={ loading }
				>
					Refresh
				</button>
			</div>

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

			{ loading && <p>Loading</p> }
			{ error && <div className="notice notice-error"><p>{ error }</p></div> }

			{ ! loading && ! artistId && <p>Select an artist to view orders.</p> }

			{ ! loading && artistId && orders.length === 0 && (
				<p>No orders found.</p>
			) }

			{ ! selectedOrder && orders.length > 0 && (
				<div className="ec-asm__order-list">
					{ orders.map( ( order ) => (
						<div
							key={ order.id }
							className="ec-asm__order-card"
							onClick={ () => onSelectOrder( order ) }
							onKeyDown={ ( e ) => e.key === 'Enter' && onSelectOrder( order ) }
							role="button"
							tabIndex={ 0 }
						>
							<div className="ec-asm__order-card-header">
								<span className="ec-asm__order-number">#{ order.number }</span>
								<span className={ `ec-asm__order-status ec-asm__order-status--${ order.status }` }>
									{ order.status }
								</span>
							</div>
							<div className="ec-asm__order-card-meta">
								<span>{ order.customer?.name }</span>
								<span>{ formatDate( order.date_created ) }</span>
							</div>
							<div className="ec-asm__order-card-footer">
								<span>{ order.items?.length || 0 } item{ ( order.items?.length || 0 ) !== 1 ? 's' : '' }</span>
								<span className="ec-asm__order-payout">${ ( order.artist_payout || 0 ).toFixed( 2 ) }</span>
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
						<span className={ `ec-asm__order-status ec-asm__order-status--${ selectedOrder.status }` }>
							{ selectedOrder.status }
						</span>
					</div>

					{ actionError && <div className="notice notice-error"><p>{ actionError }</p></div> }

					<div className="ec-asm__order-sections">
						<div className="ec-asm__order-section">
							<h5>Customer</h5>
							<p>
								<strong>{ selectedOrder.customer?.name }</strong><br />
								{ selectedOrder.customer?.email }
							</p>
						</div>

						<div className="ec-asm__order-section">
							<h5>Shipping Address</h5>
							<p style={ { whiteSpace: 'pre-line' } }>
								{ formatAddress( selectedOrder.customer?.address ) }
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
									{ ( selectedOrder.items || [] ).map( ( item, idx ) => (
										<tr key={ idx }>
											<td>{ item.name }</td>
											<td>{ item.quantity }</td>
											<td>${ ( item.total || 0 ).toFixed( 2 ) }</td>
										</tr>
									) ) }
								</tbody>
							</table>
							<p className="ec-asm__order-payout-total">
								<strong>Your Payout:</strong> ${ ( selectedOrder.artist_payout || 0 ).toFixed( 2 ) }
							</p>
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
								{ ! selectedOrder.tracking_number && ! labelSuccess && (
									<div className="ec-asm__label-section">
										<button
											type="button"
											className="button-1 button-medium"
											onClick={ handlePrintLabel }
											disabled={ labelPurchasing || actionLoading }
										>
											{ labelPurchasing ? 'Purchasing Label...' : 'Print Shipping Label' }
										</button>
										<p className="ec-asm__muted">$5 flat rate USPS label</p>
									</div>
								) }

								{ /* Label Success State */ }
								{ labelSuccess && (
									<div className="ec-asm__label-success">
										<p><strong>Label Purchased!</strong></p>
										<p>Tracking: { labelSuccess.tracking_number }</p>
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
								{ selectedOrder.tracking_number && ! labelSuccess && (
									<div className="ec-asm__label-existing">
										<p><strong>Label Already Purchased</strong></p>
										<p>Tracking: { selectedOrder.tracking_number }</p>
										{ selectedOrder.label_url && (
											<a
												href={ selectedOrder.label_url }
												target="_blank"
												rel="noopener noreferrer"
												className="button-2 button-small"
											>
												Reprint Label
											</a>
										) }
									</div>
								) }

								<hr className="ec-asm__divider" />

								<label className="ec-asm__field">
									<span>Tracking Number { selectedOrder.tracking_number || labelSuccess ? '' : '(optional)' }</span>
									<input
										type="text"
										value={ trackingInput }
										onChange={ ( e ) => setTrackingInput( e.target.value ) }
										placeholder="e.g. 1Z999AA10123456784"
										disabled={ !! selectedOrder.tracking_number || !! labelSuccess }
									/>
								</label>
								<button
									type="button"
									className="button-1 button-medium"
									onClick={ handleMarkShipped }
									disabled={ actionLoading || labelPurchasing }
								>
									{ actionLoading ? 'Processing...' : 'Mark as Shipped' }
								</button>
							</div>
						) }

						{ canRefund && ! showRefundConfirm && (
							<button
								type="button"
								className="button-danger button-medium"
								onClick={ () => setShowRefundConfirm( true ) }
								disabled={ actionLoading }
							>
								Refund Order
							</button>
						) }

						{ showRefundConfirm && (
							<div className="ec-asm__refund-confirm">
								<p><strong>Are you sure?</strong> This will issue a full refund of ${ ( selectedOrder.order_total || 0 ).toFixed( 2 ) }.</p>
								<div className="ec-asm__actions">
									<button
										type="button"
										className="button-danger button-medium"
										onClick={ handleRefund }
										disabled={ actionLoading }
									>
										{ actionLoading ? 'Processing...' : 'Yes, Refund' }
									</button>
									<button
										type="button"
										className="button-2 button-medium"
										onClick={ () => setShowRefundConfirm( false ) }
										disabled={ actionLoading }
									>
										Cancel
									</button>
								</div>
							</div>
						) }
					</div>
				</div>
			) }
		</div>
	);
};

export default OrdersTab;
