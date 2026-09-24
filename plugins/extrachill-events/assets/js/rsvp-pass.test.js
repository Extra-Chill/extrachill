/**
 * RSVP pass watcher + door list redeem coverage.
 */
/* global afterEach, beforeEach, describe, expect, it, jest */

async function flushPromises() {
	await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
}

function mockApiFetch( implementation ) {
	global.wp = { apiFetch: jest.fn( implementation ) };
}

function loadRsvpPass( { eventId } = {} ) {
	global.ecRsvpPass = { eventId };
	// The script runs its init immediately (footer-loaded, see source
	// docblock), so requiring it is sufficient — no DOMContentLoaded to
	// simulate.
	jest.isolateModules( () => require( './rsvp-pass' ) );
}

/**
 * Dispatch the real cross-plugin contract, matching extrachill-users'
 * dispatchAttendanceChanged() (blocks/concert-attendance/src/dispatchAttendanceChanged.js).
 *
 * @param {Object}  detail
 * @param {number}  detail.eventId  Event post ID.
 * @param {number}  [detail.blogId] Blog ID the event lives on.
 * @param {boolean} detail.marked   Attendance state after the request resolved.
 */
function fireAttendanceChanged( { eventId, blogId = 7, marked } ) {
	document.dispatchEvent(
		new CustomEvent( 'ec:attendance-changed', {
			detail: { eventId, blogId, marked },
		} )
	);
}

describe( 'RSVP pass watcher', () => {
	beforeEach( () => {
		document.body.innerHTML = `
			<button type="button" class="ec-attendance__button button-3 button-medium" aria-pressed="false">
				<span class="ec-attendance__label">Going</span>
			</button>
			<div id="ec-rsvp-pass-42" class="ec-rsvp-pass ec-surface-card" data-event-id="42" hidden>
				<p class="ec-rsvp-pass__perk"></p>
				<p class="ec-rsvp-pass__label">Show this pass at the door:</p>
				<p class="ec-rsvp-pass__code"></p>
			</div>`;
	} );

	afterEach( () => {
		jest.restoreAllMocks();
		delete global.wp;
		delete global.ecRsvpPass;
	} );

	it( 'reveals the pass after an ec:attendance-changed(marked: true) event', async () => {
		mockApiFetch( () =>
			Promise.resolve( {
				issued: true,
				code: 'ABCDE-FGH2J-K3LMN',
				perk_text: 'First beer on Extra Chill',
			} )
		);
		loadRsvpPass( { eventId: 42 } );

		fireAttendanceChanged( { eventId: 42, marked: true } );
		await flushPromises();

		const container = document.getElementById( 'ec-rsvp-pass-42' );
		expect( container.hidden ).toBe( false );
		expect(
			container.querySelector( '.ec-rsvp-pass__code' ).textContent
		).toBe( 'ABCDE-FGH2J-K3LMN' );
		expect(
			container.querySelector( '.ec-rsvp-pass__perk' ).textContent
		).toBe( 'First beer on Extra Chill' );
	} );

	it( 'hides the pass after an ec:attendance-changed(marked: false) event', async () => {
		document.getElementById( 'ec-rsvp-pass-42' ).hidden = false;
		mockApiFetch( () => Promise.resolve( { issued: false } ) );
		loadRsvpPass( { eventId: 42 } );

		fireAttendanceChanged( { eventId: 42, marked: false } );
		await flushPromises();

		expect( document.getElementById( 'ec-rsvp-pass-42' ).hidden ).toBe(
			true
		);
	} );

	it( 'ignores ec:attendance-changed for a different event on the same page load', async () => {
		mockApiFetch( () => Promise.resolve( { issued: true, code: 'X' } ) );
		loadRsvpPass( { eventId: 42 } );

		fireAttendanceChanged( { eventId: 999, marked: true } );
		await flushPromises();

		expect( global.wp.apiFetch ).not.toHaveBeenCalled();
		expect( document.getElementById( 'ec-rsvp-pass-42' ).hidden ).toBe(
			true
		);
	} );

	it( 'renders correctly once a deliberately slow pass-state fetch resolves — no premature render, no timer guess', async () => {
		let resolveFetch;
		const pending = new Promise( ( resolve ) => {
			resolveFetch = resolve;
		} );
		mockApiFetch( () => pending );
		loadRsvpPass( { eventId: 42 } );

		fireAttendanceChanged( { eventId: 42, marked: true } );
		await flushPromises();

		// The fetch this listener made has not resolved yet — the pass
		// must still be hidden. This is the guarantee that replaces the
		// old fixed 900ms/1500ms timing guess: correctness does not depend
		// on how long anything takes, only on real resolution.
		expect( document.getElementById( 'ec-rsvp-pass-42' ).hidden ).toBe(
			true
		);

		resolveFetch( { issued: true, code: 'SLOW-BAR-WIFI' } );
		await pending;
		await flushPromises();

		const container = document.getElementById( 'ec-rsvp-pass-42' );
		expect( container.hidden ).toBe( false );
		expect(
			container.querySelector( '.ec-rsvp-pass__code' ).textContent
		).toBe( 'SLOW-BAR-WIFI' );
	} );

	it( 'does nothing when the attendance-changed event never fires (older extrachill-users deployed) — server-rendered pass stands', async () => {
		document.getElementById( 'ec-rsvp-pass-42' ).hidden = false;
		mockApiFetch( () => Promise.resolve( { issued: true, code: 'X' } ) );
		loadRsvpPass( { eventId: 42 } );

		// No event ever fires. Nothing should throw, and the
		// server-rendered initial state (visible, from PHP) must stand.
		await flushPromises();

		expect( global.wp.apiFetch ).not.toHaveBeenCalled();
		expect( document.getElementById( 'ec-rsvp-pass-42' ).hidden ).toBe(
			false
		);
	} );

	it( 'does not throw when no pass mount is present on the page', async () => {
		mockApiFetch( () => Promise.resolve( { issued: true, code: 'X' } ) );
		loadRsvpPass( { eventId: 999 } );

		expect( () =>
			fireAttendanceChanged( { eventId: 999, marked: true } )
		).not.toThrow();
		await flushPromises();

		expect( global.wp.apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				path: '/wp-abilities/v1/abilities/extrachill/get-my-event-pass/run',
				data: { input: { event_id: 999 } },
			} )
		);
	} );
} );

describe( 'RSVP door list redeem', () => {
	beforeEach( () => {
		document.body.innerHTML = `
			<div class="ec-door-list ec-surface-card" data-event-id="42">
				<ul class="ec-door-list__rows">
					<li class="ec-door-list__row" data-user-id="7">
						<span class="ec-door-list__name">Chris Gardner</span>
						<button type="button" class="button-2 button-small ec-door-list__redeem" data-user-id="7">Redeem</button>
					</li>
				</ul>
			</div>`;
	} );

	afterEach( () => {
		jest.restoreAllMocks();
		delete global.wp;
		delete global.ecRsvpPass;
	} );

	it( 'replaces the Redeem button with a redeemed status on success', async () => {
		mockApiFetch( () =>
			Promise.resolve( {
				already_redeemed: false,
				redeemed_at: '2026-10-21 19:05:00',
				redeemed_by_user_id: 3,
			} )
		);
		loadRsvpPass( {} );

		document
			.querySelector( '.ec-door-list__redeem' )
			.dispatchEvent( new Event( 'click', { bubbles: true } ) );
		await flushPromises();

		expect( global.wp.apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				path: '/wp-abilities/v1/abilities/extrachill/redeem-event-pass/run',
				data: { input: { event_id: 42, user_id: 7 } },
			} )
		);
		expect( document.querySelector( '.ec-door-list__redeem' ) ).toBeNull();
		expect(
			document.querySelector( '.ec-door-list__status' ).textContent
		).toBe( 'Redeemed 2026-10-21 19:05:00' );
	} );

	it( 're-enables the Redeem button when the request fails', async () => {
		mockApiFetch( () => Promise.reject( new Error( 'network error' ) ) );
		loadRsvpPass( {} );

		const button = document.querySelector( '.ec-door-list__redeem' );
		button.dispatchEvent( new Event( 'click', { bubbles: true } ) );
		await flushPromises();

		expect( button.disabled ).toBe( false );
	} );
} );
