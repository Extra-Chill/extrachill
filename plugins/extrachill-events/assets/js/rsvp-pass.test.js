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
		// Real setTimeout delays are collapsed to immediate execution so
		// tests stay deterministic without fighting fake-timer/Promise
		// interleaving; the 900ms/1500ms values themselves aren't the
		// behavior under test.
		jest.spyOn( global, 'setTimeout' ).mockImplementation( ( fn ) => {
			fn();
			return 0;
		} );
	} );

	afterEach( () => {
		jest.restoreAllMocks();
		delete global.wp;
		delete global.ecRsvpPass;
	} );

	it( 'reveals the pass after marking Going', async () => {
		mockApiFetch( () =>
			Promise.resolve( {
				issued: true,
				code: 'ABCDE-FGH2J-K3LMN',
				perk_text: 'First beer on Extra Chill',
			} )
		);
		loadRsvpPass( { eventId: 42 } );

		document
			.querySelector( '.ec-attendance__button' )
			.dispatchEvent( new Event( 'click', { bubbles: true } ) );
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

	it( 'hides the pass after unmarking Going', async () => {
		document.getElementById( 'ec-rsvp-pass-42' ).hidden = false;
		mockApiFetch( () => Promise.resolve( { issued: false } ) );
		loadRsvpPass( { eventId: 42 } );

		document
			.querySelector( '.ec-attendance__button' )
			.dispatchEvent( new Event( 'click', { bubbles: true } ) );
		await flushPromises();

		expect( document.getElementById( 'ec-rsvp-pass-42' ).hidden ).toBe(
			true
		);
	} );

	it( 'retries once when the pass has not committed yet', async () => {
		const responses = [ { issued: false }, { issued: true, code: 'X' } ];
		mockApiFetch( () => Promise.resolve( responses.shift() ) );
		loadRsvpPass( { eventId: 42 } );

		document
			.querySelector( '.ec-attendance__button' )
			.dispatchEvent( new Event( 'click', { bubbles: true } ) );
		await flushPromises();
		await flushPromises();

		expect( global.wp.apiFetch ).toHaveBeenCalledTimes( 2 );
		expect( document.getElementById( 'ec-rsvp-pass-42' ).hidden ).toBe(
			false
		);
	} );

	it( 'does nothing when no pass mount is present on the page', async () => {
		mockApiFetch( () => Promise.resolve( { issued: true, code: 'X' } ) );
		loadRsvpPass( { eventId: 999 } );

		document
			.querySelector( '.ec-attendance__button' )
			.dispatchEvent( new Event( 'click', { bubbles: true } ) );
		await flushPromises();

		// No mount for event 999 exists; nothing to assert beyond "no throw".
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
