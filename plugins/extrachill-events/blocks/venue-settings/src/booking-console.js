/**
 * WordPress dependencies
 */
import { useEffect, useRef, useState } from '@wordpress/element';

/**
 * External dependencies
 */
import {
	ActionRow,
	Badge,
	FieldGroup,
	Grid,
	InlineStatus,
	Panel,
	PanelHeader,
	SearchBox,
} from '@extrachill/components';

/**
 * Internal dependencies
 */
import { errorDetails, runAbility } from './api';

export const BOOKING_STATUSES = [
	'submitted',
	'needs_info',
	'under_review',
	'negotiating',
	'held',
	'confirmed',
	'declined',
	'withdrawn',
	'cancelled',
	'completed',
];

const STATUS_LABELS = {
	submitted: 'Submitted',
	needs_info: 'Needs info',
	under_review: 'Under review',
	negotiating: 'Negotiating',
	held: 'Held',
	confirmed: 'Confirmed',
	declined: 'Declined',
	withdrawn: 'Withdrawn',
	cancelled: 'Cancelled',
	completed: 'Completed',
};

const STATUS_TONES = {
	submitted: 'info',
	needs_info: 'error',
	under_review: 'info',
	negotiating: 'warning',
	held: 'warning',
	confirmed: 'success',
	declined: 'error',
	withdrawn: 'error',
	cancelled: 'error',
	completed: 'success',
};

const pad = ( value ) => String( value ).padStart( 2, '0' );

export const monthKey = ( date = new Date() ) =>
	`${ date.getFullYear() }-${ pad( date.getMonth() + 1 ) }`;

export const moveMonth = ( month, amount ) => {
	const [ year, index ] = month.split( '-' ).map( Number );
	return monthKey( new Date( year, index - 1 + amount, 1 ) );
};

export const monthRange = ( month ) => ( {
	start: `${ month }-01 00:00:00`,
	end: `${ moveMonth( month, 1 ) }-01 00:00:00`,
} );

export const calendarDays = ( month ) => {
	const [ year, index ] = month.split( '-' ).map( Number );
	const first = new Date( year, index - 1, 1 );
	const start = new Date( year, index - 1, 1 - first.getDay() );
	return Array.from( { length: 42 }, ( _, offset ) => {
		const date = new Date(
			start.getFullYear(),
			start.getMonth(),
			start.getDate() + offset
		);
		return {
			key: `${ date.getFullYear() }-${ pad(
				date.getMonth() + 1
			) }-${ pad( date.getDate() ) }`,
			day: date.getDate(),
			inMonth: date.getMonth() === index - 1,
		};
	} );
};

const bookingDate = ( booking ) =>
	( booking.performance_start_at || booking.requested_start_at || '' ).slice(
		0,
		10
	);

export const calendarEntries = ( bookings, events, supportEvents = [] ) => {
	const eventIds = new Set( events.map( ( event ) => Number( event.id ) ) );
	const supportByEvent = new Map(
		supportEvents.map( ( event ) => [ Number( event.id ), event ] )
	);
	return [
		...events.map( ( event ) => ( {
			type: 'event',
			id: event.id,
			date: ( event.datetime || '' ).slice( 0, 10 ),
			title: event.title,
			status: 'published',
			permalink: event.permalink,
			support: supportByEvent.get( Number( event.id ) ) || null,
			venueName: event.venue_name || '',
		} ) ),
		...bookings
			.filter(
				( booking ) =>
					! booking.event_id ||
					! eventIds.has( Number( booking.event_id ) )
			)
			.map( ( booking ) => ( {
				type: 'booking',
				id: booking.id,
				date: bookingDate( booking ),
				title: booking.artist_name,
				status: booking.status,
				booking,
				venueName: booking.venue_name || '',
			} ) ),
	];
};

export const filterBookings = ( bookings, search ) => {
	const query = search.trim().toLowerCase();
	if ( ! query ) {
		return bookings;
	}
	return bookings.filter( ( booking ) =>
		[
			booking.artist_name,
			booking.contact_name,
			booking.contact_email,
			booking.public_id,
			booking.status,
			booking.venue_name,
		]
			.filter( Boolean )
			.some( ( value ) =>
				String( value ).toLowerCase().includes( query )
			)
	);
};

const formatDate = ( value, fallback = 'Not set' ) => {
	if ( ! value ) {
		return fallback;
	}
	const parsed = new Date( value.replace( ' ', 'T' ) + 'Z' );
	return Number.isNaN( parsed.getTime() )
		? value
		: parsed.toLocaleString( [], {
				dateStyle: 'medium',
				timeStyle: 'short',
		  } );
};

const toDatabaseDate = ( value ) =>
	value ? `${ value.replace( 'T', ' ' ) }:00` : null;

const toLocalInput = ( value ) =>
	value ? value.replace( ' ', 'T' ).slice( 0, 16 ) : '';

const statusLabel = ( status ) => STATUS_LABELS[ status ] || status;

const payloadData = ( value ) => {
	if ( value === null || value === undefined ) {
		return null;
	}
	if (
		typeof value === 'object' &&
		! Array.isArray( value ) &&
		Object.prototype.hasOwnProperty.call( value, 'data' )
	) {
		return value.data;
	}
	return value;
};
const linesToItems = ( value ) =>
	value
		.split( '\n' )
		.map( ( item ) => item.trim() )
		.filter( Boolean );
const nullableNumber = ( value ) => ( value === '' ? null : Number( value ) );

const hasExactKeys = ( value, keys ) =>
	value !== null &&
	typeof value === 'object' &&
	! Array.isArray( value ) &&
	Object.keys( value ).length === keys.length &&
	keys.every( ( key ) => Object.prototype.hasOwnProperty.call( value, key ) );

const DEAL_KEYS = [
	'version',
	'type',
	'guarantee_cents',
	'revenue_share_basis_points',
	'revenue_share_basis',
	'currency',
	'capacity',
	'advance_ticket_price_cents',
	'door_ticket_price_cents',
	'ticket_fee_cents',
	'tickets_on_sale_at',
	'ticket_url',
	'additional_terms',
];
const nullableInteger = ( value ) =>
	value === null || Number.isInteger( value );
const nullableString = ( value ) => value === null || typeof value === 'string';
const validDealShape = ( value ) =>
	hasExactKeys( value, DEAL_KEYS ) &&
	value.version === 1 &&
	typeof value.type === 'string' &&
	Number.isInteger( value.guarantee_cents ) &&
	Number.isInteger( value.revenue_share_basis_points ) &&
	typeof value.revenue_share_basis === 'string' &&
	typeof value.currency === 'string' &&
	[
		'capacity',
		'advance_ticket_price_cents',
		'door_ticket_price_cents',
		'ticket_fee_cents',
	].every( ( key ) => nullableInteger( value[ key ] ) ) &&
	[ 'tickets_on_sale_at', 'ticket_url', 'additional_terms' ].every( ( key ) =>
		nullableString( value[ key ] )
	);

const PRODUCTION_KEYS = [
	'version',
	'support_requirements',
	'support_offers',
	'production_notes',
];
const validProductionShape = ( value ) =>
	hasExactKeys( value, PRODUCTION_KEYS ) &&
	value.version === 1 &&
	[ 'support_requirements', 'support_offers' ].every(
		( key ) =>
			Array.isArray( value[ key ] ) &&
			value[ key ].every( ( item ) => typeof item === 'string' )
	) &&
	nullableString( value.production_notes );

export const activityLabel = ( kind ) =>
	kind
		.split( '_' )
		.map( ( word ) => word.charAt( 0 ).toUpperCase() + word.slice( 1 ) )
		.join( ' ' );

const eventActionLabel = ( booking, operations ) => {
	if ( booking.event_id ) {
		return operations.sync.retryable
			? 'Retry event reconciliation'
			: 'Reconcile event';
	}
	if (
		operations.conversion.status === 'failed' &&
		! operations.conversion.retryable
	) {
		return 'Conversion retry unavailable';
	}
	return operations.conversion.status === 'failed'
		? 'Retry event conversion'
		: 'Convert to event';
};

const dealDocument = ( booking, fallback ) => {
	const stored = payloadData( booking.confirmed_deal || booking.deal );
	if ( stored !== null && ! validDealShape( stored ) ) {
		return null;
	}
	const source = stored || fallback || {};
	return {
		version: 1,
		type: source.type || 'custom',
		guarantee_cents: source.guarantee_cents ?? 0,
		revenue_share_basis_points: source.revenue_share_basis_points ?? 0,
		revenue_share_basis: source.revenue_share_basis || 'gross_ticket_sales',
		currency: source.currency || 'USD',
		capacity: source.capacity ?? null,
		advance_ticket_price_cents: source.advance_ticket_price_cents ?? null,
		door_ticket_price_cents: source.door_ticket_price_cents ?? null,
		ticket_fee_cents: source.ticket_fee_cents ?? null,
		tickets_on_sale_at: source.tickets_on_sale_at ?? null,
		ticket_url: source.ticket_url ?? null,
		additional_terms: source.additional_terms ?? null,
	};
};

function DealEditor( { booking, defaultDeal, pending, onSave } ) {
	const [ deal, setDeal ] = useState( () =>
		dealDocument( booking, defaultDeal )
	);
	if ( deal === null ) {
		return (
			<section className="ec-booking-detail__section">
				<h3>Deal terms</h3>
				<InlineStatus tone="error">
					The stored deal document is malformed. Editing is
					unavailable until the canonical record is repaired.
				</InlineStatus>
			</section>
		);
	}
	const update = ( key, value ) => setDeal( { ...deal, [ key ]: value } );
	return (
		<section className="ec-booking-detail__section">
			<h3>Deal terms</h3>
			<form
				className="ec-booking-console__form"
				onSubmit={ ( event ) => {
					event.preventDefault();
					onSave( deal );
				} }
			>
				<Grid minColumnWidth="16rem" maxColumns={ 2 }>
					<FieldGroup label="Deal type" htmlFor="booking-deal-type">
						<input
							id="booking-deal-type"
							value={ deal.type }
							onChange={ ( event ) =>
								update( 'type', event.target.value )
							}
							required
						/>
					</FieldGroup>
					<FieldGroup
						label="Currency"
						htmlFor="booking-deal-currency"
					>
						<input
							id="booking-deal-currency"
							value={ deal.currency }
							onChange={ ( event ) =>
								update(
									'currency',
									event.target.value.toUpperCase()
								)
							}
							required
						/>
					</FieldGroup>
					<FieldGroup
						label="Guarantee (cents)"
						htmlFor="booking-deal-guarantee"
					>
						<input
							id="booking-deal-guarantee"
							type="number"
							min="0"
							value={ deal.guarantee_cents }
							onChange={ ( event ) =>
								update(
									'guarantee_cents',
									Number( event.target.value )
								)
							}
							required
						/>
					</FieldGroup>
					<FieldGroup
						label="Revenue share (basis points)"
						htmlFor="booking-deal-share"
					>
						<input
							id="booking-deal-share"
							type="number"
							min="0"
							max="10000"
							value={ deal.revenue_share_basis_points }
							onChange={ ( event ) =>
								update(
									'revenue_share_basis_points',
									Number( event.target.value )
								)
							}
							required
						/>
					</FieldGroup>
					<FieldGroup
						label="Revenue share basis"
						htmlFor="booking-deal-basis"
					>
						<select
							id="booking-deal-basis"
							value={ deal.revenue_share_basis }
							onChange={ ( event ) =>
								update(
									'revenue_share_basis',
									event.target.value
								)
							}
						>
							<option value="gross_ticket_sales">
								Gross ticket sales
							</option>
							<option value="net_ticket_sales">
								Net ticket sales
							</option>
							<option value="door_receipts">Door receipts</option>
						</select>
					</FieldGroup>
					{ [
						[ 'capacity', 'Capacity', 1 ],
						[
							'advance_ticket_price_cents',
							'Advance ticket price (cents)',
							0,
						],
						[
							'door_ticket_price_cents',
							'Door ticket price (cents)',
							0,
						],
						[ 'ticket_fee_cents', 'Ticket fee (cents)', 0 ],
					].map( ( [ key, label, minimum ] ) => (
						<FieldGroup
							key={ key }
							label={ label }
							htmlFor={ `booking-deal-${ key }` }
						>
							<input
								id={ `booking-deal-${ key }` }
								type="number"
								min={ minimum }
								value={ deal[ key ] ?? '' }
								onChange={ ( event ) =>
									update(
										key,
										nullableNumber( event.target.value )
									)
								}
							/>
						</FieldGroup>
					) ) }
					<FieldGroup
						label="Tickets on sale (UTC)"
						htmlFor="booking-deal-on-sale"
					>
						<input
							id="booking-deal-on-sale"
							type="datetime-local"
							value={ toLocalInput( deal.tickets_on_sale_at ) }
							onChange={ ( event ) =>
								update(
									'tickets_on_sale_at',
									toDatabaseDate( event.target.value )
								)
							}
						/>
					</FieldGroup>
					<FieldGroup
						label="Public ticket URL"
						htmlFor="booking-deal-ticket-url"
					>
						<input
							id="booking-deal-ticket-url"
							type="url"
							value={ deal.ticket_url || '' }
							onChange={ ( event ) =>
								update(
									'ticket_url',
									event.target.value || null
								)
							}
						/>
					</FieldGroup>
				</Grid>
				<FieldGroup
					label="Additional terms"
					htmlFor="booking-deal-terms"
				>
					<textarea
						id="booking-deal-terms"
						value={ deal.additional_terms || '' }
						onChange={ ( event ) =>
							update(
								'additional_terms',
								event.target.value || null
							)
						}
					/>
				</FieldGroup>
				<button
					type="submit"
					className="button-2"
					disabled={ pending !== '' }
				>
					Save deal terms
				</button>
			</form>
		</section>
	);
}

function ProductionEditor( { booking, pending, onSave } ) {
	const stored = payloadData( booking.production );
	const malformed = stored !== null && ! validProductionShape( stored );
	const source = malformed ? {} : stored || {};
	const [ requirements, setRequirements ] = useState(
		( source.support_requirements || [] ).join( '\n' )
	);
	const [ offers, setOffers ] = useState(
		( source.support_offers || [] ).join( '\n' )
	);
	const [ notes, setNotes ] = useState( source.production_notes || '' );
	if ( malformed ) {
		return (
			<section className="ec-booking-detail__section">
				<h3>Production details</h3>
				<InlineStatus tone="error">
					The stored production document is malformed. Editing is
					unavailable until the canonical record is repaired.
				</InlineStatus>
			</section>
		);
	}
	return (
		<section className="ec-booking-detail__section">
			<h3>Production details</h3>
			<form
				className="ec-booking-console__form"
				onSubmit={ ( event ) => {
					event.preventDefault();
					onSave( {
						version: 1,
						support_requirements: linesToItems( requirements ),
						support_offers: linesToItems( offers ),
						production_notes: notes || null,
					} );
				} }
			>
				<Grid minColumnWidth="16rem" maxColumns={ 2 }>
					<FieldGroup
						label="Artist requirements (one per line)"
						htmlFor="booking-production-requirements"
					>
						<textarea
							id="booking-production-requirements"
							value={ requirements }
							onChange={ ( event ) =>
								setRequirements( event.target.value )
							}
						/>
					</FieldGroup>
					<FieldGroup
						label="Venue offers (one per line)"
						htmlFor="booking-production-offers"
					>
						<textarea
							id="booking-production-offers"
							value={ offers }
							onChange={ ( event ) =>
								setOffers( event.target.value )
							}
						/>
					</FieldGroup>
				</Grid>
				<FieldGroup
					label="Production notes"
					htmlFor="booking-production-notes"
				>
					<textarea
						id="booking-production-notes"
						value={ notes }
						onChange={ ( event ) => setNotes( event.target.value ) }
					/>
				</FieldGroup>
				<button
					type="submit"
					className="button-2"
					disabled={ pending !== '' }
				>
					Save production details
				</button>
			</form>
		</section>
	);
}

function ActivityTimeline( { operations } ) {
	return (
		<section className="ec-booking-detail__section">
			<h3>Activity</h3>
			{ operations.activity.length ? (
				<ul className="ec-booking-console__timeline">
					{ operations.activity.map( ( item ) => (
						<li key={ item.id }>
							<strong>{ activityLabel( item.kind ) }</strong>
							<small>{ formatDate( item.occurred_at ) }</small>
						</li>
					) ) }
				</ul>
			) : (
				<p>No activity recorded.</p>
			) }
		</section>
	);
}

function BookingStatus( { status } ) {
	return (
		<Badge tone={ STATUS_TONES[ status ] || 'default' }>
			{ statusLabel( status ) }
		</Badge>
	);
}

function EmptyState( { children } ) {
	return <div className="ec-booking-console__empty">{ children }</div>;
}

function ErrorState( { message, onRetry } ) {
	return (
		<InlineStatus tone="error">
			{ message }
			<button
				type="button"
				className="button-2 button-small"
				onClick={ onRetry }
			>
				Retry
			</button>
		</InlineStatus>
	);
}

function BookingCard( { booking, active, holds, onSelect } ) {
	return (
		<button
			type="button"
			className={ `ec-panel ec-booking-card${
				active ? ' is-active' : ''
			}` }
			onClick={ () => onSelect( booking.id ) }
			aria-pressed={ active }
		>
			<span className="ec-booking-card__title">
				{ booking.artist_name }
			</span>
			{ booking.venue_name && <span>{ booking.venue_name }</span> }
			<BookingStatus status={ booking.status } />
			<span>{ formatDate( booking.requested_start_at ) }</span>
			{ holds.length > 0 && (
				<span>
					{ holds.length } active{ ' ' }
					{ holds.length === 1 ? 'hold' : 'holds' }
				</span>
			) }
		</button>
	);
}

function Calendar( {
	bookings,
	events,
	holds,
	month,
	onMonthChange,
	onSelect,
	supportEvents,
} ) {
	const entries = calendarEntries( bookings, events, supportEvents );
	const byDay = entries.reduce( ( grouped, entry ) => {
		if ( entry.date ) {
			grouped[ entry.date ] = [
				...( grouped[ entry.date ] || [] ),
				entry,
			];
		}
		return grouped;
	}, {} );
	const heading = new Date( `${ month }-01T12:00:00` ).toLocaleDateString(
		[],
		{
			month: 'long',
			year: 'numeric',
		}
	);
	const activeHoldCounts = holds.reduce( ( counts, hold ) => {
		if ( hold.status === 'active' ) {
			counts[ hold.booking_id ] = ( counts[ hold.booking_id ] || 0 ) + 1;
		}
		return counts;
	}, {} );

	return (
		<Panel>
			<PanelHeader
				title={ heading }
				description="Requests, holds, and confirmed shows share one canonical calendar."
				actions={
					<ActionRow>
						<button
							type="button"
							className="button-2"
							onClick={ () =>
								onMonthChange( moveMonth( month, -1 ) )
							}
						>
							Previous
						</button>
						<button
							type="button"
							className="button-2"
							onClick={ () => onMonthChange( monthKey() ) }
						>
							Today
						</button>
						<button
							type="button"
							className="button-2"
							onClick={ () =>
								onMonthChange( moveMonth( month, 1 ) )
							}
						>
							Next
						</button>
					</ActionRow>
				}
			/>
			{ entries.length === 0 && (
				<EmptyState>
					No bookings, holds, or published events this month.
				</EmptyState>
			) }
			<div className="ec-booking-calendar__weekdays" aria-hidden="true">
				{ [ 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat' ].map(
					( day ) => (
						<span key={ day }>{ day }</span>
					)
				) }
			</div>
			<div
				className="ec-booking-calendar"
				role="grid"
				aria-label={ heading }
			>
				{ calendarDays( month ).map( ( day ) => (
					<div
						key={ day.key }
						className={ `ec-booking-calendar__day${
							day.inMonth ? '' : ' is-outside'
						}` }
						role="gridcell"
					>
						<span className="ec-booking-calendar__date">
							{ day.day }
						</span>
						<div className="ec-booking-calendar__items">
							{ ( byDay[ day.key ] || [] ).map( ( entry ) => {
								const content = (
									<>
										<span>{ entry.title }</span>
										<small>
											{ entry.type === 'event'
												? 'Published event'
												: statusLabel( entry.status ) }
											{ entry.venueName
												? ` - ${ entry.venueName }`
												: '' }
											{ entry.type === 'booking' &&
											activeHoldCounts[ entry.id ]
												? ` · ${
														activeHoldCounts[
															entry.id
														]
												  } active hold${
														activeHoldCounts[
															entry.id
														] === 1
															? ''
															: 's'
												  }`
												: '' }
										</small>
									</>
								);
								const className = `ec-booking-calendar__item ec-booking-calendar__item--${ entry.status }`;
								return entry.type === 'event' ? (
									<span key={ `event-${ entry.id }` }>
										<a
											className={ className }
											href={ entry.permalink }
										>
											{ content }
										</a>
										{ entry.support && (
											<a
												href={
													entry.support.workspace_url
												}
											>
												{ entry.support.status ===
												'not_seeking'
													? 'Find local support'
													: 'Manage local support' }
											</a>
										) }
									</span>
								) : (
									<button
										type="button"
										key={ `booking-${ entry.id }` }
										className={ className }
										onClick={ () => onSelect( entry.id ) }
									>
										{ content }
									</button>
								);
							} ) }
						</div>
					</div>
				) ) }
			</div>
		</Panel>
	);
}

function JsonRecord( { title, value, empty } ) {
	const entries = Object.entries( value?.data || value || {} );
	return (
		<section className="ec-booking-detail__section">
			<h3>{ title }</h3>
			{ entries.length ? (
				<dl className="ec-booking-detail__facts">
					{ entries.map( ( [ key, item ] ) => (
						<div key={ key }>
							<dt>{ key.replaceAll( '_', ' ' ) }</dt>
							<dd>
								{ Array.isArray( item )
									? item.join( ', ' ) || 'None'
									: String( item ?? 'Not set' ) }
							</dd>
						</div>
					) ) }
				</dl>
			) : (
				<p>{ empty }</p>
			) }
		</section>
	);
}

function Correspondence( { booking, items, onRefresh } ) {
	const [ message, setMessage ] = useState( '' );
	const [ subject, setSubject ] = useState( '' );
	const [ replyTo, setReplyTo ] = useState( '' );
	const [ sending, setSending ] = useState( false );
	const [ status, setStatus ] = useState( null );
	const send = async ( event ) => {
		event.preventDefault();
		setSending( true );
		setStatus( null );
		try {
			await runAbility( 'extrachill/send-booking-message', {
				booking_id: booking.id,
				idempotency_key: `console-${ booking.id }-${ Date.now() }`,
				template: 'operator_message',
				recipient: booking.contact_email,
				subject,
				message,
				reply_to: replyTo,
				expected_statuses: [ booking.status ],
				approval: 'direct',
			} );
			setMessage( '' );
			setSubject( '' );
			setStatus( { tone: 'success', message: 'Message queued.' } );
			await onRefresh();
		} catch ( error ) {
			setStatus( {
				tone: 'error',
				message: errorDetails( error ).message,
			} );
		} finally {
			setSending( false );
		}
	};
	return (
		<section className="ec-booking-detail__section">
			<h3>Correspondence</h3>
			{ items.length ? (
				<ul className="ec-booking-console__timeline">
					{ items.map( ( item ) => (
						<li key={ item.activity_id }>
							<strong>
								{ item.message?.subject || item.kind }
							</strong>
							<span>{ item.state?.status || 'Recorded' }</span>
							<small>{ formatDate( item.occurred_at ) }</small>
						</li>
					) ) }
				</ul>
			) : (
				<p>No correspondence recorded.</p>
			) }
			{ booking.contact_email ? (
				<form onSubmit={ send } className="ec-booking-console__form">
					<Grid minColumnWidth="16rem" maxColumns={ 2 }>
						<FieldGroup
							label="Subject"
							htmlFor="booking-message-subject"
						>
							<input
								id="booking-message-subject"
								value={ subject }
								onChange={ ( event ) =>
									setSubject( event.target.value )
								}
								required
							/>
						</FieldGroup>
						<FieldGroup
							label="Reply-to email"
							htmlFor="booking-message-reply-to"
						>
							<input
								id="booking-message-reply-to"
								type="email"
								value={ replyTo }
								onChange={ ( event ) =>
									setReplyTo( event.target.value )
								}
								required
							/>
						</FieldGroup>
					</Grid>
					<FieldGroup label="Message" htmlFor="booking-message-body">
						<textarea
							id="booking-message-body"
							value={ message }
							onChange={ ( event ) =>
								setMessage( event.target.value )
							}
							required
						/>
					</FieldGroup>
					<button
						type="submit"
						className="button-1"
						disabled={ sending }
					>
						{ sending
							? 'Sending...'
							: `Email ${ booking.contact_email }` }
					</button>
				</form>
			) : (
				<InlineStatus tone="warning">
					Add a contact email before sending correspondence.
				</InlineStatus>
			) }
			{ status && (
				<InlineStatus tone={ status.tone }>
					{ status.message }
				</InlineStatus>
			) }
		</section>
	);
}

function BookingDetail( {
	booking,
	holds,
	communications,
	operations,
	defaultDeal,
	onMutate,
	onClose,
	onRefreshCommunications,
} ) {
	const [ status, setStatus ] = useState( null );
	const [ pending, setPending ] = useState( '' );
	const [ transition, setTransition ] = useState( '' );
	const [ note, setNote ] = useState( '' );
	const [ space, setSpace ] = useState(
		booking.space_key || booking.requested_space_key || ''
	);
	const [ starts, setStarts ] = useState(
		toLocalInput(
			booking.performance_start_at || booking.requested_start_at
		)
	);
	const [ ends, setEnds ] = useState(
		toLocalInput( booking.performance_end_at || booking.requested_end_at )
	);

	useEffect( () => {
		setSpace( booking.space_key || booking.requested_space_key || '' );
		setStarts(
			toLocalInput(
				booking.performance_start_at || booking.requested_start_at
			)
		);
		setEnds(
			toLocalInput(
				booking.performance_end_at || booking.requested_end_at
			)
		);
	}, [ booking ] );

	const mutate = async ( label, ability, input ) => {
		setPending( label );
		setStatus( null );
		try {
			const result = await runAbility( ability, input );
			setStatus( { tone: 'success', message: `${ label } complete.` } );
			await onMutate( result );
		} catch ( error ) {
			const details = errorDetails( error );
			const conflict = details.conflict
				? ` Conflict: ${
						details.conflict.conflict_type || 'schedule'
				  } #${
						details.conflict.id ||
						details.conflict.booking_id ||
						'unknown'
				  }.`
				: '';
			setStatus( {
				tone: details.status === 409 ? 'warning' : 'error',
				message:
					details.status === 409
						? `${ details.message }${ conflict } The latest booking has been reloaded.`
						: `${ details.message }${ conflict }`,
			} );
			if ( details.status === 409 ) {
				await onMutate();
			}
		} finally {
			setPending( '' );
		}
	};
	const activeHolds = holds.filter( ( hold ) => hold.status === 'active' );
	return (
		<Panel className="ec-booking-detail">
			<PanelHeader
				className="ec-booking-detail__header"
				title={
					<>
						<BookingStatus status={ booking.status } />
						<span>{ booking.artist_name }</span>
					</>
				}
				description={ `Booking #${ booking.id } · version ${ booking.version }` }
				actions={
					<button
						type="button"
						className="button-2"
						onClick={ onClose }
					>
						Close detail
					</button>
				}
			/>
			{ status && (
				<InlineStatus tone={ status.tone }>
					{ status.message }
				</InlineStatus>
			) }
			<div className="ec-booking-detail__facts ec-booking-detail__facts--summary">
				<div>
					<dt>Requested</dt>
					<dd>{ formatDate( booking.requested_start_at ) }</dd>
				</div>
				<div>
					<dt>Performance</dt>
					<dd>{ formatDate( booking.performance_start_at ) }</dd>
				</div>
				<div>
					<dt>Space</dt>
					<dd>
						{ booking.space_key ||
							booking.requested_space_key ||
							'Not set' }
					</dd>
				</div>
				<div>
					<dt>Contact</dt>
					<dd>
						{ booking.contact_name || 'Not set' }
						{ booking.contact_email
							? ` · ${ booking.contact_email }`
							: '' }
					</dd>
				</div>
			</div>

			<section className="ec-booking-detail__section ec-booking-detail__actions">
				<h3>Operations</h3>
				<Grid minColumnWidth="16rem" maxColumns={ 1 }>
					<FieldGroup
						label="Lifecycle status"
						htmlFor="booking-transition"
					>
						<select
							id="booking-transition"
							value={ transition }
							onChange={ ( event ) =>
								setTransition( event.target.value )
							}
						>
							<option value="">
								Choose canonical transition
							</option>
							{ BOOKING_STATUSES.filter(
								( item ) => item !== booking.status
							).map( ( item ) => (
								<option key={ item } value={ item }>
									{ statusLabel( item ) }
								</option>
							) ) }
						</select>
						<input
							value={ note }
							onChange={ ( event ) =>
								setNote( event.target.value )
							}
							placeholder="Optional transition note"
						/>
						<button
							type="button"
							className="button-1"
							disabled={ ! transition || pending !== '' }
							onClick={ () =>
								mutate(
									'Transition',
									'extrachill/transition-venue-booking',
									{
										booking_id: booking.id,
										to_status: transition,
										expected_version: booking.version,
										note: note || null,
									}
								)
							}
						>
							Apply transition
						</button>
					</FieldGroup>
				</Grid>
			</section>

			<section className="ec-booking-detail__section">
				<h3>Performance and holds</h3>
				<Grid minColumnWidth="16rem" maxColumns={ 2 }>
					<FieldGroup label="Space key" htmlFor="booking-space">
						<input
							id="booking-space"
							value={ space }
							onChange={ ( event ) =>
								setSpace( event.target.value )
							}
						/>
					</FieldGroup>
					<FieldGroup label="Starts" htmlFor="booking-start">
						<input
							id="booking-start"
							type="datetime-local"
							value={ starts }
							onChange={ ( event ) =>
								setStarts( event.target.value )
							}
						/>
					</FieldGroup>
					<FieldGroup label="Ends" htmlFor="booking-end">
						<input
							id="booking-end"
							type="datetime-local"
							value={ ends }
							onChange={ ( event ) =>
								setEnds( event.target.value )
							}
						/>
					</FieldGroup>
				</Grid>
				<ActionRow>
					<button
						type="button"
						className="button-2"
						disabled={
							! space || ! starts || ! ends || pending !== ''
						}
						onClick={ () =>
							mutate(
								'Performance selection',
								'extrachill/select-venue-booking-performance',
								{
									booking_id: booking.id,
									expected_version: booking.version,
									space_key: space,
									start_at: toDatabaseDate( starts ),
									end_at: toDatabaseDate( ends ),
								}
							)
						}
					>
						Save performance
					</button>
					<button
						type="button"
						className="button-2"
						disabled={
							! booking.performance_start_at ||
							activeHolds.length > 0 ||
							pending !== ''
						}
						onClick={ () =>
							mutate(
								'Hold creation',
								'extrachill/create-booking-hold',
								{
									booking_id: booking.id,
									expected_booking_version: booking.version,
								}
							)
						}
					>
						Create hold
					</button>
				</ActionRow>
				{ activeHolds.length ? (
					<ul className="ec-booking-console__timeline">
						{ activeHolds.map( ( hold ) => (
							<li key={ hold.id }>
								<strong>{ hold.space_key }</strong>
								<span>
									Expires { formatDate( hold.expires_at ) }
								</span>
								<button
									type="button"
									className="button-link-delete"
									disabled={ pending !== '' }
									onClick={ () =>
										mutate(
											'Hold release',
											'extrachill/release-booking-hold',
											{
												hold_id: hold.id,
												expected_version: hold.version,
												reason: 'Released from venue booking console',
											}
										)
									}
								>
									Release
								</button>
							</li>
						) ) }
					</ul>
				) : (
					<p>No active hold.</p>
				) }
			</section>

			<JsonRecord
				title="Submitted intake"
				value={ booking.intake }
				empty="No intake answers were supplied."
			/>
			<DealEditor
				key={ `deal-${ booking.id }-${ booking.version }` }
				booking={ booking }
				defaultDeal={ defaultDeal }
				pending={ pending }
				onSave={ ( deal ) =>
					mutate(
						'Deal update',
						'extrachill/update-venue-booking-deal',
						{
							booking_id: booking.id,
							expected_version: booking.version,
							deal,
						}
					)
				}
			/>
			<ProductionEditor
				key={ `production-${ booking.id }-${ booking.version }` }
				booking={ booking }
				pending={ pending }
				onSave={ ( production ) =>
					mutate(
						'Production update',
						'extrachill/update-venue-booking-production',
						{
							booking_id: booking.id,
							expected_version: booking.version,
							production,
						}
					)
				}
			/>

			<section className="ec-booking-detail__section">
				<h3>Canonical event</h3>
				<p>
					{ booking.event_id
						? `Linked event #${ booking.event_id } is synchronized through booking-owned abilities.`
						: 'No canonical event has been created.' }
				</p>
				{ operations.conversion.status === 'failed' && (
					<InlineStatus
						tone={
							operations.conversion.retryable
								? 'warning'
								: 'error'
						}
					>
						Conversion attempt { operations.conversion.attempt }{ ' ' }
						failed
						{ operations.conversion.failure_code
							? ` (${ operations.conversion.failure_code })`
							: '' }
						.{ ' ' }
						{ operations.conversion.retryable
							? 'Retry is available.'
							: 'Manual review is required before retrying.' }
					</InlineStatus>
				) }
				{ operations.conversion.status === 'pending' && (
					<InlineStatus tone="warning">
						Conversion attempt { operations.conversion.attempt } has
						no terminal result yet.
					</InlineStatus>
				) }
				{ booking.event_id && operations.sync.status !== 'none' && (
					<InlineStatus
						tone={
							[ 'failed', 'conflict', 'retryable' ].includes(
								operations.sync.status
							)
								? 'warning'
								: 'info'
						}
					>
						Event synchronization:{ ' ' }
						{ activityLabel( operations.sync.status ) }
						{ operations.sync.code
							? ` (${ operations.sync.code })`
							: '' }
						.
						{ operations.sync.retryable
							? ' Reconciliation can be retried.'
							: '' }
					</InlineStatus>
				) }
				<button
					type="button"
					className="button-1"
					disabled={
						pending !== '' ||
						( ! booking.event_id &&
							operations.conversion.status === 'failed' &&
							! operations.conversion.retryable )
					}
					onClick={ () =>
						mutate(
							booking.event_id
								? 'Event reconciliation'
								: 'Event conversion',
							booking.event_id
								? 'extrachill/reconcile-booking-event'
								: 'extrachill/convert-booking-to-event',
							{
								booking_id: booking.id,
								expected_version: booking.version,
							}
						)
					}
				>
					{ eventActionLabel( booking, operations ) }
				</button>
			</section>

			<Correspondence
				booking={ booking }
				items={ communications }
				onRefresh={ onRefreshCommunications }
			/>

			<InlineStatus tone="info">
				Marketing execution, finance and settlement, promoter tools, and
				private-file operations are not included in this focused console
				slice.
			</InlineStatus>
			<ActivityTimeline operations={ operations } />
		</Panel>
	);
}

const PAGE_SIZE = 100;
const MAX_PAGE_COUNT = 100;

async function listAllVenueRecords( name, input, offset = 0, records = [] ) {
	if ( offset >= PAGE_SIZE * MAX_PAGE_COUNT ) {
		throw new Error( 'Venue records exceeded the supported page limit.' );
	}
	const page = await runAbility( name, {
		...input,
		limit: PAGE_SIZE,
		offset,
	} );
	const combined = [ ...records, ...page ];
	return page.length === PAGE_SIZE
		? listAllVenueRecords( name, input, offset + PAGE_SIZE, combined )
		: combined;
}

async function listAllVenueEvents( venueId, month, page = 1, events = [] ) {
	if ( page > MAX_PAGE_COUNT ) {
		throw new Error( 'Venue events exceeded the supported page limit.' );
	}
	const result = await runAbility( 'extrachill/events-calendar', {
		venue_id: venueId,
		month,
		page,
	} );
	const combined = [
		...events,
		...( result.dates || [] ).flatMap( ( date ) => date.events || [] ),
	];
	return result.has_more
		? listAllVenueEvents( venueId, month, page + 1, combined )
		: combined;
}

export function BookingConsole( {
	context,
	venues = [],
	defaultDeal,
	defaultDeals = {},
	supportEvents = [],
} ) {
	const scopeVenues = venues.length ? venues : [ context.selected_venue ];
	const venueIds = scopeVenues.map( ( venue ) => venue.id );
	const aggregateScope = ! context.selected_venue && venues.length > 0;
	const [ bookings, setBookings ] = useState( [] );
	const [ events, setEvents ] = useState( [] );
	const [ holds, setHolds ] = useState( [] );
	const [ communications, setCommunications ] = useState( [] );
	const [ operations, setOperations ] = useState( null );
	const [ selectedId, setSelectedId ] = useState( context.booking_id || 0 );
	const [ selected, setSelected ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ detailLoading, setDetailLoading ] = useState(
		Boolean( context.booking_id )
	);
	const [ error, setError ] = useState( '' );
	const [ detailError, setDetailError ] = useState( '' );
	const [ search, setSearch ] = useState( '' );
	const [ filterStatus, setFilterStatus ] = useState( '' );
	const [ month, setMonth ] = useState( monthKey() );
	const [ view, setView ] = useState( 'calendar' );
	const requestId = useRef( 0 );
	const detailRequestId = useRef( 0 );
	const selectedIdRef = useRef( context.booking_id || 0 );

	const loadList = async () => {
		const currentRequest = ++requestId.current;
		setLoading( true );
		setError( '' );
		try {
			const range = monthRange( month );
			const results = await Promise.all(
				scopeVenues.map( async ( venue ) => {
					const input = {
						venue_term_id: venue.id,
					};
					if ( view === 'calendar' ) {
						input.range_start = range.start;
						input.range_end = range.end;
					}
					if ( filterStatus ) {
						input.status = filterStatus;
					}
					const [ bookingResult, holdResult, eventResult ] =
						await Promise.allSettled( [
							listAllVenueRecords(
								'extrachill/list-venue-bookings',
								input
							),
							listAllVenueRecords(
								'extrachill/list-booking-holds',
								{
									venue_term_id: venue.id,
									...( view === 'calendar'
										? {
												range_start: range.start,
												range_end: range.end,
										  }
										: {} ),
								}
							),
							view === 'calendar'
								? listAllVenueEvents( venue.id, month )
								: Promise.resolve( [] ),
						] );
					return {
						venue,
						bookingRows:
							bookingResult.status === 'fulfilled'
								? bookingResult.value
								: [],
						holdRows:
							holdResult.status === 'fulfilled'
								? holdResult.value
								: [],
						events:
							eventResult.status === 'fulfilled'
								? eventResult.value
								: [],
						failedSources: [
							bookingResult,
							holdResult,
							eventResult,
						].filter( ( result ) => result.status === 'rejected' )
							.length,
					};
				} )
			);
			const sourceCount = view === 'calendar' ? 3 : 2;
			if (
				results.every(
					( result ) => result.failedSources >= sourceCount
				)
			) {
				throw new Error( 'Venue data unavailable.' );
			}
			if ( currentRequest === requestId.current ) {
				setBookings(
					results.flatMap( ( result ) =>
						result.bookingRows.map( ( booking ) => ( {
							...booking,
							venue_name: result.venue.name,
						} ) )
					)
				);
				setHolds( results.flatMap( ( result ) => result.holdRows ) );
				setEvents(
					results.flatMap( ( result ) =>
						result.events.map( ( event ) => ( {
							...event,
							venue_term_id: result.venue.id,
							venue_name: result.venue.name,
						} ) )
					)
				);
				setError(
					results.some( ( result ) => result.failedSources > 0 )
						? 'Some venue records could not be loaded.'
						: ''
				);
			}
		} catch ( caught ) {
			if ( currentRequest === requestId.current ) {
				setError( errorDetails( caught ).message );
			}
		} finally {
			if ( currentRequest === requestId.current ) {
				setLoading( false );
			}
		}
	};

	const loadDetail = async ( bookingId = selectedIdRef.current ) => {
		const currentRequest = ++detailRequestId.current;
		if ( ! bookingId ) {
			setSelected( null );
			setCommunications( [] );
			setOperations( null );
			setDetailLoading( false );
			return;
		}
		setDetailLoading( true );
		setDetailError( '' );
		try {
			const [ booking, messages, activity ] = await Promise.all( [
				runAbility( 'extrachill/get-venue-booking', {
					booking_id: bookingId,
				} ),
				runAbility( 'extrachill/list-booking-communications', {
					booking_id: bookingId,
				} ),
				runAbility( 'extrachill/get-venue-booking-activity', {
					booking_id: bookingId,
				} ),
			] );
			if ( ! venueIds.includes( booking.venue_term_id ) ) {
				throw new Error(
					'The booking is outside this venue workspace.'
				);
			}
			if (
				currentRequest !== detailRequestId.current ||
				selectedIdRef.current !== bookingId
			) {
				return;
			}
			setSelected( booking );
			setCommunications( messages );
			setOperations( activity );
		} catch ( caught ) {
			if (
				currentRequest !== detailRequestId.current ||
				selectedIdRef.current !== bookingId
			) {
				return;
			}
			setSelected( null );
			setOperations( null );
			setDetailError( errorDetails( caught ).message );
		} finally {
			if (
				currentRequest === detailRequestId.current &&
				selectedIdRef.current === bookingId
			) {
				setDetailLoading( false );
			}
		}
	};

	useEffect( () => {
		loadList();
	}, [ filterStatus, month, view ] ); // eslint-disable-line react-hooks/exhaustive-deps
	useEffect( () => {
		loadDetail();
	}, [ selectedId ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const selectBooking = ( bookingId ) => {
		selectedIdRef.current = bookingId;
		setSelectedId( bookingId );
		const url = new URL( window.location.href );
		const booking = bookings.find( ( item ) => item.id === bookingId );
		if ( aggregateScope ) {
			url.searchParams.delete( 'venue_id' );
			url.searchParams.set(
				'booking_venue_id',
				booking?.venue_term_id || context.booking_venue_id
			);
		} else if ( booking?.venue_term_id ) {
			url.searchParams.set( 'venue_id', booking.venue_term_id );
			url.searchParams.delete( 'booking_venue_id' );
		}
		url.searchParams.set( 'booking_id', bookingId );
		url.hash = 'tab-calendar';
		window.history.replaceState( {}, '', url );
	};
	const closeDetail = () => {
		selectedIdRef.current = 0;
		++detailRequestId.current;
		setSelectedId( 0 );
		setSelected( null );
		setDetailLoading( false );
		const url = new URL( window.location.href );
		url.searchParams.delete( 'booking_id' );
		url.searchParams.delete( 'booking_venue_id' );
		window.history.replaceState( {}, '', url );
	};
	const refreshAfterMutation = async () => {
		await Promise.all( [ loadList(), loadDetail() ] );
	};
	const visible = filterBookings( bookings, search );
	const selectedHolds = holds.filter(
		( hold ) => hold.booking_id === selectedId
	);
	const activeHoldsFor = ( bookingId ) =>
		holds.filter(
			( hold ) =>
				hold.booking_id === bookingId && hold.status === 'active'
		);

	return (
		<div className="ec-booking-console">
			<ActionRow>
				<button
					type="button"
					className="button-2"
					aria-pressed={ view === 'calendar' }
					onClick={ () => setView( 'calendar' ) }
				>
					Calendar
				</button>
				<button
					type="button"
					className="button-2"
					aria-pressed={ view === 'list' }
					onClick={ () => setView( 'list' ) }
				>
					List
				</button>
			</ActionRow>
			<Grid
				className="ec-booking-console__toolbar"
				minColumnWidth="12rem"
				maxColumns={ 2 }
			>
				<SearchBox
					value={ search }
					onSearch={ setSearch }
					onClear={ () => setSearch( '' ) }
					placeholder="Search artist, contact, email, or booking ID"
				/>
				<label htmlFor="booking-status-filter">
					Status
					<select
						id="booking-status-filter"
						value={ filterStatus }
						onChange={ ( event ) =>
							setFilterStatus( event.target.value )
						}
					>
						<option value="">All statuses</option>
						{ BOOKING_STATUSES.map( ( item ) => (
							<option value={ item } key={ item }>
								{ statusLabel( item ) }
							</option>
						) ) }
					</select>
				</label>
			</Grid>
			{ error && <ErrorState message={ error } onRetry={ loadList } /> }
			{ loading ? (
				<Panel>
					<p aria-live="polite">Loading venue bookings...</p>
				</Panel>
			) : (
				<>
					{ view === 'calendar' ? (
						<Calendar
							bookings={ visible }
							events={ events }
							holds={ holds }
							month={ month }
							onMonthChange={ setMonth }
							onSelect={ selectBooking }
							supportEvents={ supportEvents }
						/>
					) : (
						<Panel>
							<PanelHeader
								title="Booking pipeline"
								description="A bounded venue-authorized list across the selected venue scope. Filters are reapplied by canonical abilities."
							/>
							{ visible.length ? (
								<Grid
									className="ec-booking-console__list"
									minColumnWidth="15rem"
									gap="0.75rem"
								>
									{ visible.map( ( booking ) => (
										<BookingCard
											key={ booking.id }
											booking={ booking }
											active={ booking.id === selectedId }
											holds={ activeHoldsFor(
												booking.id
											) }
											onSelect={ selectBooking }
										/>
									) ) }
								</Grid>
							) : (
								<EmptyState>
									No bookings match this venue scope and
									filter.
								</EmptyState>
							) }
						</Panel>
					) }
				</>
			) }
			{ detailError && (
				<ErrorState message={ detailError } onRetry={ loadDetail } />
			) }
			{ detailLoading && (
				<Panel>
					<p aria-live="polite">Loading booking detail...</p>
					{ selectedId > 0 && (
						<button
							type="button"
							className="button-2"
							onClick={ closeDetail }
						>
							Close detail
						</button>
					) }
				</Panel>
			) }
			{ selected && operations && ! detailLoading && (
				<BookingDetail
					booking={ selected }
					holds={ selectedHolds }
					communications={ communications }
					operations={ operations }
					defaultDeal={
						defaultDeals[ selected.venue_term_id ] || defaultDeal
					}
					onMutate={ refreshAfterMutation }
					onClose={ closeDetail }
					onRefreshCommunications={ loadDetail }
				/>
			) }
		</div>
	);
}
