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

export const BOOKING_TRANSITIONS = {
	submitted: [ 'needs_info', 'under_review', 'declined', 'withdrawn' ],
	needs_info: [ 'submitted', 'under_review', 'declined', 'withdrawn' ],
	under_review: [ 'needs_info', 'negotiating', 'declined', 'withdrawn' ],
	negotiating: [
		'needs_info',
		'under_review',
		'held',
		'confirmed',
		'declined',
		'withdrawn',
	],
	held: [ 'negotiating', 'confirmed', 'declined', 'withdrawn', 'cancelled' ],
	confirmed: [ 'cancelled', 'completed' ],
	declined: [],
	withdrawn: [],
	cancelled: [],
	completed: [],
};

const STATUS_FILTERS = {
	active: [
		'submitted',
		'needs_info',
		'under_review',
		'negotiating',
		'held',
	],
	confirmed: [ 'confirmed', 'completed' ],
	closed: [ 'declined', 'withdrawn', 'cancelled' ],
};

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

export const bookingMessageIdentity = ( input ) =>
	JSON.stringify( [
		Number( input.bookingId ),
		'operator_message',
		String( input.recipient || '' )
			.trim()
			.toLowerCase(),
		String( input.subject || '' ).trim(),
		String( input.message || '' )
			.replaceAll( '\r\n', '\n' )
			.trim(),
		String( input.replyTo || '' )
			.trim()
			.toLowerCase(),
	] );

export const bookingMessageKey = async ( input, generation = 0 ) => {
	const bytes = new TextEncoder().encode(
		`${ bookingMessageIdentity( input ) }\n${ generation }`
	);
	const digest = await window.crypto.subtle.digest( 'SHA-256', bytes );
	const hash = Array.from( new Uint8Array( digest ), ( byte ) =>
		byte.toString( 16 ).padStart( 2, '0' )
	).join( '' );
	return `console-${ input.bookingId }-${ hash }`;
};

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

const bookingDate = ( booking ) => {
	const value = booking.performance_start_at || booking.requested_start_at;
	if ( ! value ) {
		return '';
	}
	try {
		return toVenueLocalInput( value, booking.venue_timezone ).slice(
			0,
			10
		);
	} catch {
		return value.slice( 0, 10 );
	}
};

const bookingDateValue = ( booking ) =>
	booking.performance_start_at ||
	booking.requested_start_at ||
	booking.created_at ||
	'';

export const sortBookingsChronologically = ( bookings ) =>
	[ ...bookings ].sort( ( left, right ) => {
		const leftDate = bookingDateValue( left );
		const rightDate = bookingDateValue( right );
		if ( leftDate === rightDate ) {
			return Number( left.id ) - Number( right.id );
		}
		if ( ! leftDate ) {
			return 1;
		}
		if ( ! rightDate ) {
			return -1;
		}
		return leftDate.localeCompare( rightDate );
	} );

export const bookingSummary = ( bookings, holds, now = new Date() ) => {
	const expiresBy = now.getTime() + 48 * 60 * 60 * 1000;
	const activeHolds = holds.filter( ( hold ) => hold.status === 'active' );
	const expiringHolds = activeHolds.filter( ( hold ) => {
		if ( ! hold.expires_at ) {
			return false;
		}
		const expiresAt = new Date(
			hold.expires_at.replace( ' ', 'T' ) + 'Z'
		).getTime();
		return ! Number.isNaN( expiresAt ) && expiresAt <= expiresBy;
	} );
	return {
		newSubmissions: bookings.filter(
			( booking ) => booking.status === 'submitted'
		).length,
		needsInfo: bookings.filter(
			( booking ) => booking.status === 'needs_info'
		).length,
		activeHolds: activeHolds.length,
		expiringHolds: expiringHolds.length,
	};
};

export const calendarEntries = ( bookings, events ) => {
	const eventIds = new Set( events.map( ( event ) => Number( event.id ) ) );
	return [
		...events.map( ( event ) => ( {
			type: 'event',
			id: event.id,
			date: ( event.datetime || '' ).slice( 0, 10 ),
			title: event.title,
			status: 'published',
			permalink: event.permalink,
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

export const filterBookings = ( bookings, search, statusFilter = '' ) => {
	const query = search.trim().toLowerCase();
	if ( ! query && ! statusFilter ) {
		return bookings;
	}
	return bookings.filter( ( booking ) => {
		if (
			statusFilter &&
			! STATUS_FILTERS[ statusFilter ]?.includes( booking.status )
		) {
			return false;
		}
		return (
			! query ||
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
	} );
};

const canonicalUtcDate = ( value ) => {
	if ( ! /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test( value || '' ) ) {
		throw new Error( 'The stored UTC date is invalid.' );
	}
	const parsed = new Date( `${ value.replace( ' ', 'T' ) }Z` );
	if (
		Number.isNaN( parsed.getTime() ) ||
		parsed.toISOString().slice( 0, 19 ).replace( 'T', ' ' ) !== value
	) {
		throw new Error( 'The stored UTC date is invalid.' );
	}
	return parsed;
};

const venueDateParts = ( date, timezone ) =>
	Object.fromEntries(
		new Intl.DateTimeFormat( 'en-US', {
			timeZone: timezone,
			year: 'numeric',
			month: '2-digit',
			day: '2-digit',
			hour: '2-digit',
			minute: '2-digit',
			second: '2-digit',
			hourCycle: 'h23',
		} )
			.formatToParts( date )
			.filter( ( part ) => part.type !== 'literal' )
			.map( ( part ) => [ part.type, part.value ] )
	);

const venueWallValue = ( date, timezone ) => {
	const parts = venueDateParts( date, timezone );
	return `${ parts.year }-${ parts.month }-${ parts.day }T${ parts.hour }:${ parts.minute }:${ parts.second }`;
};

const isValidVenueTimezone = ( timezone ) => {
	try {
		new Intl.DateTimeFormat( 'en-US', { timeZone: timezone } );
		return true;
	} catch {
		return false;
	}
};

export const toVenueLocalInput = ( value, timezone ) =>
	venueWallValue( canonicalUtcDate( value ), timezone );

export const venueLocalToUtc = ( value, timezone ) => {
	const match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2}))?$/.exec(
		value || ''
	);
	if ( ! match ) {
		throw new Error( 'Enter a complete local date and time.' );
	}
	const normalized = `${ match[ 1 ] }-${ match[ 2 ] }-${ match[ 3 ] }T${
		match[ 4 ]
	}:${ match[ 5 ] }:${ match[ 6 ] || '00' }`;
	const wallTimestamp = Date.UTC(
		...match
			.slice( 1 )
			.map( Number )
			.map( ( part, index ) => ( index === 1 ? part - 1 : part ) )
	);
	if (
		new Date( wallTimestamp ).toISOString().slice( 0, 19 ) !== normalized
	) {
		throw new Error( 'Enter a valid local date and time.' );
	}

	let offsets;
	try {
		offsets = new Set(
			[ -36, -12, 0, 12, 36 ].map( ( hours ) => {
				const instant = new Date(
					wallTimestamp + hours * 60 * 60 * 1000
				);
				const parts = venueDateParts( instant, timezone );
				return (
					Date.UTC(
						Number( parts.year ),
						Number( parts.month ) - 1,
						Number( parts.day ),
						Number( parts.hour ),
						Number( parts.minute ),
						Number( parts.second )
					) - instant.getTime()
				);
			} )
		);
	} catch {
		throw new Error(
			`The venue timezone ${ timezone || '(missing)' } is invalid.`
		);
	}
	const candidates = [ ...offsets ]
		.map( ( offset ) => new Date( wallTimestamp - offset ) )
		.filter(
			( candidate ) =>
				venueWallValue( candidate, timezone ) === normalized
		);
	if ( candidates.length === 0 ) {
		throw new Error(
			`${ normalized.replace(
				'T',
				' '
			) } does not exist in ${ timezone } because the clocks move forward.`
		);
	}
	if ( candidates.length > 1 ) {
		throw new Error(
			`${ normalized.replace(
				'T',
				' '
			) } occurs twice in ${ timezone } because the clocks move back. Choose an unambiguous time.`
		);
	}
	return candidates[ 0 ].toISOString().slice( 0, 19 ).replace( 'T', ' ' );
};

export const formatVenueDate = ( value, timezone, fallback = 'Not set' ) => {
	if ( ! value ) {
		return fallback;
	}
	try {
		return canonicalUtcDate( value ).toLocaleString( [], {
			dateStyle: 'medium',
			timeStyle: 'short',
			timeZone: timezone,
		} );
	} catch {
		return value;
	}
};

const utcInputToDatabaseDate = ( value ) =>
	value ? `${ value.replace( 'T', ' ' ) }:00` : null;

const utcDatabaseToInput = ( value ) =>
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
							value={ utcDatabaseToInput(
								deal.tickets_on_sale_at
							) }
							onChange={ ( event ) =>
								update(
									'tickets_on_sale_at',
									utcInputToDatabaseDate( event.target.value )
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

function ActivityTimeline( { operations, timezone } ) {
	return (
		<section className="ec-booking-detail__section">
			<h3>Activity</h3>
			{ operations.activity.length ? (
				<ul className="ec-booking-console__timeline">
					{ operations.activity.map( ( item ) => (
						<li key={ item.id }>
							<strong>{ activityLabel( item.kind ) }</strong>
							<small>
								{ formatVenueDate(
									item.occurred_at,
									timezone
								) }
							</small>
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
		<li>
			<button
				type="button"
				className={ `ec-booking-card button-3 button-medium button-block${
					active ? ' is-active' : ''
				}` }
				onClick={ () => onSelect( booking.id ) }
				aria-pressed={ active }
			>
				<span className="ec-booking-card__identity">
					<strong className="ec-booking-card__title">
						{ booking.artist_name }
					</strong>
					{ booking.venue_name && (
						<span>{ booking.venue_name }</span>
					) }
				</span>
				<span className="ec-booking-card__date">
					{ formatVenueDate(
						bookingDateValue( booking ),
						booking.venue_timezone
					) }
				</span>
				<span className="ec-booking-card__state">
					<BookingStatus status={ booking.status } />
					{ holds.length > 0 && (
						<small>
							{ holds.length } active{ ' ' }
							{ holds.length === 1 ? 'hold' : 'holds' }
						</small>
					) }
				</span>
			</button>
		</li>
	);
}

function BookingInboxSummary( { summary } ) {
	const items = [
		[ 'New submissions', summary.newSubmissions ],
		[ 'Needs info', summary.needsInfo ],
		[ 'Active holds', summary.activeHolds ],
	];
	if ( summary.expiringHolds > 0 ) {
		items.push( [ 'Expiring holds', summary.expiringHolds ] );
	}
	return (
		<ul
			className="ec-booking-console__summary"
			aria-label="Booking actions"
		>
			{ items.map( ( [ label, count ] ) => (
				<li key={ label }>
					<strong>{ count }</strong>
					<span>{ label }</span>
				</li>
			) ) }
		</ul>
	);
}

function Calendar( {
	bookings,
	events,
	holds,
	month,
	onMonthChange,
	onSelect,
} ) {
	const entries = calendarEntries( bookings, events );
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
									<div
										className="ec-booking-calendar__event"
										key={ `event-${ entry.id }` }
									>
										<a
											className={ className }
											href={ entry.permalink }
										>
											{ content }
										</a>
									</div>
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

function RecordValue( { value } ) {
	if ( Array.isArray( value ) ) {
		return value.length ? (
			<ul>
				{ value.map( ( item, index ) => (
					<li key={ `${ item }-${ index }` }>
						<RecordValue value={ item } />
					</li>
				) ) }
			</ul>
		) : (
			'None'
		);
	}
	if ( value && typeof value === 'object' ) {
		return (
			<dl>
				{ Object.entries( value ).map( ( [ key, nested ] ) => (
					<div key={ key }>
						<dt>{ key.replaceAll( '_', ' ' ) }</dt>
						<dd>
							<RecordValue value={ nested } />
						</dd>
					</div>
				) ) }
			</dl>
		);
	}
	const text = String( value ?? 'Not set' );
	return /^https?:\/\//i.test( text ) ? (
		<a href={ text } target="_blank" rel="noreferrer">
			{ text }
		</a>
	) : (
		text
	);
}

function JsonRecord( { title, value, empty } ) {
	const entries = Object.entries( value?.data || value || {} ).flatMap(
		( [ key, item ] ) =>
			key === 'fields' &&
			item &&
			typeof item === 'object' &&
			! Array.isArray( item )
				? Object.entries( item )
				: [ [ key, item ] ]
	);
	return (
		<section className="ec-booking-detail__section">
			<h3>{ title }</h3>
			{ entries.length ? (
				<dl className="ec-booking-detail__facts">
					{ entries.map( ( [ key, item ] ) => (
						<div key={ key }>
							<dt>{ key.replaceAll( '_', ' ' ) }</dt>
							<dd>
								<RecordValue value={ item } />
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

export function Correspondence( { booking, items, onRefresh, timezone } ) {
	const [ message, setMessage ] = useState( '' );
	const [ subject, setSubject ] = useState( '' );
	const [ replyTo, setReplyTo ] = useState( '' );
	const [ sending, setSending ] = useState( false );
	const [ status, setStatus ] = useState( null );
	const sendingRef = useRef( false );
	const identityRef = useRef( { signature: '', generation: 0, key: '' } );
	const clearDraft = () => {
		setMessage( '' );
		setSubject( '' );
	};
	const refreshForKey = async ( key ) => {
		const refreshed = await onRefresh();
		return refreshed.some(
			( item ) =>
				item.kind === 'booking_message_requested' &&
				item.idempotency_key === key
		);
	};
	const send = async ( event ) => {
		event.preventDefault();
		if ( sendingRef.current ) {
			return;
		}
		sendingRef.current = true;
		setSending( true );
		setStatus( null );
		const canonicalSubject = subject.trim();
		setSubject( canonicalSubject );
		const input = {
			bookingId: booking.id,
			recipient: booking.contact_email,
			subject: canonicalSubject,
			message,
			replyTo,
		};
		const signature = bookingMessageIdentity( input );
		if ( identityRef.current.signature !== signature ) {
			identityRef.current = { signature, generation: 0, key: '' };
		}
		let key = identityRef.current.key;
		try {
			if ( ! key ) {
				key = await bookingMessageKey(
					input,
					identityRef.current.generation
				);
				identityRef.current.key = key;
			}
			const result = await runAbility(
				'extrachill/send-booking-message',
				{
					booking_id: booking.id,
					idempotency_key: key,
					template: 'operator_message',
					recipient: booking.contact_email,
					subject: canonicalSubject,
					message,
					reply_to: replyTo,
					expected_statuses: [ booking.status ],
					approval: 'direct',
				}
			);
			if ( result.status !== 'queued' ) {
				throw Object.assign(
					new Error( 'The send outcome requires reconciliation.' ),
					{ uncertain: true }
				);
			}
			clearDraft();
			try {
				await onRefresh();
				setStatus( { tone: 'success', message: 'Message queued.' } );
			} catch {
				setStatus( {
					tone: 'warning',
					message:
						'Message queued. Correspondence could not be refreshed.',
				} );
			}
		} catch ( error ) {
			const details = errorDetails( error );
			try {
				if ( await refreshForKey( key ) ) {
					clearDraft();
					setStatus( {
						tone: 'success',
						message: 'Message recorded. Delivery status refreshed.',
					} );
				} else if (
					! error.uncertain &&
					details.status >= 400 &&
					details.status < 500
				) {
					identityRef.current = {
						...identityRef.current,
						generation: identityRef.current.generation + 1,
						key: '',
					};
					setStatus( { tone: 'error', message: details.message } );
				} else {
					setStatus( {
						tone: 'warning',
						message:
							'Send outcome not confirmed. Draft preserved; retry will safely reuse the same message identity.',
					} );
				}
			} catch {
				setStatus( {
					tone: 'warning',
					message:
						'Send outcome not confirmed and correspondence could not be refreshed. Draft preserved; retry will safely reuse the same message identity.',
				} );
			}
		} finally {
			sendingRef.current = false;
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
							<small>
								{ formatVenueDate(
									item.occurred_at,
									timezone
								) }
							</small>
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
								maxLength={ 200 }
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
				<div role="status" aria-live="polite">
					<InlineStatus tone={ status.tone }>
						{ status.message }
					</InlineStatus>
				</div>
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
	timezone,
} ) {
	const [ status, setStatus ] = useState( null );
	const [ pending, setPending ] = useState( '' );
	const [ transition, setTransition ] = useState( '' );
	const [ note, setNote ] = useState( '' );
	const [ space, setSpace ] = useState(
		booking.space_key || booking.requested_space_key || ''
	);
	const [ starts, setStarts ] = useState(
		toVenueLocalInput(
			booking.performance_start_at || booking.requested_start_at,
			timezone
		)
	);
	const [ ends, setEnds ] = useState(
		toVenueLocalInput(
			booking.performance_end_at || booking.requested_end_at,
			timezone
		)
	);

	useEffect( () => {
		setSpace( booking.space_key || booking.requested_space_key || '' );
		setStarts(
			toVenueLocalInput(
				booking.performance_start_at || booking.requested_start_at,
				timezone
			)
		);
		setEnds(
			toVenueLocalInput(
				booking.performance_end_at || booking.requested_end_at,
				timezone
			)
		);
	}, [ booking, timezone ] );

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
	const availableTransitions = BOOKING_TRANSITIONS[ booking.status ] || [];
	const savePerformance = () => {
		let startAt;
		let endAt;
		try {
			startAt = venueLocalToUtc( starts, timezone );
			endAt = venueLocalToUtc( ends, timezone );
		} catch ( error ) {
			setStatus( { tone: 'error', message: error.message } );
			return;
		}
		mutate(
			'Performance selection',
			'extrachill/select-venue-booking-performance',
			{
				booking_id: booking.id,
				expected_version: booking.version,
				space_key: space,
				start_at: startAt,
				end_at: endAt,
			}
		);
	};
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
				description={ `Booking #${ booking.id }` }
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
					<dt>{ `Requested (${ timezone })` }</dt>
					<dd>
						{ formatVenueDate(
							booking.requested_start_at,
							timezone
						) }
					</dd>
				</div>
				<div>
					<dt>{ `Performance (${ timezone })` }</dt>
					<dd>
						{ formatVenueDate(
							booking.performance_start_at,
							timezone
						) }
					</dd>
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
				{ availableTransitions.length ? (
					<Grid minColumnWidth="16rem" maxColumns={ 1 }>
						<FieldGroup label="Status" htmlFor="booking-transition">
							<select
								id="booking-transition"
								value={ transition }
								onChange={ ( event ) =>
									setTransition( event.target.value )
								}
							>
								<option value="">Choose next status</option>
								{ availableTransitions.map( ( item ) => (
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
				) : (
					<p>This booking is in a final state.</p>
				) }
			</section>

			<section className="ec-booking-detail__section">
				<h3>Performance and holds</h3>
				<p id="booking-performance-timezone">
					Performance times use the venue timezone:{ ' ' }
					<strong>{ timezone }</strong>.
				</p>
				<Grid minColumnWidth="16rem" maxColumns={ 2 }>
					<FieldGroup label="Space" htmlFor="booking-space">
						<input
							id="booking-space"
							value={ space }
							onChange={ ( event ) =>
								setSpace( event.target.value )
							}
						/>
					</FieldGroup>
					<FieldGroup
						label={ `Starts (${ timezone })` }
						htmlFor="booking-start"
					>
						<input
							id="booking-start"
							type="datetime-local"
							step="1"
							aria-describedby="booking-performance-timezone"
							value={ starts }
							onChange={ ( event ) =>
								setStarts( event.target.value )
							}
						/>
					</FieldGroup>
					<FieldGroup
						label={ `Ends (${ timezone })` }
						htmlFor="booking-end"
					>
						<input
							id="booking-end"
							type="datetime-local"
							step="1"
							aria-describedby="booking-performance-timezone"
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
						onClick={ savePerformance }
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
									Expires{ ' ' }
									{ formatVenueDate(
										hold.expires_at,
										timezone
									) }
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
			<details className="ec-booking-detail__disclosure">
				<summary>Deal and production</summary>
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
			</details>

			<details className="ec-booking-detail__disclosure">
				<summary>Event listing</summary>
				<section className="ec-booking-detail__section">
					<h3>Event listing</h3>
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
							Conversion attempt { operations.conversion.attempt }{ ' ' }
							has no terminal result yet.
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
			</details>

			<Correspondence
				booking={ booking }
				items={ communications }
				onRefresh={ onRefreshCommunications }
				timezone={ timezone }
			/>

			<details className="ec-booking-detail__disclosure">
				<summary>Activity history</summary>
				<ActivityTimeline
					operations={ operations }
					timezone={ timezone }
				/>
			</details>
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
} ) {
	const scopeVenues = venues.length ? venues : [ context.selected_venue ];
	const venueIds = scopeVenues.map( ( venue ) => venue.id );
	const timezoneForVenue = ( venueId ) =>
		scopeVenues.find( ( venue ) => venue.id === venueId )?.timezone || '';
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
							venue_timezone: result.venue.timezone,
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
			setSelected( {
				...booking,
				venue_timezone: timezoneForVenue( booking.venue_term_id ),
			} );
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

	const loadCommunications = async () => {
		const bookingId = selectedIdRef.current;
		const messages = await runAbility(
			'extrachill/list-booking-communications',
			{ booking_id: bookingId }
		);
		if ( selectedIdRef.current === bookingId ) {
			setCommunications( messages );
		}
		return messages;
	};

	useEffect( () => {
		loadList();
	}, [ month, view ] ); // eslint-disable-line react-hooks/exhaustive-deps
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
		setDetailError( '' );
		const url = new URL( window.location.href );
		url.searchParams.delete( 'booking_id' );
		url.searchParams.delete( 'booking_venue_id' );
		window.history.replaceState( {}, '', url );
	};
	const refreshAfterMutation = async () => {
		await Promise.all( [ loadList(), loadDetail() ] );
	};
	const visible = sortBookingsChronologically(
		filterBookings( bookings, search, filterStatus )
	);
	const summary = bookingSummary( bookings, holds );
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
			{ ( selectedId === 0 || detailLoading ) && (
				<>
					<div className="ec-booking-console__toolbar">
						<div
							className="ec-booking-console__view-switcher"
							role="group"
							aria-label="Booking view"
						>
							{ [
								{ id: 'calendar', label: 'Calendar' },
								{ id: 'list', label: 'List' },
							].map( ( option ) => (
								<button
									type="button"
									key={ option.id }
									className={
										view === option.id
											? 'button-1 button-medium is-active'
											: 'button-3 button-medium'
									}
									aria-pressed={ view === option.id }
									onClick={ () => setView( option.id ) }
								>
									{ option.label }
								</button>
							) ) }
						</div>
						<div className="ec-booking-console__search">
							<SearchBox
								value={ search }
								onSearch={ setSearch }
								onClear={ () => setSearch( '' ) }
								placeholder="Search artist, contact, email, or booking ID"
							/>
						</div>
						<label htmlFor="booking-status-filter">
							Status
							<select
								id="booking-status-filter"
								value={ filterStatus }
								onChange={ ( event ) =>
									setFilterStatus( event.target.value )
								}
							>
								<option value="">All bookings</option>
								<option value="active">Active inquiries</option>
								<option value="confirmed">
									Confirmed shows
								</option>
								<option value="closed">Closed inquiries</option>
							</select>
						</label>
					</div>
					{ error && (
						<ErrorState message={ error } onRetry={ loadList } />
					) }
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
								/>
							) : (
								<Panel>
									<PanelHeader
										title="Booking inbox"
										description="Review the next booking action in date order."
									/>
									<BookingInboxSummary summary={ summary } />
									{ visible.length ? (
										<ul className="ec-booking-console__list">
											{ visible.map( ( booking ) => (
												<BookingCard
													key={ booking.id }
													booking={ booking }
													active={
														booking.id ===
														selectedId
													}
													holds={ activeHoldsFor(
														booking.id
													) }
													onSelect={ selectBooking }
												/>
											) ) }
										</ul>
									) : (
										<EmptyState>
											No bookings match this venue scope
											and filter.
										</EmptyState>
									) }
								</Panel>
							) }
						</>
					) }
				</>
			) }
			{ detailError && (
				<div className="ec-booking-detail__error">
					<ErrorState
						message={ detailError }
						onRetry={ loadDetail }
					/>
					<button
						type="button"
						className="button-2"
						onClick={ closeDetail }
					>
						Back to bookings
					</button>
				</div>
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
			{ selected &&
				operations &&
				! detailLoading &&
				! isValidVenueTimezone( selected.venue_timezone ) && (
					<InlineStatus tone="error">
						The canonical venue timezone is unavailable. Performance
						times cannot be edited safely.
					</InlineStatus>
				) }
			{ selected &&
				operations &&
				! detailLoading &&
				isValidVenueTimezone( selected.venue_timezone ) && (
					<BookingDetail
						booking={ selected }
						holds={ selectedHolds }
						communications={ communications }
						operations={ operations }
						defaultDeal={
							defaultDeals[ selected.venue_term_id ] ||
							defaultDeal
						}
						onMutate={ refreshAfterMutation }
						onClose={ closeDetail }
						onRefreshCommunications={ loadCommunications }
						timezone={ selected.venue_timezone }
					/>
				) }
		</div>
	);
}
