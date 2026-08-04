/* global HTMLInputElement, afterAll, beforeAll, beforeEach, describe, expect, it, jest */

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { getQueryArgs } from '@wordpress/url';
import { createRoot } from '@wordpress/element';

/**
 * External dependencies
 */
import { act } from 'react';

/**
 * Internal dependencies
 */
import { VenueSettingsApp, venueSubscriberCsv } from './view';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

jest.mock( '@extrachill/components', () => {
	const React = require( 'react' );
	const Wrapper = ( { children } ) =>
		React.createElement( 'div', null, children );
	return {
		ActionRow: Wrapper,
		Badge: Wrapper,
		BlockShell: Wrapper,
		BlockShellHeader: ( { title } ) =>
			React.createElement( 'h1', null, title ),
		BlockShellInner: Wrapper,
		FieldGroup: ( { label, help, children } ) =>
			React.createElement(
				'label',
				null,
				label,
				children,
				help && React.createElement( 'span', null, help )
			),
		Grid: Wrapper,
		InlineStatus: Wrapper,
		Panel: Wrapper,
		PanelHeader: ( { title, description, actions } ) =>
			React.createElement(
				'div',
				null,
				React.createElement( 'h2', null, title ),
				description && React.createElement( 'p', null, description ),
				actions
			),
		SearchBox: ( { value, onSearch, placeholder } ) =>
			React.createElement( 'input', {
				value,
				onChange: ( event ) => onSearch( event.target.value ),
				placeholder,
			} ),
		ResponsiveTabs: ( { tabs, active, onChange, renderPanel } ) =>
			React.createElement(
				'div',
				null,
				tabs.map( ( tab ) =>
					React.createElement(
						'button',
						{ key: tab.id, onClick: () => onChange( tab.id ) },
						tab.label
					)
				),
				renderPanel( active )
			),
	};
} );

const profile = ( id ) => ( {
	term_id: id,
	name: `Venue ${ id }`,
	description: '',
	address: '',
	city: '',
	state: '',
	zip: '',
	country: '',
	phone: '',
	website: '',
	capacity: '',
	revision: String( id ).padStart( 64, '0' ),
} );
const config = ( id ) => ( {
	version: 6,
	revision: id,
	updated_by_user_id: null,
	updated_at: null,
	enabled: false,
	intake: {
		version: 1,
		fields: [],
		presentation: {
			artist_name_label: 'Artist or project name',
			contact_name_label: 'Contact name',
			contact_email_label: 'Contact email',
			contact_phone_label: 'Contact phone',
			message_label: 'Anything else?',
			message_help: 'Share routing, timing, or context.',
		},
	},
	public_requirements: [],
	consent: {
		id: 'booking-privacy',
		version: 1,
		label: 'I agree.',
		required: true,
	},
	embed: { allowed_parent_origins: [] },
	spaces: [],
	default_deal: {
		version: 1,
		type: 'custom',
		guarantee_cents: 0,
		revenue_share_basis_points: 0,
		revenue_share_basis: 'gross_ticket_sales',
		currency: 'USD',
	},
	ticket_provider_reference: null,
	marketing_channels: [],
	marketing_triggers: [],
	hold_ttl_minutes: 1440,
} );
const booking = ( id, venueId = 44 ) => ( {
	id,
	public_id: `booking-${ id }`,
	venue_term_id: venueId,
	artist_term_id: null,
	artist_profile_id: null,
	artist_name: 'Kid Lake',
	submitter_user_id: null,
	contact_name: 'Booking Agent',
	contact_email: 'agent@example.com',
	contact_phone: null,
	requested_space_key: 'main-room',
	space_key: null,
	status: 'submitted',
	version: 4,
	requested_start_at: '2026-07-30 20:00:00',
	requested_end_at: '2026-07-30 23:00:00',
	performance_start_at: null,
	performance_end_at: null,
	intake: { version: 1, data: { hometown: 'Charleston' } },
	production: null,
	deal: null,
	confirmed_deal: null,
	event_id: null,
	created_at: '2026-07-20 12:00:00',
	updated_at: '2026-07-20 12:00:00',
} );
const bookingActivity = ( overrides = {} ) => ( {
	activity: [
		{
			id: 31,
			kind: 'booking_submitted',
			actor_type: 'user',
			actor_id: 7,
			direction: null,
			channel: null,
			external_id: null,
			occurred_at: '2026-07-20 12:00:00',
		},
	],
	conversion: {
		status: 'none',
		attempt: 0,
		failure_code: null,
		retryable: false,
	},
	sync: { status: 'none', code: null, retryable: false },
	...overrides,
} );
const context = ( overrides = {} ) => ( {
	user: { id: 7, name: 'Operator', is_admin: false },
	venues: [ { id: 44, name: 'Venue 44', status: 'active', is_owner: false } ],
	claim_venues: [ { id: 44, name: 'Venue 44' } ],
	selected_venue: {
		id: 44,
		name: 'Venue 44',
		status: 'active',
		is_owner: false,
	},
	can_access: true,
	can_manage: false,
	route_url: 'https://events.example/venue-settings/',
	booking_url: 'https://events.example/venue/venue-44/#booking-inquiry',
	requested_venue_id: 0,
	booking_id: 0,
	support_events: [],
	...overrides,
} );

const normalizeQueryInput = ( value ) => {
	if ( Array.isArray( value ) ) {
		return value.map( normalizeQueryInput );
	}
	if ( value && typeof value === 'object' ) {
		return Object.fromEntries(
			Object.entries( value ).map( ( [ key, item ] ) => [
				key,
				normalizeQueryInput( item ),
			] )
		);
	}
	return typeof value === 'string' && /^-?\d+(?:\.\d+)?$/.test( value )
		? Number( value )
		: value;
};

const requestInput = ( request ) => {
	if ( request.data?.input ) {
		return request.data.input;
	}
	return normalizeQueryInput(
		getQueryArgs( request.path || request ).input || {}
	);
};

const installApi = () =>
	apiFetch.mockImplementation( ( request ) => {
		const input = requestInput( request );
		if ( request.path.includes( 'get-venue-profile' ) ) {
			return Promise.resolve( profile( input.venue_term_id ) );
		}
		if ( request.path.includes( 'get-venue-booking-config' ) ) {
			return Promise.resolve( config( input.venue_term_id ) );
		}
		if ( request.path.includes( 'get-venue-booking-activity' ) ) {
			return Promise.resolve( bookingActivity() );
		}
		if (
			request.path.includes( 'list-venue-memberships' ) ||
			request.path.includes( 'list-venue-invitations' ) ||
			request.path.includes( 'list-venue-claims' ) ||
			request.path.includes( 'list-venue-bookings' ) ||
			request.path.includes( 'list-booking-holds' ) ||
			request.path.includes( 'list-booking-communications' )
		) {
			return Promise.resolve( [] );
		}
		return Promise.resolve( {} );
	} );

async function renderApp( appContext ) {
	const container = document.createElement( 'div' );
	document.body.appendChild( container );
	const root = createRoot( container );
	await act( async () => {
		root.render( <VenueSettingsApp context={ appContext } /> );
		await Promise.resolve();
		await Promise.resolve();
	} );
	return { container, root };
}

const buttonByText = ( container, text ) =>
	[ ...container.querySelectorAll( 'button' ) ].find(
		( button ) => button.textContent === text
	);
const buttonContaining = ( container, text ) =>
	[ ...container.querySelectorAll( 'button' ) ].find( ( button ) =>
		button.textContent.includes( text )
	);
const deferred = () => {
	let resolve;
	const promise = new Promise( ( done ) => {
		resolve = done;
	} );
	return { promise, resolve };
};

const setInput = async ( input, value ) => {
	await act( async () => {
		Object.getOwnPropertyDescriptor(
			HTMLInputElement.prototype,
			'value'
		).set.call( input, value );
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	} );
};

const setControl = async ( control, value ) => {
	await act( async () => {
		Object.getOwnPropertyDescriptor(
			control.constructor.prototype,
			'value'
		).set.call( control, value );
		control.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	} );
};

describe( 'venue settings authorization-facing states', () => {
	beforeAll( () => {
		global.IS_REACT_ACT_ENVIRONMENT = true;
	} );
	afterAll( () => {
		delete global.IS_REACT_ACT_ENVIRONMENT;
	} );
	beforeEach( () => {
		document.body.innerHTML = '';
		apiFetch.mockReset();
		installApi();
	} );

	it.each( [ 'invited', 'revoked' ] )(
		'does not request active settings for a %s member',
		async ( status ) => {
			const selected = {
				id: 44,
				name: 'Venue 44',
				status,
				is_owner: false,
			};
			const { container, root } = await renderApp(
				context( {
					selected_venue: selected,
					venues: [ selected ],
					can_access: false,
				} )
			);
			expect( apiFetch ).not.toHaveBeenCalled();
			expect( container.textContent ).toContain(
				`${ status } membership cannot access active-member settings`
			);
			await act( async () => root.unmount() );
		}
	);

	it( 'preselects venue context for a non-member claim', async () => {
		const { container, root } = await renderApp(
			context( {
				venues: [],
				claim_venues: [
					{ id: 44, name: 'Venue 44' },
					{ id: 45, name: 'Venue 45' },
				],
				selected_venue: null,
				can_access: false,
				requested_venue_id: 45,
			} )
		);
		expect( container.querySelector( 'select' ).value ).toBe( '45' );
		await act( async () => root.unmount() );
	} );

	it( 'shows all manageable venues by default', async () => {
		const venues = [
			{
				id: 44,
				name: 'Venue 44',
				status: 'active',
				is_owner: true,
				can_access: true,
				can_manage: true,
			},
			{
				id: 45,
				name: 'Venue 45',
				status: 'active',
				is_owner: false,
				can_access: true,
				can_manage: false,
			},
		];
		const { container, root } = await renderApp(
			context( {
				venues,
				selected_venue: null,
				can_access: false,
			} )
		);

		const selector = container.querySelector( '#venue-workspace' );
		expect( selector.value ).toBe( '0' );
		expect( selector.options[ 0 ].textContent ).toBe( 'My Venues' );
		expect( container.textContent ).toContain( 'Venue 44' );
		expect( container.textContent ).toContain( 'Venue 45' );
		expect(
			[ ...container.querySelectorAll( 'button' ) ]
				.slice( 0, 4 )
				.map( ( button ) => button.textContent )
		).toEqual( [ 'Calendar', 'Venue', 'Settings', 'Team' ] );
		expect( container.textContent ).not.toContain( 'Open workspace' );
		const profileVenueIds = apiFetch.mock.calls
			.filter( ( [ request ] ) =>
				request.path.includes( 'get-venue-profile' )
			)
			.map( ( [ request ] ) =>
				request.data?.input
					? request.data.input.venue_term_id
					: requestInput( request.path ).venue_term_id
			);
		expect( profileVenueIds ).toEqual( [ 44, 45 ] );

		await act( async () => buttonByText( container, 'Venue' ).click() );
		expect( container.textContent ).toContain( 'Public venue profile' );
		expect(
			container.querySelectorAll( '.ec-venue-settings__venue-scope > h2' )
		).toHaveLength( 2 );
		for ( const tab of [ 'Venue', 'Settings', 'Team' ] ) {
			await act( async () => buttonByText( container, tab ).click() );
			const ids = [ ...container.querySelectorAll( '[id]' ) ].map(
				( element ) => element.id
			);
			expect( new Set( ids ).size ).toBe( ids.length );
		}
		await act( async () => root.unmount() );
	} );

	it( 'combines events, submissions, and holds across My Venues', async () => {
		apiFetch.mockImplementation( ( request ) => {
			const input = request.data?.input || requestInput( request.path );
			const venueId = input.venue_term_id || input.venue_id;
			if ( request.path.includes( 'get-venue-profile' ) ) {
				return Promise.resolve( profile( venueId ) );
			}
			if ( request.path.includes( 'get-venue-booking-config' ) ) {
				return Promise.resolve( config( venueId ) );
			}
			if ( request.path.includes( 'list-venue-bookings' ) ) {
				return Promise.resolve( [
					{
						...booking( 100 + venueId, venueId ),
						artist_name: `Artist ${ venueId }`,
						requested_start_at: '2026-08-05 20:00:00',
					},
				] );
			}
			if ( request.path.includes( 'list-booking-holds' ) ) {
				return Promise.resolve( [
					{
						id: 200 + venueId,
						booking_id: 100 + venueId,
						status: 'active',
					},
				] );
			}
			if ( request.path.includes( 'events-calendar' ) ) {
				return Promise.resolve( {
					dates: [
						{
							events: [
								{
									id: 300 + venueId,
									title: `Published ${ venueId }`,
									datetime: '2026-08-06 20:00:00',
									permalink: `https://events.example/event-${ venueId }/`,
								},
							],
						},
					],
				} );
			}
			return Promise.resolve( [] );
		} );
		const venues = [ 44, 45 ].map( ( id ) => ( {
			id,
			name: `Venue ${ id }`,
			status: 'active',
			is_owner: false,
			can_access: true,
			can_manage: false,
		} ) );
		const { container, root } = await renderApp(
			context( {
				venues,
				selected_venue: null,
				can_access: false,
			} )
		);

		for ( const id of [ 44, 45 ] ) {
			expect( container.textContent ).toContain( `Artist ${ id }` );
			expect( container.textContent ).toContain( `Published ${ id }` );
			expect( container.textContent ).toContain( `Venue ${ id }` );
		}
		expect( container.textContent.match( /1 active hold/g ) ).toHaveLength(
			2
		);
		window.history.replaceState( {}, '', '/venue-settings/' );
		await act( async () => {
			buttonContaining( container, 'Artist 44' ).click();
			await Promise.resolve();
		} );
		const selectedUrl = new URL( window.location.href );
		expect( selectedUrl.searchParams.get( 'booking_id' ) ).toBe( '144' );
		expect( selectedUrl.searchParams.get( 'booking_venue_id' ) ).toBe(
			'44'
		);
		expect( selectedUrl.searchParams.has( 'venue_id' ) ).toBe( false );
		await act( async () => root.unmount() );
	} );

	it( 'keeps profile edits and saves isolated by venue', async () => {
		let updateInput;
		apiFetch.mockImplementation( ( request ) => {
			const input = request.data?.input || {};
			if ( request.path.includes( 'get-venue-profile' ) ) {
				return Promise.resolve( profile( input.venue_term_id ) );
			}
			if ( request.path.includes( 'get-venue-booking-config' ) ) {
				return Promise.resolve( config( input.venue_term_id ) );
			}
			if ( request.path.includes( 'update-venue-profile' ) ) {
				updateInput = input;
				return Promise.resolve( {
					profile: {
						...profile( input.venue_term_id ),
						...input.profile,
					},
				} );
			}
			if ( request.path.includes( 'events-calendar' ) ) {
				return Promise.resolve( { dates: [], has_more: false } );
			}
			return Promise.resolve( [] );
		} );
		const venues = [ 44, 45 ].map( ( id ) => ( {
			id,
			name: `Venue ${ id }`,
			status: 'active',
			is_owner: false,
			can_access: true,
			can_manage: false,
		} ) );
		const { container, root } = await renderApp(
			context( { venues, selected_venue: null, can_access: false } )
		);
		await act( async () => buttonByText( container, 'Venue' ).click() );
		await setInput(
			container.querySelector( '#venue-44-venue-profile-name' ),
			'Edited Venue 44'
		);
		await act( async () => {
			buttonByText( container, 'Save profile' ).click();
			await Promise.resolve();
		} );

		expect( updateInput.venue_term_id ).toBe( 44 );
		expect( updateInput.profile ).toEqual( { name: 'Edited Venue 44' } );
		expect(
			container.querySelector( '#venue-45-venue-profile-name' ).value
		).toBe( 'Venue 45' );
		await act( async () => root.unmount() );
	} );

	it( 'hides team controls from active non-owners', async () => {
		const { container, root } = await renderApp( context() );
		expect( container.textContent ).not.toContain( 'Team' );
		expect(
			[ ...container.querySelectorAll( 'button' ) ]
				.slice( 0, 3 )
				.map( ( button ) => button.textContent )
		).toEqual( [ 'Calendar', 'Venue', 'Settings' ] );
		for ( const retired of [
			'Bookings',
			'Local Support',
			'Profile',
			'Booking',
			'Guide',
			'Intake',
			'Claims',
		] ) {
			expect( buttonByText( container, retired ) ).toBeUndefined();
		}
		expect(
			apiFetch.mock.calls.some( ( [ request ] ) =>
				request.path.includes( 'list-venue-memberships' )
			)
		).toBe( false );
		expect(
			apiFetch.mock.calls.some( ( [ request ] ) =>
				request.path.includes( 'list-venue-email-subscribers' )
			)
		).toBe( false );
		await act( async () => root.unmount() );
	} );

	it( 'shows exact current venue email subscribers only to owners', async () => {
		apiFetch.mockImplementation( ( request ) => {
			const input = requestInput( request );
			if ( request.path.includes( 'get-venue-profile' ) ) {
				return Promise.resolve( profile( input.venue_term_id ) );
			}
			if ( request.path.includes( 'get-venue-booking-config' ) ) {
				return Promise.resolve( config( input.venue_term_id ) );
			}
			if ( request.path.includes( 'list-venue-email-subscribers' ) ) {
				return Promise.resolve( {
					venue_term_id: input.venue_term_id,
					total: 1,
					subscribers: [
						{ user_id: 12, email: 'current@example.com' },
					],
				} );
			}
			return Promise.resolve( [] );
		} );
		const { container, root } = await renderApp(
			context( { can_manage: true } )
		);
		await act( async () => buttonByText( container, 'Team' ).click() );

		expect( container.textContent ).toContain( 'current@example.com' );
		expect( container.textContent ).toContain( 'Download CSV' );
		const request = apiFetch.mock.calls.find( ( [ call ] ) =>
			call.path.includes( 'list-venue-email-subscribers' )
		)[ 0 ];
		expect( requestInput( request ) ).toEqual( { venue_term_id: 44 } );
		await act( async () => root.unmount() );
	} );

	it( 'shows exactly four venue tabs to authorized managers', async () => {
		const { container, root } = await renderApp(
			context( {
				user: { id: 1, name: 'Admin', is_admin: true },
				can_manage: true,
			} )
		);
		expect(
			[ ...container.querySelectorAll( 'button' ) ]
				.slice( 0, 4 )
				.map( ( button ) => button.textContent )
		).toEqual( [ 'Calendar', 'Venue', 'Settings', 'Team' ] );
		expect( container.textContent ).not.toContain( 'Venue claims' );
		await act( async () => buttonByText( container, 'List' ).click() );
		expect( container.textContent ).toContain( 'Booking pipeline' );
		await act( async () => root.unmount() );
	} );

	it( 'exports only the resolved user ID and current email', () => {
		expect(
			venueSubscriberCsv( [
				{ user_id: 12, email: 'current@example.com' },
			] )
		).toBe( '"user_id","email"\r\n"12","current@example.com"' );
	} );

	it( 'lets administrators review claims without active venue access', async () => {
		apiFetch.mockImplementation( ( request ) => {
			if ( request.path.includes( 'list-venue-claims' ) ) {
				return Promise.resolve( [
					{
						id: 3,
						venue_term_id: 44,
						claimant_user_id: 19,
						status: 'pending',
						version: 1,
					},
				] );
			}
			return Promise.resolve( [] );
		} );
		const { container, root } = await renderApp(
			context( {
				user: { id: 1, name: 'Admin', is_admin: true },
				can_access: false,
				can_manage: true,
			} )
		);
		expect( container.textContent ).toContain( 'Claimant user #19' );
		expect( buttonByText( container, 'Claims' ) ).toBeUndefined();
		expect(
			apiFetch.mock.calls.some( ( [ request ] ) =>
				request.path.includes( 'get-venue-profile' )
			)
		).toBe( false );
		await act( async () => root.unmount() );
	} );

	it( 'surfaces local-support actions on matching calendar events', async () => {
		apiFetch.mockImplementation( ( request ) => {
			const input = requestInput( request );
			if ( request.path.includes( 'get-venue-profile' ) ) {
				return Promise.resolve( profile( input.venue_term_id ) );
			}
			if ( request.path.includes( 'get-venue-booking-config' ) ) {
				return Promise.resolve( config( input.venue_term_id ) );
			}
			if ( request.path.includes( 'events-calendar' ) ) {
				return Promise.resolve( {
					dates: [
						{
							events: [
								{
									id: 901,
									title: 'Kid Lake at Venue 44',
									datetime: '2026-08-01 20:00:00',
									permalink:
										'https://events.example/kid-lake/',
								},
							],
						},
					],
				} );
			}
			return Promise.resolve( [] );
		} );
		const { container, root } = await renderApp(
			context( {
				support_events: [
					{
						id: 901,
						title: 'Kid Lake at Venue 44',
						start_datetime: '2026-08-01 20:00:00',
						status: 'not_seeking',
						workspace_url:
							'https://events.example/local-support/?event_id=901',
						permalink: 'https://events.example/kid-lake/',
					},
				],
			} )
		);
		expect( container.textContent ).toContain( 'Kid Lake at Venue 44' );
		expect( container.textContent ).toContain( 'Find local support' );
		expect(
			[ ...container.querySelectorAll( 'a' ) ]
				.find( ( link ) => link.textContent === 'Find local support' )
				.getAttribute( 'href' )
		).toBe( 'https://events.example/local-support/?event_id=901' );
		await act( async () => root.unmount() );
	} );

	it( 'keeps successful config data available when profile loading fails', async () => {
		apiFetch.mockImplementation( ( request ) => {
			if ( request.path.includes( 'get-venue-profile' ) ) {
				return Promise.reject( { message: 'Profile unavailable.' } );
			}
			if ( request.path.includes( 'get-venue-booking-config' ) ) {
				const input = requestInput( request );
				return Promise.resolve( config( input.venue_term_id ) );
			}
			return Promise.resolve( [] );
		} );
		const { container, root } = await renderApp( context() );
		await act( async () => buttonByText( container, 'Venue' ).click() );
		expect( container.textContent ).toContain( 'Profile unavailable.' );
		expect( container.textContent ).toContain( 'Retry profile' );
		await act( async () => root.unmount() );
	} );

	it( 'shows the fourteen-day hold limit in booking settings', async () => {
		const { container, root } = await renderApp( context() );
		await act( async () => buttonByText( container, 'Settings' ).click() );

		expect( container.querySelector( '#venue-hold-ttl' ).max ).toBe(
			'20160'
		);
		expect( container.textContent ).toContain(
			'Between 5 minutes and 14 days.'
		);
		await act( async () => root.unmount() );
	} );

	it( 'retrying profile preserves dirty booking settings', async () => {
		let profileAttempts = 0;
		let configAttempts = 0;
		apiFetch.mockImplementation( ( request ) => {
			const input = requestInput( request );
			if ( request.path.includes( 'get-venue-profile' ) ) {
				profileAttempts += 1;
				return profileAttempts === 1
					? Promise.reject( { message: 'Profile unavailable.' } )
					: Promise.resolve( profile( input.venue_term_id ) );
			}
			if ( request.path.includes( 'get-venue-booking-config' ) ) {
				configAttempts += 1;
				return Promise.resolve( config( input.venue_term_id ) );
			}
			return Promise.resolve( [] );
		} );
		const { container, root } = await renderApp( context() );
		await act( async () => buttonByText( container, 'Settings' ).click() );
		await setInput(
			container.querySelector( '#venue-ticket-provider' ),
			'dirty-ticket-account'
		);
		await act( async () => buttonByText( container, 'Venue' ).click() );
		await act( async () => {
			buttonByText( container, 'Retry profile' ).click();
			await Promise.resolve();
		} );
		await act( async () => buttonByText( container, 'Settings' ).click() );
		expect(
			container.querySelector( '#venue-ticket-provider' ).value
		).toBe( 'dirty-ticket-account' );
		expect( profileAttempts ).toBe( 2 );
		expect( configAttempts ).toBe( 1 );
		await act( async () => root.unmount() );
	} );

	it( 'retrying booking settings preserves a dirty profile', async () => {
		let profileAttempts = 0;
		let configAttempts = 0;
		apiFetch.mockImplementation( ( request ) => {
			const input = requestInput( request );
			if ( request.path.includes( 'get-venue-profile' ) ) {
				profileAttempts += 1;
				return Promise.resolve( profile( input.venue_term_id ) );
			}
			if ( request.path.includes( 'get-venue-booking-config' ) ) {
				configAttempts += 1;
				return configAttempts === 1
					? Promise.reject( { message: 'Booking unavailable.' } )
					: Promise.resolve( config( input.venue_term_id ) );
			}
			return Promise.resolve( [] );
		} );
		const { container, root } = await renderApp( context() );
		await act( async () => buttonByText( container, 'Venue' ).click() );
		await setInput(
			container.querySelector( '#venue-profile-name' ),
			'Locally edited venue'
		);
		await act( async () => buttonByText( container, 'Settings' ).click() );
		await act( async () => {
			buttonByText( container, 'Retry booking settings' ).click();
			await Promise.resolve();
		} );
		await act( async () => buttonByText( container, 'Venue' ).click() );
		expect( container.querySelector( '#venue-profile-name' ).value ).toBe(
			'Locally edited venue'
		);
		expect( profileAttempts ).toBe( 1 );
		expect( configAttempts ).toBe( 2 );
		await act( async () => root.unmount() );
	} );

	it( 'clears a stale claims error after a successful retry', async () => {
		let attempts = 0;
		apiFetch.mockImplementation( ( request ) => {
			if ( request.path.includes( 'list-venue-claims' ) ) {
				attempts += 1;
				return attempts === 1
					? Promise.reject( { message: 'Claims unavailable.' } )
					: Promise.resolve( [
							{
								id: 8,
								venue_term_id: 44,
								claimant_user_id: 29,
								status: 'pending',
								version: 1,
							},
					  ] );
			}
			return Promise.resolve( [] );
		} );
		const { container, root } = await renderApp(
			context( {
				user: { id: 1, name: 'Admin', is_admin: true },
				can_access: false,
				can_manage: true,
			} )
		);
		expect( container.textContent ).toContain( 'Claims unavailable.' );
		expect( buttonByText( container, 'Retry' ).className ).toBe(
			'button-2 button-small'
		);
		await act( async () => {
			buttonByText( container, 'Retry' ).click();
			await Promise.resolve();
		} );
		expect( container.textContent ).not.toContain( 'Claims unavailable.' );
		expect( container.textContent ).toContain( 'Claimant user #29' );
		await act( async () => root.unmount() );
	} );

	it( 'preserves dirty profile data and reports a stale-write conflict', async () => {
		apiFetch.mockImplementation( ( request ) => {
			const input = requestInput( request );
			if ( request.path.includes( 'get-venue-profile' ) ) {
				return Promise.resolve( profile( input.venue_term_id ) );
			}
			if ( request.path.includes( 'get-venue-booking-config' ) ) {
				return Promise.resolve( config( input.venue_term_id ) );
			}
			if ( request.path.includes( 'update-venue-profile' ) ) {
				return Promise.reject( {
					code: 'venue_profile_revision_conflict',
					message: 'The venue profile changed since it was read.',
					data: { status: 409 },
				} );
			}
			return Promise.resolve( [] );
		} );
		const { container, root } = await renderApp( context() );
		await act( async () => buttonByText( container, 'Venue' ).click() );
		const name = container.querySelector( '#venue-profile-name' );
		await act( async () => {
			Object.getOwnPropertyDescriptor(
				HTMLInputElement.prototype,
				'value'
			).set.call( name, 'Locally edited venue' );
			name.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );
		const save = buttonByText( container, 'Save profile' );
		await act( async () => {
			save.click();
			await Promise.resolve();
		} );
		expect( container.textContent ).toContain(
			'Reload before saving again.'
		);
		expect( name.value ).toBe( 'Locally edited venue' );
		await act( async () => root.unmount() );
	} );

	it( 'isolates successive mounts to their selected venue', async () => {
		const first = await renderApp( context() );
		expect( first.container.textContent ).toContain( 'Venue 44' );
		await act( async () => first.root.unmount() );
		const selected = {
			id: 88,
			name: 'Venue 88',
			status: 'active',
			is_owner: false,
		};
		const second = await renderApp(
			context( {
				venues: [ selected ],
				claim_venues: [ selected ],
				selected_venue: selected,
			} )
		);
		expect( second.container.textContent ).toContain( 'Venue 88' );
		expect( second.container.textContent ).not.toContain( 'Venue 44' );
		const venueInputs = apiFetch.mock.calls
			.filter( ( [ request ] ) => request.path.includes( 'get-venue-' ) )
			.map( ( [ request ] ) => requestInput( request ).venue_term_id );
		expect( venueInputs ).toEqual( [ 44, 44, 88, 88 ] );
		await act( async () => second.root.unmount() );
	} );

	it( 'hydrates a deep-linked booking and sends its expected version', async () => {
		apiFetch.mockImplementation( ( request ) => {
			const input = request.data?.input || requestInput( request.path );
			if ( request.path.includes( 'get-venue-profile' ) ) {
				return Promise.resolve( profile( input.venue_term_id ) );
			}
			if ( request.path.includes( 'get-venue-booking-config' ) ) {
				return Promise.resolve( config( input.venue_term_id ) );
			}
			if ( request.path.includes( 'get-venue-booking-activity' ) ) {
				return Promise.resolve( bookingActivity() );
			}
			if ( request.path.includes( 'get-venue-booking' ) ) {
				return Promise.resolve( booking( input.booking_id ) );
			}
			if ( request.path.includes( 'transition-venue-booking' ) ) {
				return Promise.resolve( {
					...booking( input.booking_id ),
					version: 5,
					status: input.to_status,
				} );
			}
			return Promise.resolve( [] );
		} );
		const { container, root } = await renderApp(
			context( { booking_id: 9 } )
		);
		expect( container.textContent ).toContain( 'Booking #9' );
		expect( container.textContent ).toContain( 'Booking Submitted' );
		await act( async () => {
			const select = container.querySelector( '#booking-transition' );
			select.value = 'under_review';
			select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		} );
		await act( async () => {
			buttonByText( container, 'Apply transition' ).click();
			await Promise.resolve();
			await Promise.resolve();
		} );
		expect(
			apiFetch.mock.calls.some(
				( [ request ] ) =>
					request.path.includes( 'transition-venue-booking' ) &&
					request.data.input.booking_id === 9 &&
					request.data.input.expected_version === 4 &&
					request.data.input.to_status === 'under_review'
			)
		).toBe( true );
		await act( async () => root.unmount() );
	} );

	it( 'sends complete canonical deal and production documents at the expected version', async () => {
		apiFetch.mockImplementation( ( request ) => {
			const input = request.data?.input || requestInput( request.path );
			if ( request.path.includes( 'get-venue-profile' ) ) {
				return Promise.resolve( profile( input.venue_term_id ) );
			}
			if ( request.path.includes( 'get-venue-booking-config' ) ) {
				return Promise.resolve( config( input.venue_term_id ) );
			}
			if ( request.path.includes( 'get-venue-booking-activity' ) ) {
				return Promise.resolve( bookingActivity() );
			}
			if ( request.path.includes( 'get-venue-booking' ) ) {
				return Promise.resolve( {
					...booking( input.booking_id ),
					status: 'negotiating',
				} );
			}
			if (
				request.path.includes( 'update-venue-booking-deal' ) ||
				request.path.includes( 'update-venue-booking-production' )
			) {
				return Promise.resolve( {
					...booking( input.booking_id ),
					version: 5,
				} );
			}
			return Promise.resolve( [] );
		} );
		const { container, root } = await renderApp(
			context( { booking_id: 12 } )
		);
		await setControl(
			container.querySelector( '#booking-deal-guarantee' ),
			'125000'
		);
		await act( async () => {
			buttonByText( container, 'Save deal terms' ).click();
			await Promise.resolve();
			await Promise.resolve();
		} );
		await setControl(
			container.querySelector( '#booking-production-notes' ),
			'House provides backline.'
		);
		await act( async () => {
			buttonByText( container, 'Save production details' ).click();
			await Promise.resolve();
			await Promise.resolve();
		} );
		const dealRequest = apiFetch.mock.calls.find( ( [ request ] ) =>
			request.path.includes( 'update-venue-booking-deal' )
		)[ 0 ];
		const productionRequest = apiFetch.mock.calls.find( ( [ request ] ) =>
			request.path.includes( 'update-venue-booking-production' )
		)[ 0 ];
		expect( dealRequest.data.input ).toMatchObject( {
			booking_id: 12,
			expected_version: 4,
			deal: { version: 1, guarantee_cents: 125000 },
		} );
		expect( Object.keys( dealRequest.data.input.deal ) ).toHaveLength( 13 );
		expect( productionRequest.data.input ).toEqual( {
			booking_id: 12,
			expected_version: 4,
			production: {
				version: 1,
				support_requirements: [],
				support_offers: [],
				production_notes: 'House provides backline.',
			},
		} );
		await act( async () => root.unmount() );
	} );

	it( 'reloads the booking and reports a stale deal conflict', async () => {
		let detailReads = 0;
		apiFetch.mockImplementation( ( request ) => {
			const input = request.data?.input || requestInput( request.path );
			if ( request.path.includes( 'get-venue-profile' ) ) {
				return Promise.resolve( profile( input.venue_term_id ) );
			}
			if ( request.path.includes( 'get-venue-booking-config' ) ) {
				return Promise.resolve( config( input.venue_term_id ) );
			}
			if ( request.path.includes( 'get-venue-booking-activity' ) ) {
				return Promise.resolve( bookingActivity() );
			}
			if ( request.path.includes( 'get-venue-booking' ) ) {
				detailReads += 1;
				return Promise.resolve( {
					...booking( input.booking_id ),
					status: 'negotiating',
				} );
			}
			if ( request.path.includes( 'update-venue-booking-deal' ) ) {
				return Promise.reject( {
					code: 'booking_version_conflict',
					message: 'The booking changed since it was read.',
					data: { status: 409 },
				} );
			}
			return Promise.resolve( [] );
		} );
		const { container, root } = await renderApp(
			context( { booking_id: 14 } )
		);
		await act( async () => {
			buttonByText( container, 'Save deal terms' ).click();
			await Promise.resolve();
			await Promise.resolve();
		} );
		expect( container.textContent ).toContain(
			'The latest booking has been reloaded.'
		);
		expect( detailReads ).toBeGreaterThan( 1 );
		await act( async () => root.unmount() );
	} );

	it( 'renders conversion failure and retry state from authoritative activity', async () => {
		apiFetch.mockImplementation( ( request ) => {
			const input = request.data?.input || requestInput( request.path );
			if ( request.path.includes( 'get-venue-profile' ) ) {
				return Promise.resolve( profile( input.venue_term_id ) );
			}
			if ( request.path.includes( 'get-venue-booking-config' ) ) {
				return Promise.resolve( config( input.venue_term_id ) );
			}
			if ( request.path.includes( 'get-venue-booking-activity' ) ) {
				return Promise.resolve(
					bookingActivity( {
						conversion: {
							status: 'failed',
							attempt: 2,
							failure_code: 'upstream_timeout',
							retryable: true,
						},
					} )
				);
			}
			if ( request.path.includes( 'get-venue-booking' ) ) {
				return Promise.resolve( booking( input.booking_id ) );
			}
			return Promise.resolve( [] );
		} );
		const { container, root } = await renderApp(
			context( { booking_id: 13 } )
		);
		expect( container.textContent ).toContain(
			'Conversion attempt 2 failed (upstream_timeout). Retry is available.'
		);
		expect( container.textContent ).toContain( 'Retry event conversion' );
		await act( async () => root.unmount() );
	} );

	it( 'does not offer immediate retry for a non-retryable conversion failure', async () => {
		apiFetch.mockImplementation( ( request ) => {
			const input = request.data?.input || requestInput( request.path );
			if ( request.path.includes( 'get-venue-profile' ) ) {
				return Promise.resolve( profile( input.venue_term_id ) );
			}
			if ( request.path.includes( 'get-venue-booking-config' ) ) {
				return Promise.resolve( config( input.venue_term_id ) );
			}
			if ( request.path.includes( 'get-venue-booking-activity' ) ) {
				return Promise.resolve(
					bookingActivity( {
						conversion: {
							status: 'failed',
							attempt: 1,
							failure_code: 'invalid_event',
							retryable: false,
						},
					} )
				);
			}
			if ( request.path.includes( 'get-venue-booking' ) ) {
				return Promise.resolve( booking( input.booking_id ) );
			}
			return Promise.resolve( [] );
		} );
		const { container, root } = await renderApp(
			context( { booking_id: 15 } )
		);
		const action = buttonByText(
			container,
			'Conversion retry unavailable'
		);
		expect( action.disabled ).toBe( true );
		expect( container.textContent ).not.toContain(
			'Retry event conversion'
		);
		await act( async () => root.unmount() );
	} );

	it( 'rejects malformed stored deal and production documents without crashing', async () => {
		apiFetch.mockImplementation( ( request ) => {
			const input = request.data?.input || requestInput( request.path );
			if ( request.path.includes( 'get-venue-profile' ) ) {
				return Promise.resolve( profile( input.venue_term_id ) );
			}
			if ( request.path.includes( 'get-venue-booking-config' ) ) {
				return Promise.resolve( config( input.venue_term_id ) );
			}
			if ( request.path.includes( 'get-venue-booking-activity' ) ) {
				return Promise.resolve( bookingActivity() );
			}
			if ( request.path.includes( 'get-venue-booking' ) ) {
				return Promise.resolve( {
					...booking( input.booking_id ),
					deal: { version: 1, data: { type: 'broken' } },
					production: {
						version: 1,
						data: {
							version: 1,
							support_requirements: 'not-an-array',
							support_offers: [],
							production_notes: null,
						},
					},
				} );
			}
			return Promise.resolve( [] );
		} );
		const { container, root } = await renderApp(
			context( { booking_id: 16 } )
		);
		expect( container.textContent ).toContain(
			'The stored deal document is malformed.'
		);
		expect( container.textContent ).toContain(
			'The stored production document is malformed.'
		);
		expect( buttonByText( container, 'Save deal terms' ) ).toBeUndefined();
		expect(
			buttonByText( container, 'Save production details' )
		).toBeUndefined();
		await act( async () => root.unmount() );
	} );

	it( 'keeps the latest rapid booking selection when responses resolve out of order', async () => {
		const detailA = deferred();
		const detailB = deferred();
		const first = { ...booking( 21 ), artist_name: 'Artist A' };
		const second = { ...booking( 22 ), artist_name: 'Artist B' };
		apiFetch.mockImplementation( ( request ) => {
			const input = request.data?.input || requestInput( request.path );
			if ( request.path.includes( 'get-venue-profile' ) ) {
				return Promise.resolve( profile( input.venue_term_id ) );
			}
			if ( request.path.includes( 'get-venue-booking-config' ) ) {
				return Promise.resolve( config( input.venue_term_id ) );
			}
			if ( request.path.includes( 'get-venue-booking-activity' ) ) {
				return Promise.resolve( bookingActivity() );
			}
			if ( request.path.includes( 'get-venue-booking' ) ) {
				return input.booking_id === 21
					? detailA.promise
					: detailB.promise;
			}
			if ( request.path.includes( 'list-venue-bookings' ) ) {
				return Promise.resolve( [ first, second ] );
			}
			return Promise.resolve( [] );
		} );
		const { container, root } = await renderApp( context() );
		await act( async () =>
			buttonContaining( container, 'Artist A' ).click()
		);
		await act( async () =>
			buttonContaining( container, 'Artist B' ).click()
		);
		await act( async () => {
			detailB.resolve( second );
			await detailB.promise;
		} );
		expect( container.textContent ).toContain( 'Booking #22' );
		await act( async () => {
			detailA.resolve( first );
			await detailA.promise;
		} );
		expect( container.textContent ).toContain( 'Booking #22' );
		expect( container.textContent ).not.toContain( 'Booking #21' );
		await act( async () => root.unmount() );
	} );

	it( 'clears detail loading when an in-flight booking is closed', async () => {
		const pending = deferred();
		const first = { ...booking( 23 ), artist_name: 'Loaded Artist' };
		const second = { ...booking( 24 ), artist_name: 'Pending Artist' };
		apiFetch.mockImplementation( ( request ) => {
			const input = request.data?.input || requestInput( request.path );
			if ( request.path.includes( 'get-venue-profile' ) ) {
				return Promise.resolve( profile( input.venue_term_id ) );
			}
			if ( request.path.includes( 'get-venue-booking-config' ) ) {
				return Promise.resolve( config( input.venue_term_id ) );
			}
			if ( request.path.includes( 'get-venue-booking-activity' ) ) {
				return Promise.resolve( bookingActivity() );
			}
			if ( request.path.includes( 'get-venue-booking' ) ) {
				return input.booking_id === 23
					? Promise.resolve( first )
					: pending.promise;
			}
			if ( request.path.includes( 'list-venue-bookings' ) ) {
				return Promise.resolve( [ first, second ] );
			}
			return Promise.resolve( [] );
		} );
		const { container, root } = await renderApp( context() );
		await act( async () =>
			buttonContaining( container, 'Loaded Artist' ).click()
		);
		expect( container.textContent ).toContain( 'Booking #23' );
		await act( async () =>
			buttonContaining( container, 'Pending Artist' ).click()
		);
		expect( container.textContent ).toContain(
			'Loading booking detail...'
		);
		await act( async () =>
			buttonByText( container, 'Close detail' ).click()
		);
		expect( container.textContent ).not.toContain(
			'Loading booking detail...'
		);
		expect( container.textContent ).not.toContain( 'Booking #23' );
		await act( async () => {
			pending.resolve( second );
			await pending.promise;
		} );
		expect( container.textContent ).not.toContain( 'Booking #24' );
		await act( async () => root.unmount() );
	} );

	it( 'refreshes the current selection after an earlier booking mutation resolves', async () => {
		const mutation = deferred();
		const detailIds = [];
		const first = { ...booking( 31 ), artist_name: 'Mutation Artist A' };
		const second = { ...booking( 32 ), artist_name: 'Mutation Artist B' };
		apiFetch.mockImplementation( ( request ) => {
			const input = request.data?.input || requestInput( request.path );
			if ( request.path.includes( 'get-venue-profile' ) ) {
				return Promise.resolve( profile( input.venue_term_id ) );
			}
			if ( request.path.includes( 'get-venue-booking-config' ) ) {
				return Promise.resolve( config( input.venue_term_id ) );
			}
			if ( request.path.includes( 'get-venue-booking-activity' ) ) {
				return Promise.resolve( bookingActivity() );
			}
			if ( request.path.includes( 'get-venue-booking' ) ) {
				detailIds.push( input.booking_id );
				return Promise.resolve(
					input.booking_id === 31 ? first : second
				);
			}
			if ( request.path.includes( 'transition-venue-booking' ) ) {
				return mutation.promise;
			}
			if ( request.path.includes( 'list-venue-bookings' ) ) {
				return Promise.resolve( [ first, second ] );
			}
			return Promise.resolve( [] );
		} );
		const { container, root } = await renderApp( context() );
		await act( async () =>
			buttonContaining( container, 'Mutation Artist A' ).click()
		);
		await act( async () => {
			const select = container.querySelector( '#booking-transition' );
			select.value = 'under_review';
			select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		} );
		await act( async () =>
			buttonByText( container, 'Apply transition' ).click()
		);
		await act( async () =>
			buttonContaining( container, 'Mutation Artist B' ).click()
		);
		expect( container.textContent ).toContain( 'Booking #32' );
		await act( async () => {
			mutation.resolve( {
				...first,
				status: 'under_review',
				version: 5,
			} );
			await mutation.promise;
			await Promise.resolve();
		} );
		expect( detailIds[ detailIds.length - 1 ] ).toBe( 32 );
		expect( container.textContent ).toContain( 'Booking #32' );
		await act( async () => root.unmount() );
	} );

	it( 'saves booking and intake settings in one revisioned document', async () => {
		installApi();
		apiFetch.mockImplementation( ( request ) => {
			const input = request.data?.input || requestInput( request.path );
			if ( request.path.includes( 'get-venue-profile' ) ) {
				return Promise.resolve( profile( input.venue_term_id ) );
			}
			if ( request.path.includes( 'get-venue-booking-config' ) ) {
				return Promise.resolve( config( input.venue_term_id ) );
			}
			if ( request.path.includes( 'update-venue-booking-config' ) ) {
				return Promise.resolve( {
					...input.config,
					revision: input.expected_revision + 1,
					updated_by_user_id: 7,
					updated_at: '2026-08-02 20:00:00',
				} );
			}
			return Promise.resolve( [] );
		} );
		const { container, root } = await renderApp( context() );
		await act( async () => buttonByText( container, 'Settings' ).click() );
		await setInput(
			container.querySelector( '#venue-ticket-provider' ),
			'venue-account'
		);
		await act( async () =>
			buttonByText( container, 'Add intake field' ).click()
		);
		await setInput(
			container.querySelector( '#intake-label-0' ),
			'Recent draw'
		);
		await act( async () =>
			buttonByText( container, 'Save settings' ).click()
		);

		const request = apiFetch.mock.calls.find( ( [ call ] ) =>
			call.path.includes( 'update-venue-booking-config' )
		)[ 0 ];
		expect( request.data.input.expected_revision ).toBe( 44 );
		expect( request.data.input.config.ticket_provider_reference ).toBe(
			'venue-account'
		);
		expect( request.data.input.config.intake.fields ).toEqual( [
			{
				key: 'recent_draw',
				label: 'Recent draw',
				type: 'text',
				required: false,
				options: [],
				visible_when: null,
			},
		] );
		expect( request.data.input.config ).not.toHaveProperty(
			'booking_guide'
		);
		expect( container.textContent ).toContain( 'Venue settings saved.' );
		await act( async () => root.unmount() );
	} );
} );
