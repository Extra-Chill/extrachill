/* global HTMLInputElement, HTMLTextAreaElement, afterAll, afterEach, beforeAll, beforeEach, describe, expect, it, jest */

/**
 * WordPress dependencies
 */
import { createRoot } from '@wordpress/element';

/**
 * External dependencies
 */
import { act } from 'react';

/**
 * Internal dependencies
 */
import { BookingFollowThrough, ReceiptRecovery } from './follow-through-view';
import { receiptStorageKey, saveReceipt } from './follow-through';
import { BookingInquiry } from './view';
import { DRAFT_VERSION, draftStorageKey } from './submission';

jest.mock( '@extrachill/components', () => {
	const React = require( 'react' );
	const Wrapper = ( { children } ) =>
		React.createElement( 'div', null, children );
	return {
		ActionRow: Wrapper,
		BlockShell: Wrapper,
		BlockShellHeader: ( { title } ) =>
			React.createElement( 'div', null, title ),
		BlockShellInner: Wrapper,
		Grid: Wrapper,
		Panel: Wrapper,
		FieldGroup: ( { label, htmlFor, help, children } ) =>
			React.createElement(
				'div',
				null,
				React.createElement( 'label', { htmlFor }, label ),
				children,
				help && React.createElement( 'p', null, help )
			),
		InlineStatus: ( { children } ) =>
			React.createElement( 'div', null, children ),
		Modal: ( { title, isOpen, onClose, children } ) =>
			isOpen
				? React.createElement(
						'div',
						{ role: 'dialog', 'aria-label': title },
						React.createElement(
							'button',
							{ type: 'button', onClick: onClose },
							'Close'
						),
						children
				  )
				: null,
	};
} );

const publicId = '123e4567-e89b-42d3-a456-426614174000';
const capability = 'a'.repeat( 64 );
const receipt = { public_id: publicId, venue_term_id: 42, capability };
const config = {
	instanceId: 'booking-test',
	venue: { id: 42, name: 'The Room' },
	restNonce: 'nonce',
	followThrough: {
		status: '/status',
		correction: '/correction',
		withdrawal: '/withdrawal',
		receiptRecovery: '/recovery',
	},
	endpoint: '/admission',
	availabilityEndpoint: '/availability',
	revision: 7,
	buttonLabel: 'Send booking inquiry',
	spaces: [ { key: 'main', name: 'Main Room', is_default: true } ],
	fields: [],
	consent: { id: 'privacy', version: 1, required: true, label: 'Agree' },
};
const inquiry = ( overrides = {} ) => ( {
	public_id: publicId,
	status: 'submitted',
	status_label: 'Pending review',
	version: 2,
	requested_interval: { start_at: '2026-09-01 00:00:00', end_at: null },
	requested_space: { key: 'main', label: 'Main Room' },
	permitted_actions: [ 'request_correction', 'withdraw' ],
	...overrides,
} );
const response = ( payload, status = 200 ) =>
	Promise.resolve( {
		ok: status >= 200 && status < 300,
		status,
		headers: new Headers(),
		json: () => Promise.resolve( payload ),
	} );
const flush = () => new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

const render = async ( node, withTurnstile = false ) => {
	const wrapper = document.createElement( 'section' );
	if ( withTurnstile ) {
		wrapper.innerHTML =
			'<div data-booking-turnstile><div class="cf-turnstile"><input name="cf-turnstile-response" value="challenge"></div></div>';
	}
	const container = document.createElement( 'div' );
	wrapper.appendChild( container );
	document.body.appendChild( wrapper );
	const root = createRoot( container );
	await act( async () => {
		root.render( node( wrapper ) );
		await flush();
	} );
	return { container, root, wrapper };
};
const button = ( container, label ) =>
	[ ...container.querySelectorAll( 'button' ) ].find(
		( item ) => item.textContent === label
	);
// The inquiry form lives in a modal behind the block's inquiry button.
const openBookingForm = async ( container ) =>
	act( async () => {
		button( container, 'Start a booking inquiry' ).click();
		await flush();
	} );
const addToken = ( wrapper, value ) => {
	const input = document.createElement( 'input' );
	input.name = 'cf-turnstile-response';
	input.value = value;
	wrapper.querySelector( '.cf-turnstile' ).appendChild( input );
};

describe( 'artist booking inquiry follow-through UI', () => {
	beforeAll( () => {
		global.IS_REACT_ACT_ENVIRONMENT = true;
	} );
	afterAll( () => {
		delete global.IS_REACT_ACT_ENVIRONMENT;
	} );
	beforeEach( () => {
		window.fetch = jest.fn();
		sessionStorage.clear();
		localStorage.clear();
	} );
	afterEach( () => {
		document.body.replaceChildren();
	} );

	it( 'renders the caller-selected semantic heading with an instance-scoped id', async () => {
		const { container, root } = await render( ( wrapper ) => (
			<>
				<BookingInquiry
					config={ { ...config, headingLevel: 4 } }
					wrapper={ wrapper }
				/>
				<BookingInquiry
					config={ {
						...config,
						instanceId: 'booking-test-secondary',
						headingLevel: 2,
					} }
					wrapper={ wrapper }
				/>
			</>
		) );
		const heading = container.querySelector( 'h4' );
		expect( heading.id ).toBe( 'booking-test-heading' );
		expect( heading.textContent ).toBe( 'Booking at The Room' );
		expect(
			container.querySelectorAll( '#booking-test-heading' )
		).toHaveLength( 1 );
		expect( container.querySelector( 'h2' ).id ).toBe(
			'booking-test-secondary-heading'
		);
		await act( async () => root.unmount() );
	} );

	it( 'loads anonymous status with body authority and renders only permitted actions', async () => {
		window.fetch.mockImplementation( () => response( inquiry() ) );
		const { container, root } = await render(
			( wrapper ) => (
				<BookingFollowThrough
					config={ config }
					receipt={ receipt }
					wrapper={ wrapper }
					onClear={ jest.fn() }
				/>
			),
			true
		);
		const [ url, options ] = window.fetch.mock.calls[ 0 ];
		expect( url ).toBe( '/status' );
		expect( url ).not.toContain( capability );
		expect( options.credentials ).toBe( 'same-origin' );
		expect( options.headers[ 'X-WP-Nonce' ] ).toBe( 'nonce' );
		expect( JSON.parse( options.body ) ).toEqual( {
			public_id: publicId,
			capability,
		} );
		expect( button( container, 'Withdraw inquiry' ) ).toBeTruthy();
		expect( button( container, 'Request cancellation' ) ).toBeFalsy();
		expect( button( container, 'Refresh status' ).type ).toBe( 'button' );
		expect(
			container.querySelector( '[aria-live="polite"]' )
		).toBeTruthy();
		await act( async () => root.unmount() );
	} );

	it( 'sends correction and withdrawal with optimistic versions and fresh keys', async () => {
		window.fetch
			.mockImplementationOnce( () => response( inquiry() ) )
			.mockImplementationOnce( () =>
				response( {
					public_id: publicId,
					operation: 'correction_requested',
					version: 2,
				} )
			)
			.mockImplementationOnce( () => response( inquiry() ) )
			.mockImplementationOnce( () =>
				response( {
					public_id: publicId,
					operation: 'withdrawn',
					status: 'withdrawn',
					version: 3,
				} )
			)
			.mockImplementationOnce( () =>
				response(
					inquiry( {
						status: 'withdrawn',
						status_label: 'Withdrawn',
						version: 3,
						permitted_actions: [],
					} )
				)
			);
		const { container, root, wrapper } = await render(
			( currentWrapper ) => (
				<BookingFollowThrough
					config={ config }
					receipt={ receipt }
					wrapper={ currentWrapper }
					onClear={ jest.fn() }
				/>
			),
			true
		);
		await act( async () =>
			button( container, 'Request correction' ).click()
		);
		const textarea = container.querySelector( 'textarea' );
		await act( async () => {
			Object.getOwnPropertyDescriptor(
				HTMLTextAreaElement.prototype,
				'value'
			).set.call( textarea, 'Change the date note.' );
			textarea.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );
		await act( async () => {
			button( container, 'Send correction request' ).click();
			await flush();
		} );
		const correction = JSON.parse( window.fetch.mock.calls[ 1 ][ 1 ].body );
		expect( correction ).toMatchObject( {
			capability,
			expected_version: 2,
			correction: 'Change the date note.',
			turnstile_response: 'challenge',
		} );
		addToken( wrapper, 'fresh-withdrawal-challenge' );
		await act( async () => {
			button( container, 'Withdraw inquiry' ).click();
		} );
		expect( window.fetch ).toHaveBeenCalledTimes( 3 );
		expect( document.activeElement.getAttribute( 'role' ) ).toBe(
			'status'
		);
		await act( async () => {
			button( container, 'Confirm withdrawal' ).click();
			await flush();
		} );
		const withdrawal = JSON.parse( window.fetch.mock.calls[ 3 ][ 1 ].body );
		expect( withdrawal.expected_version ).toBe( 2 );
		expect( withdrawal.turnstile_response ).toBe(
			'fresh-withdrawal-challenge'
		);
		expect( withdrawal.idempotency_key ).not.toBe(
			correction.idempotency_key
		);
		expect( container.textContent ).toContain(
			'Your inquiry was withdrawn.'
		);
		await act( async () => root.unmount() );
	} );

	it( 'refreshes stale versions and offers privacy-safe recovery for wrong authority', async () => {
		window.fetch
			.mockImplementationOnce( () => response( inquiry() ) )
			.mockImplementationOnce( () =>
				response(
					{ code: 'booking_version_conflict', current_version: 3 },
					409
				)
			)
			.mockImplementationOnce( () =>
				response( inquiry( { version: 3 } ) )
			);
		const first = await render(
			( wrapper ) => (
				<BookingFollowThrough
					config={ config }
					receipt={ receipt }
					wrapper={ wrapper }
					onClear={ jest.fn() }
				/>
			),
			true
		);
		await act( async () => {
			button( first.container, 'Withdraw inquiry' ).click();
		} );
		await act( async () => {
			button( first.container, 'Confirm withdrawal' ).click();
			await flush();
		} );
		expect( window.fetch ).toHaveBeenCalledTimes( 3 );
		expect( first.container.textContent ).toContain(
			'Review the refreshed status'
		);
		await act( async () => first.root.unmount() );

		saveReceipt( config, receipt );
		window.fetch
			.mockReset()
			.mockImplementation( () =>
				response( { code: 'booking_inquiry_unavailable' }, 404 )
			);
		const second = await render( ( wrapper ) => (
			<BookingFollowThrough
				config={ config }
				receipt={ receipt }
				wrapper={ wrapper }
				onClear={ jest.fn() }
			/>
		) );
		expect( second.container.textContent ).not.toContain( 'Mismatch' );
		expect( second.container.textContent ).toContain(
			'Request a new confirmation email'
		);
		expect(
			button( second.container, 'Request access email' )
		).toBeTruthy();
		expect(
			sessionStorage.getItem( receiptStorageKey( config ) )
		).toBeNull();
		expect( document.activeElement.getAttribute( 'role' ) ).toBe(
			'status'
		);
		await act( async () => second.root.unmount() );
	} );

	it( 'sends confirmed cancellation as a Turnstile-backed request, not immediate withdrawal', async () => {
		const confirmed = inquiry( {
			status: 'confirmed',
			status_label: 'Confirmed',
			version: 5,
			permitted_actions: [ 'request_correction', 'request_cancellation' ],
		} );
		window.fetch
			.mockImplementationOnce( () => response( confirmed ) )
			.mockImplementationOnce( () =>
				response( {
					public_id: publicId,
					operation: 'cancellation_requested',
					version: 5,
				} )
			)
			.mockImplementationOnce( () => response( confirmed ) );
		const { container, root } = await render(
			( wrapper ) => (
				<BookingFollowThrough
					config={ config }
					receipt={ receipt }
					wrapper={ wrapper }
					onClear={ jest.fn() }
				/>
			),
			true
		);
		expect( container.textContent ).toContain(
			'does not immediately cancel a confirmed booking'
		);
		await act( async () => {
			button( container, 'Request cancellation' ).click();
			await flush();
		} );
		const payload = JSON.parse( window.fetch.mock.calls[ 1 ][ 1 ].body );
		expect( payload ).toMatchObject( {
			expected_version: 5,
			turnstile_response: 'challenge',
		} );
		expect( container.textContent ).toContain(
			'cancellation request was sent'
		);
		await act( async () => root.unmount() );
	} );

	it( 'retries an uncertain mutation with the same key and a fresh challenge', async () => {
		window.fetch
			.mockImplementationOnce( () => response( inquiry() ) )
			.mockRejectedValueOnce( new Error( 'network interrupted' ) )
			.mockImplementationOnce( () =>
				response( {
					public_id: publicId,
					operation: 'correction_requested',
					version: 2,
				} )
			)
			.mockImplementationOnce( () => response( inquiry() ) );
		const { container, root, wrapper } = await render(
			( currentWrapper ) => (
				<BookingFollowThrough
					config={ config }
					receipt={ receipt }
					wrapper={ currentWrapper }
					onClear={ jest.fn() }
				/>
			),
			true
		);
		await act( async () =>
			button( container, 'Request correction' ).click()
		);
		const textarea = container.querySelector( 'textarea' );
		await act( async () => {
			Object.getOwnPropertyDescriptor(
				HTMLTextAreaElement.prototype,
				'value'
			).set.call( textarea, 'Correct the contact note.' );
			textarea.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );
		await act( async () => {
			button( container, 'Send correction request' ).click();
			await flush();
		} );
		const uncertain = JSON.parse( window.fetch.mock.calls[ 1 ][ 1 ].body );
		addToken( wrapper, 'retry-challenge' );
		await act( async () => {
			button( container, 'Send correction request' ).click();
			await flush();
		} );
		const retry = JSON.parse( window.fetch.mock.calls[ 2 ][ 1 ].body );
		expect( retry.idempotency_key ).toBe( uncertain.idempotency_key );
		expect( retry.turnstile_response ).toBe( 'retry-challenge' );
		await act( async () => root.unmount() );
	} );

	it( 'distinguishes recovery admission from transport failure and safely retries one key', async () => {
		window.fetch
			.mockImplementationOnce( () =>
				response( { code: 'service_unavailable' }, 503 )
			)
			.mockImplementationOnce( () =>
				response( { accepted: true }, 202 )
			);
		const { container, root, wrapper } = await render(
			( currentWrapper ) => (
				<ReceiptRecovery
					config={ config }
					wrapper={ currentWrapper }
					initialReference={ publicId }
				/>
			),
			true
		);
		const email = container.querySelector( 'input[type="email"]' );
		await act( async () => {
			Object.getOwnPropertyDescriptor(
				HTMLInputElement.prototype,
				'value'
			).set.call( email, 'artist@example.com' );
			email.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );
		await act( async () => {
			button( container, 'Email my access code' ).click();
			await flush();
		} );
		const body = JSON.parse( window.fetch.mock.calls[ 0 ][ 1 ].body );
		expect( body ).toMatchObject( {
			public_id: publicId,
			contact_email: 'artist@example.com',
			turnstile_response: 'challenge',
		} );
		expect( email.value ).toBe( 'artist@example.com' );
		expect( container.textContent ).toContain(
			'recovery service is temporarily unavailable'
		);
		expect( container.textContent ).not.toContain(
			'If those details match an inquiry'
		);
		addToken( wrapper, 'fresh-recovery-challenge' );
		await act( async () => {
			button( container, 'Email my access code' ).click();
			await flush();
		} );
		const retry = JSON.parse( window.fetch.mock.calls[ 1 ][ 1 ].body );
		expect( retry.idempotency_key ).toBe( body.idempotency_key );
		expect( retry.turnstile_response ).toBe( 'fresh-recovery-challenge' );
		expect( email.value ).toBe( '' );
		expect( container.textContent ).toContain(
			'If those details match an inquiry'
		);
		expect( document.activeElement.getAttribute( 'role' ) ).toBe(
			'status'
		);
		await act( async () => root.unmount() );
	} );

	it( 'keeps stale actions disabled when the required refresh fails', async () => {
		window.fetch
			.mockImplementationOnce( () => response( inquiry() ) )
			.mockImplementationOnce( () =>
				response( { code: 'booking_version_conflict' }, 409 )
			)
			.mockImplementationOnce( () =>
				response( { code: 'service_unavailable' }, 503 )
			);
		const { container, root } = await render(
			( wrapper ) => (
				<BookingFollowThrough
					config={ config }
					receipt={ receipt }
					wrapper={ wrapper }
					onClear={ jest.fn() }
				/>
			),
			true
		);
		await act( async () =>
			button( container, 'Withdraw inquiry' ).click()
		);
		await act( async () => {
			button( container, 'Confirm withdrawal' ).click();
			await flush();
		} );
		expect( container.textContent ).toContain(
			'inquiry service is temporarily unavailable'
		);
		expect( container.textContent ).not.toContain(
			'Review the refreshed status'
		);
		expect( button( container, 'Confirm withdrawal' ).disabled ).toBe(
			true
		);
		await act( async () => root.unmount() );
	} );

	it( 'opens a recovery email in a new tab with body-only access and persists after validation', async () => {
		window.fetch.mockImplementation( () => response( inquiry() ) );
		const onAccess = jest.fn();
		const anonymousConfig = { ...config, restNonce: '' };
		const { container, root } = await render( ( wrapper ) => (
			<ReceiptRecovery
				config={ anonymousConfig }
				wrapper={ wrapper }
				initialReference={ publicId }
				onAccess={ onAccess }
			/>
		) );
		const code = container.querySelector(
			'#booking-test-recovery-access-code'
		);
		await act( async () => {
			Object.getOwnPropertyDescriptor(
				HTMLInputElement.prototype,
				'value'
			).set.call( code, capability );
			code.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );
		await act( async () => {
			button( container, 'Open inquiry' ).click();
			await flush();
		} );
		const [ url, options ] = window.fetch.mock.calls[ 0 ];
		expect( url ).toBe( '/status' );
		expect( url ).not.toContain( capability );
		expect( JSON.parse( options.body ) ).toEqual( {
			public_id: publicId,
			capability,
		} );
		expect( onAccess ).toHaveBeenCalledWith( receipt, inquiry() );
		expect(
			JSON.parse( sessionStorage.getItem( receiptStorageKey( config ) ) )
		).toEqual( receipt );
		await act( async () => root.unmount() );
	} );

	it( 'opens an authenticated new-tab reference without an access code', async () => {
		window.fetch.mockImplementation( () => response( inquiry() ) );
		const onAccess = jest.fn();
		const { container, root } = await render( ( wrapper ) => (
			<ReceiptRecovery
				config={ config }
				wrapper={ wrapper }
				initialReference={ publicId }
				onAccess={ onAccess }
			/>
		) );
		expect(
			container.querySelector( '#booking-test-recovery-access-code' )
		).toBeNull();
		expect( container.textContent ).toContain(
			'signed-in account authorizes'
		);
		await act( async () => {
			button( container, 'Open inquiry' ).click();
			await flush();
		} );
		const [ url, options ] = window.fetch.mock.calls[ 0 ];
		expect( url ).toBe( '/status' );
		expect( options.credentials ).toBe( 'same-origin' );
		expect( options.headers[ 'X-WP-Nonce' ] ).toBe( 'nonce' );
		expect( JSON.parse( options.body ) ).toEqual( { public_id: publicId } );
		expect( onAccess ).toHaveBeenCalledWith(
			{ public_id: publicId, venue_term_id: 42 },
			inquiry()
		);
		await act( async () => root.unmount() );
	} );

	it( 'preserves terminal receipt history across reload until explicit clear', async () => {
		const terminal = inquiry( {
			status: 'completed',
			status_label: 'Completed',
			permitted_actions: [],
		} );
		saveReceipt( config, receipt );
		window.fetch.mockImplementation( () => response( terminal ) );
		const first = await render( ( wrapper ) => (
			<BookingFollowThrough
				config={ config }
				receipt={ receipt }
				wrapper={ wrapper }
				onClear={ jest.fn() }
			/>
		) );
		expect( first.container.textContent ).toContain( 'Completed' );
		expect(
			sessionStorage.getItem( receiptStorageKey( config ) )
		).not.toBeNull();
		await act( async () => first.root.unmount() );
		const restored = await render( ( wrapper ) => (
			<BookingFollowThrough
				config={ config }
				receipt={ receipt }
				wrapper={ wrapper }
				onClear={ jest.fn() }
			/>
		) );
		expect( restored.container.textContent ).toContain( 'Completed' );
		expect(
			sessionStorage.getItem( receiptStorageKey( config ) )
		).not.toBeNull();
		await act( async () => restored.root.unmount() );
	} );

	it( 'clears receipt, both draft scopes, and restored private form state', async () => {
		const draft = {
			version: DRAFT_VERSION,
			venueId: 42,
			revision: 7,
			scope: 'session',
			savedAt: Date.now(),
			values: {
				artistName: 'Private Artist',
				contactName: 'Private Contact',
				contactEmail: 'private@example.com',
				contactPhone: '',
				spaceKey: 'main',
				requestedDate: '2030-08-01',
				message: 'Private pitch',
				fields: {},
			},
		};
		sessionStorage.setItem(
			draftStorageKey( config ),
			JSON.stringify( draft )
		);
		localStorage.setItem(
			draftStorageKey( config ),
			JSON.stringify( { ...draft, scope: 'device' } )
		);
		saveReceipt( config, receipt );
		window.fetch.mockImplementation( () => response( inquiry() ) );
		const { container, root } = await render(
			( wrapper ) => (
				<BookingInquiry config={ config } wrapper={ wrapper } />
			),
			true
		);
		await act( async () => {
			button( container, 'Clear this receipt' ).click();
			await flush();
		} );
		expect(
			sessionStorage.getItem( receiptStorageKey( config ) )
		).toBeNull();
		expect(
			sessionStorage.getItem( draftStorageKey( config ) )
		).toBeNull();
		expect( localStorage.getItem( draftStorageKey( config ) ) ).toBeNull();
		expect( container.textContent ).toContain(
			'Receipt and saved form details cleared.'
		);
		await openBookingForm( container );
		expect( container.querySelector( 'input[type="date"]' ).value ).toBe(
			''
		);
		await act( async () => root.unmount() );
	} );

	it( 'warns when receipt or draft storage removal cannot be confirmed', async () => {
		saveReceipt( config, receipt );
		window.fetch.mockImplementation( () => response( inquiry() ) );
		const { container, root } = await render(
			( wrapper ) => (
				<BookingInquiry config={ config } wrapper={ wrapper } />
			),
			true
		);
		const removeItem = Storage.prototype.removeItem;
		Storage.prototype.removeItem = () => {
			throw new Error( 'removal denied' );
		};
		try {
			await act( async () => {
				button( container, 'Clear this receipt' ).click();
				await flush();
			} );
			expect( container.textContent ).toContain(
				'browser storage may remain'
			);
			expect( container.textContent ).toContain(
				"clear this device's site data"
			);
			await openBookingForm( container );
			expect(
				container.querySelector( 'input[type="date"]' ).value
			).toBe( '' );
		} finally {
			Storage.prototype.removeItem = removeItem;
		}
		await act( async () => root.unmount() );
	} );
	it( 'keeps the inquiry form in a modal behind the block trigger', async () => {
		const { container, root } = await render( ( wrapper ) => (
			<BookingInquiry config={ config } wrapper={ wrapper } />
		) );

		expect( container.querySelector( '[role="dialog"]' ) ).toBeNull();
		expect( container.querySelector( 'input[type="date"]' ) ).toBeNull();
		expect( button( container, 'Start a booking inquiry' ) ).toBeTruthy();

		await openBookingForm( container );

		const dialog = container.querySelector( '[role="dialog"]' );
		expect( dialog ).toBeTruthy();
		expect( dialog.getAttribute( 'aria-label' ) ).toBe( 'Booking inquiry' );
		expect( container.querySelector( 'input[type="date"]' ) ).toBeTruthy();

		await act( async () => {
			button( container, 'Cancel' ).click();
			await flush();
		} );

		expect( container.querySelector( '[role="dialog"]' ) ).toBeNull();
		expect( container.querySelector( 'input[type="date"]' ) ).toBeNull();
		await act( async () => root.unmount() );
	} );
} );
