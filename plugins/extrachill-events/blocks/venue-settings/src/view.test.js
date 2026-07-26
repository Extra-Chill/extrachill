/* global HTMLInputElement, afterAll, beforeAll, beforeEach, describe, expect, it, jest */

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { createRoot } from '@wordpress/element';

/**
 * External dependencies
 */
import { act } from 'react';

/**
 * Internal dependencies
 */
import { VenueSettingsApp } from './view';

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
		FieldGroup: ( { label, children } ) =>
			React.createElement( 'label', null, label, children ),
		InlineStatus: Wrapper,
		Panel: Wrapper,
		PanelHeader: ( { title } ) => React.createElement( 'h2', null, title ),
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
	version: 1,
	revision: id,
	updated_by_user_id: null,
	updated_at: null,
	enabled: false,
	intake: { version: 1, fields: [] },
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
	hold_ttl_minutes: 1440,
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
	...overrides,
} );

const requestInput = ( path ) => {
	const input = new URL( `https://events.example${ path }` ).searchParams.get(
		'input'
	);
	return input ? JSON.parse( input ) : {};
};

const installApi = () =>
	apiFetch.mockImplementation( ( request ) => {
		const input = requestInput( request.path );
		if ( request.path.includes( 'get-venue-profile' ) ) {
			return Promise.resolve( profile( input.venue_term_id ) );
		}
		if ( request.path.includes( 'get-venue-booking-config' ) ) {
			return Promise.resolve( config( input.venue_term_id ) );
		}
		if (
			request.path.includes( 'list-venue-memberships' ) ||
			request.path.includes( 'list-venue-invitations' ) ||
			request.path.includes( 'list-venue-claims' )
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

const setInput = async ( input, value ) => {
	await act( async () => {
		Object.getOwnPropertyDescriptor(
			HTMLInputElement.prototype,
			'value'
		).set.call( input, value );
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
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

	it( 'hides team controls from active non-owners', async () => {
		const { container, root } = await renderApp( context() );
		expect( container.textContent ).not.toContain( 'Team' );
		expect(
			apiFetch.mock.calls.some( ( [ request ] ) =>
				request.path.includes( 'list-venue-memberships' )
			)
		).toBe( false );
		await act( async () => root.unmount() );
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
		expect( container.textContent ).toContain(
			'Administrator claim review is available'
		);
		expect( container.textContent ).toContain( 'Claimant user #19' );
		expect(
			apiFetch.mock.calls.some( ( [ request ] ) =>
				request.path.includes( 'get-venue-profile' )
			)
		).toBe( false );
		await act( async () => root.unmount() );
	} );

	it( 'keeps successful config data available when profile loading fails', async () => {
		apiFetch.mockImplementation( ( request ) => {
			if ( request.path.includes( 'get-venue-profile' ) ) {
				return Promise.reject( { message: 'Profile unavailable.' } );
			}
			if ( request.path.includes( 'get-venue-booking-config' ) ) {
				const input = requestInput( request.path );
				return Promise.resolve( config( input.venue_term_id ) );
			}
			return Promise.resolve( [] );
		} );
		const { container, root } = await renderApp( context() );
		expect( container.textContent ).toContain( 'Profile unavailable.' );
		expect( container.textContent ).toContain( 'Retry profile' );
		await act( async () => root.unmount() );
	} );

	it( 'retrying profile preserves dirty booking settings', async () => {
		let profileAttempts = 0;
		let configAttempts = 0;
		apiFetch.mockImplementation( ( request ) => {
			const input = requestInput( request.path );
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
		await act( async () => buttonByText( container, 'Booking' ).click() );
		await setInput(
			container.querySelector( '#venue-ticket-provider' ),
			'dirty-ticket-account'
		);
		await act( async () => buttonByText( container, 'Profile' ).click() );
		await act( async () => {
			buttonByText( container, 'Retry profile' ).click();
			await Promise.resolve();
		} );
		await act( async () => buttonByText( container, 'Booking' ).click() );
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
			const input = requestInput( request.path );
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
		await setInput(
			container.querySelector( '#venue-profile-name' ),
			'Locally edited venue'
		);
		await act( async () => buttonByText( container, 'Booking' ).click() );
		await act( async () => {
			buttonByText( container, 'Retry booking settings' ).click();
			await Promise.resolve();
		} );
		await act( async () => buttonByText( container, 'Profile' ).click() );
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
			const input = requestInput( request.path );
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
			.map(
				( [ request ] ) => requestInput( request.path ).venue_term_id
			);
		expect( venueInputs ).toEqual( [ 44, 44, 88, 88 ] );
		await act( async () => second.root.unmount() );
	} );
} );
