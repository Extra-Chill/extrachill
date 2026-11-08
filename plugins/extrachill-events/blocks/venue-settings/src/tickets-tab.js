/**
 * External dependencies
 */
import {
	Badge,
	DataTable,
	InlineStatus,
	Panel,
	PanelHeader,
} from '@extrachill/components';

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { useCallback, useEffect, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { errorDetails, runAbility } from './api';
import { LoadingPanel, Status } from './status';

const STATUS = {
	paid: { label: 'Paid', tone: 'info' },
	checked_in: { label: 'Checked in', tone: 'success' },
	refunded: { label: 'Refunded', tone: 'muted' },
};

/**
 * Format minor currency units, e.g. 1500 → "$15.00".
 *
 * @param {number} minor    Amount in minor units.
 * @param {string} currency ISO currency code.
 * @return {string} Formatted amount.
 */
export const formatMoney = ( minor, currency = 'usd' ) =>
	new Intl.NumberFormat( 'en-US', {
		style: 'currency',
		currency: currency.toUpperCase(),
	} ).format( minor / 100 );

/**
 * Format a site-local `Y-m-d H:i:s` datetime for display.
 *
 * @param {string} value Datetime string.
 * @return {string} Readable date, or '' when absent.
 */
export const formatDate = ( value ) => {
	if ( ! value ) {
		return '';
	}
	const date = new Date( value.replace( ' ', 'T' ) );
	if ( Number.isNaN( date.getTime() ) ) {
		return value;
	}
	return date.toLocaleString( 'en-US', {
		weekday: 'short',
		month: 'short',
		day: 'numeric',
		hour: 'numeric',
		minute: '2-digit',
	} );
};

/**
 * One-line sales summary for a show.
 *
 * @param {Object} event Show summary from list-seller-ticket-sales.
 * @return {string} Summary line.
 */
export const salesSummary = ( event ) =>
	[
		event.quantity > 0
			? `${ event.sold } of ${ event.quantity } sold`
			: `${ event.sold } sold`,
		`${ event.checked_in } checked in`,
		event.refunded > 0 ? `${ event.refunded } refunded` : '',
		`${ formatMoney( event.price_minor, event.currency ) } each`,
	]
		.filter( Boolean )
		.join( ' · ' );

const refundMessage = ( error ) => {
	if ( error?.code === 'ticket_already_redeemed' ) {
		return 'Someone on this order has checked in, so it can no longer be refunded.';
	}
	return errorDetails( error ).message;
};

function ShowSales( { event, onRefund, refunding } ) {
	const columns = [
		{ key: 'name', label: 'Buyer' },
		{ key: 'quantity', label: 'Tickets', width: '5rem' },
		{
			key: 'bought_at',
			label: 'Bought',
			render: ( value ) => formatDate( value ),
		},
		{
			key: 'status',
			label: 'Status',
			render: ( value ) => (
				<Badge tone={ STATUS[ value ]?.tone || 'default' }>
					{ STATUS[ value ]?.label || value }
				</Badge>
			),
		},
		{
			key: 'refundable',
			label: '',
			width: '7rem',
			render: ( value, order ) =>
				value ? (
					<button
						type="button"
						className="button-danger button-small"
						disabled={ refunding === order.order_reference }
						onClick={ () => onRefund( order ) }
					>
						{ refunding === order.order_reference
							? 'Refunding…'
							: 'Refund' }
					</button>
				) : null,
		},
	];

	return (
		<Panel compact depth={ 2 }>
			<h3>
				{ event.url ? (
					<a href={ event.url }>{ event.title }</a>
				) : (
					event.title
				) }
			</h3>
			<p>
				{ formatDate( event.start ) }
				{ ! event.tickets_enabled && (
					<>
						{ ' ' }
						<Badge tone="muted">Sales off</Badge>
					</>
				) }
			</p>
			<p>{ salesSummary( event ) }</p>
			<DataTable
				columns={ columns }
				data={ event.orders }
				rowKey="order_reference"
				emptyMessage="No tickets sold yet."
			/>
			{ event.manage_url && (
				<p>
					<a
						className="button-3 button-small"
						href={ event.manage_url }
					>
						Guest list and ticket settings
					</a>
				</p>
			) }
		</Panel>
	);
}

/**
 * Ticket sales for a venue or promoter seller, with refunds until check-in.
 *
 * @param {Object} props        Component props.
 * @param {Object} props.seller `{ type, id }` seller.
 */
export function TicketsTab( { seller } ) {
	const [ events, setEvents ] = useState( null );
	const [ status, setStatus ] = useState( null );
	const [ refunding, setRefunding ] = useState( '' );

	const load = useCallback( async () => {
		try {
			const result = await runAbility(
				'extrachill/list-seller-ticket-sales',
				{ type: seller.type, id: seller.id }
			);
			setEvents( result?.events || [] );
		} catch ( error ) {
			setEvents( [] );
			setStatus( {
				tone: 'error',
				message: errorDetails( error ).message,
			} );
		}
	}, [ seller.type, seller.id ] );

	useEffect( () => {
		setEvents( null );
		setStatus( null );
		load();
	}, [ load ] );

	const refund = async ( order ) => {
		const tickets =
			order.quantity === 1 ? 'ticket' : `${ order.quantity } tickets`;
		// eslint-disable-next-line no-alert -- A refund moves money; confirm it.
		const confirmed = window.confirm(
			`Refund ${ order.name || 'this buyer' }'s ${ tickets }? Their passes stop working and the money goes back to their card.`
		);
		if ( ! confirmed ) {
			return;
		}
		setRefunding( order.order_reference );
		try {
			await apiFetch( {
				path: `/extrachill/v1/events/tickets/orders/${ encodeURIComponent(
					order.order_reference
				) }/refund`,
				method: 'POST',
			} );
			setStatus( {
				tone: 'success',
				message: `Refunded ${ order.name || 'the order' }.`,
			} );
			await load();
		} catch ( error ) {
			setStatus( { tone: 'error', message: refundMessage( error ) } );
		} finally {
			setRefunding( '' );
		}
	};

	if ( events === null ) {
		return <LoadingPanel label="Loading ticket sales..." />;
	}

	return (
		<Panel>
			<PanelHeader
				title="Ticket sales"
				description="Tickets sold on your Extra Chill shows. Money goes straight to your Stripe account. You can refund an order until someone on it checks in."
			/>
			<Status state={ status } />
			{ events.length === 0 && ! status && (
				<InlineStatus tone="info">
					No ticketed shows yet. Turn on tickets in a show&apos;s
					guest list panel.
				</InlineStatus>
			) }
			{ events.map( ( event ) => (
				<ShowSales
					key={ event.event_id }
					event={ event }
					onRefund={ refund }
					refunding={ refunding }
				/>
			) ) }
		</Panel>
	);
}
