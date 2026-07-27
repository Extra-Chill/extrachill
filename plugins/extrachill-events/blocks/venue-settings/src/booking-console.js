/**
 * WordPress dependencies
 */
import { useEffect, useRef, useState } from '@wordpress/element';

/**
 * External dependencies
 */
import {
	ActionRow,
	FieldGroup,
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

const pad = ( value ) => String( value ).padStart( 2, '0' );

export const monthKey = ( date = new Date() ) =>
	`${ date.getFullYear() }-${ pad( date.getMonth() + 1 ) }`;

export const moveMonth = ( month, amount ) => {
	const [ year, index ] = month.split( '-' ).map( Number );
	return monthKey( new Date( year, index - 1 + amount, 1 ) );
};

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

function BookingStatus( { status } ) {
	return (
		<span className={ `ec-booking-status ec-booking-status--${ status }` }>
			<span className="ec-booking-status__mark" aria-hidden="true" />
			{ statusLabel( status ) }
		</span>
	);
}

function EmptyState( { children } ) {
	return <div className="ec-booking-console__empty">{ children }</div>;
}

function ErrorState( { message, onRetry } ) {
	return (
		<InlineStatus tone="error">
			{ message }
			<button type="button" className="button-link" onClick={ onRetry }>
				Retry
			</button>
		</InlineStatus>
	);
}

function BookingCard( { booking, active, holds, onSelect } ) {
	return (
		<button
			type="button"
			className={ `ec-booking-card${ active ? ' is-active' : '' }` }
			onClick={ () => onSelect( booking.id ) }
			aria-pressed={ active }
		>
			<span className="ec-booking-card__title">
				{ booking.artist_name }
			</span>
			<BookingStatus status={ booking.status } />
			<span>{ formatDate( booking.requested_start_at ) }</span>
			<span>
				{ booking.assignee_user_id
					? `Assigned to user #${ booking.assignee_user_id }`
					: 'Unassigned' }
			</span>
			{ holds.length > 0 && (
				<span>
					{ holds.length } active{ ' ' }
					{ holds.length === 1 ? 'hold' : 'holds' }
				</span>
			) }
		</button>
	);
}

function Calendar( { bookings, holds, month, onMonthChange, onSelect } ) {
	const byDay = bookings.reduce( ( grouped, booking ) => {
		const day = bookingDate( booking );
		if ( day ) {
			grouped[ day ] = [ ...( grouped[ day ] || [] ), booking ];
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
			<div className="ec-booking-calendar__heading">
				<div>
					<h2>{ heading }</h2>
					<p>
						Requests, holds, and confirmed shows share one canonical
						calendar.
					</p>
				</div>
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
						onClick={ () => onMonthChange( moveMonth( month, 1 ) ) }
					>
						Next
					</button>
				</ActionRow>
			</div>
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
							{ ( byDay[ day.key ] || [] ).map( ( booking ) => (
								<button
									type="button"
									key={ booking.id }
									className={ `ec-booking-calendar__item ec-booking-calendar__item--${ booking.status }` }
									onClick={ () => onSelect( booking.id ) }
								>
									<span>{ booking.artist_name }</span>
									<small>
										{ statusLabel( booking.status ) }
										{ activeHoldCounts[ booking.id ]
											? ` · ${
													activeHoldCounts[
														booking.id
													]
											  } active hold${
													activeHoldCounts[
														booking.id
													] === 1
														? ''
														: 's'
											  }`
											: '' }
									</small>
								</button>
							) ) }
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
					<div className="ec-venue-settings__grid">
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
					</div>
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
	members,
	currentUserId,
	onMutate,
	onClose,
	onRefreshCommunications,
} ) {
	const [ status, setStatus ] = useState( null );
	const [ pending, setPending ] = useState( '' );
	const [ transition, setTransition ] = useState( '' );
	const [ note, setNote ] = useState( '' );
	const [ assignee, setAssignee ] = useState(
		booking.assignee_user_id || ''
	);
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
		setAssignee( booking.assignee_user_id || '' );
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
	const assigneeOptions = [
		...( members || [] ).filter( ( member ) => member.status === 'active' ),
		...( ( members || [] ).some(
			( member ) => member.user_id === currentUserId
		)
			? []
			: [
					{
						user_id: currentUserId,
						display_name: 'Me',
						status: 'active',
					},
			  ] ),
	];

	return (
		<Panel className="ec-booking-detail">
			<div className="ec-booking-detail__header">
				<div>
					<BookingStatus status={ booking.status } />
					<h2>{ booking.artist_name }</h2>
					<p>
						Booking #{ booking.id } · version { booking.version }
					</p>
				</div>
				<button type="button" className="button-2" onClick={ onClose }>
					Close detail
				</button>
			</div>
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
				<div className="ec-venue-settings__grid">
					<FieldGroup label="Assignment" htmlFor="booking-assignee">
						<select
							id="booking-assignee"
							value={ assignee }
							onChange={ ( event ) =>
								setAssignee( event.target.value )
							}
						>
							<option value="">Unassigned</option>
							{ assigneeOptions.map( ( member ) => (
								<option
									key={ member.user_id }
									value={ member.user_id }
								>
									{ member.display_name ||
										`User #${ member.user_id }` }
								</option>
							) ) }
						</select>
						<button
							type="button"
							className="button-2"
							disabled={ pending !== '' }
							onClick={ () =>
								mutate(
									'Assignment',
									'extrachill/assign-venue-booking',
									{
										booking_id: booking.id,
										assignee_user_id: assignee
											? Number( assignee )
											: null,
										expected_version: booking.version,
									}
								)
							}
						>
							Save assignment
						</button>
					</FieldGroup>
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
				</div>
			</section>

			<section className="ec-booking-detail__section">
				<h3>Performance and holds</h3>
				<div className="ec-venue-settings__grid">
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
				</div>
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
			<JsonRecord
				title="Deal state"
				value={ booking.confirmed_deal || booking.deal }
				empty="No deal document yet."
			/>
			<JsonRecord
				title="Production state"
				value={ booking.production }
				empty="No production document yet."
			/>

			<section className="ec-booking-detail__section">
				<h3>Canonical event</h3>
				<p>
					{ booking.event_id
						? `Linked event #${ booking.event_id } is synchronized through booking-owned abilities.`
						: 'No canonical event has been created.' }
				</p>
				<button
					type="button"
					className="button-1"
					disabled={ pending !== '' }
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
					{ booking.event_id
						? 'Reconcile event'
						: 'Convert to event' }
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
			<section className="ec-booking-detail__section">
				<h3>Activity</h3>
				<ul className="ec-booking-console__timeline">
					<li>
						<strong>Booking created</strong>
						<small>{ formatDate( booking.created_at ) }</small>
					</li>
					<li>
						<strong>Booking last changed</strong>
						<small>{ formatDate( booking.updated_at ) }</small>
					</li>
				</ul>
			</section>
		</Panel>
	);
}

export function BookingConsole( { context, members, view } ) {
	const venueId = context.selected_venue.id;
	const [ bookings, setBookings ] = useState( [] );
	const [ holds, setHolds ] = useState( [] );
	const [ communications, setCommunications ] = useState( [] );
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
	const [ filterAssignee, setFilterAssignee ] = useState( '' );
	const [ month, setMonth ] = useState( monthKey() );
	const requestId = useRef( 0 );

	const loadList = async () => {
		const currentRequest = ++requestId.current;
		setLoading( true );
		setError( '' );
		try {
			const input = { venue_term_id: venueId, limit: 100, offset: 0 };
			if ( filterStatus ) {
				input.status = filterStatus;
			}
			if ( filterAssignee ) {
				input.assignee_user_id = Number( filterAssignee );
			}
			const [ bookingRows, holdRows ] = await Promise.all( [
				runAbility( 'extrachill/list-venue-bookings', input ),
				runAbility( 'extrachill/list-booking-holds', {
					venue_term_id: venueId,
					limit: 100,
					offset: 0,
				} ),
			] );
			if ( currentRequest === requestId.current ) {
				setBookings( bookingRows );
				setHolds( holdRows );
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

	const loadCommunications = async ( bookingId = selectedId ) => {
		if ( ! bookingId ) {
			return;
		}
		try {
			setCommunications(
				await runAbility( 'extrachill/list-booking-communications', {
					booking_id: bookingId,
				} )
			);
		} catch ( caught ) {
			setDetailError( errorDetails( caught ).message );
		}
	};

	const loadDetail = async ( bookingId = selectedId ) => {
		if ( ! bookingId ) {
			setSelected( null );
			setCommunications( [] );
			return;
		}
		setDetailLoading( true );
		setDetailError( '' );
		try {
			const [ booking, messages ] = await Promise.all( [
				runAbility( 'extrachill/get-venue-booking', {
					booking_id: bookingId,
				} ),
				runAbility( 'extrachill/list-booking-communications', {
					booking_id: bookingId,
				} ),
			] );
			if ( booking.venue_term_id !== venueId ) {
				throw new Error(
					'The booking is outside this venue workspace.'
				);
			}
			setSelected( booking );
			setCommunications( messages );
		} catch ( caught ) {
			setSelected( null );
			setDetailError( errorDetails( caught ).message );
		} finally {
			setDetailLoading( false );
		}
	};

	useEffect( () => {
		loadList();
	}, [ filterStatus, filterAssignee ] ); // eslint-disable-line react-hooks/exhaustive-deps
	useEffect( () => {
		loadDetail();
	}, [ selectedId ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const selectBooking = ( bookingId ) => {
		setSelectedId( bookingId );
		const url = new URL( window.location.href );
		url.searchParams.set( 'venue_id', venueId );
		url.searchParams.set( 'booking_id', bookingId );
		url.hash = `tab-${ view }`;
		window.history.replaceState( {}, '', url );
	};
	const closeDetail = () => {
		setSelectedId( 0 );
		setSelected( null );
		const url = new URL( window.location.href );
		url.searchParams.delete( 'booking_id' );
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
			<div className="ec-booking-console__toolbar">
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
				<label htmlFor="booking-assignee-filter">
					Assignment
					<select
						id="booking-assignee-filter"
						value={ filterAssignee }
						onChange={ ( event ) =>
							setFilterAssignee( event.target.value )
						}
					>
						<option value="">Anyone</option>
						<option value={ context.user.id }>
							Assigned to me
						</option>
					</select>
				</label>
			</div>
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
							holds={ holds }
							month={ month }
							onMonthChange={ setMonth }
							onSelect={ selectBooking }
						/>
					) : (
						<Panel>
							<PanelHeader
								title="Booking pipeline"
								description="A bounded venue-authorized list. Filters are reapplied by canonical abilities."
							/>
							{ visible.length ? (
								<div className="ec-booking-console__list">
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
								</div>
							) : (
								<EmptyState>
									No bookings match this venue and filter.
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
				</Panel>
			) }
			{ selected && ! detailLoading && (
				<BookingDetail
					booking={ selected }
					holds={ selectedHolds }
					communications={ communications }
					members={ members }
					currentUserId={ context.user.id }
					onMutate={ refreshAfterMutation }
					onClose={ closeDetail }
					onRefreshCommunications={ loadCommunications }
				/>
			) }
		</div>
	);
}
