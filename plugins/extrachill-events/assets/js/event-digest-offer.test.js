/**
 * Single-event Local Scene digest offer behaviour (#962, users#450).
 */
/* global afterEach, beforeEach, describe, expect, it, jest */

function mount( mode = 'subscribe' ) {
	document.body.innerHTML = `
		<aside data-event-digest-offer data-event-digest-mode="${ mode }" data-event-digest-city="charleston" data-post-mark-heading="You're in. Get the rest of this week's shows in Charleston by email">
			<p data-event-digest-heading><strong>Get this week's shows in Charleston by email</strong></p>
			<form>
				<input type="hidden" value="event_page" data-event-digest-surface>
				<button type="submit">Email me the weekly shows</button>
				<button type="button" data-event-digest-dismiss hidden>Not now</button>
			</form>
		</aside>`;
	jest.isolateModules( () => require( './event-digest-offer' ) );
	return document.querySelector( '[data-event-digest-offer]' );
}

function fireAttendanceChanged( detail ) {
	document.dispatchEvent(
		new CustomEvent( 'ec:attendance-changed', {
			detail: { eventId: 1, blogId: 7, ...detail },
		} )
	);
}

describe( 'event digest offer', () => {
	beforeEach( () => window.localStorage.clear() );
	afterEach( () => {
		document.body.innerHTML = '';
	} );

	it( 'reveals the dismiss control and remembers dismissal per city', () => {
		const offer = mount();
		const dismiss = offer.querySelector( '[data-event-digest-dismiss]' );
		expect( dismiss.hidden ).toBe( false );

		dismiss.click();

		expect( offer.hidden ).toBe( true );
		expect(
			window.localStorage.getItem( 'ecEventDigestDismissed:charleston' )
		).toBe( '1' );
		expect( mount().hidden ).toBe( true );
	} );

	it( 'reframes the offer after a mark and tags the post_mark surface', () => {
		const offer = mount();

		fireAttendanceChanged( { marked: true, firstMark: false } );

		expect( offer.querySelector( 'strong' ).textContent ).toBe(
			"You're in. Get the rest of this week's shows in Charleston by email"
		);
		expect(
			offer.querySelector( '[data-event-digest-surface]' ).value
		).toBe( 'post_mark' );
	} );

	it( 'ignores unmarks', () => {
		const offer = mount();

		fireAttendanceChanged( { marked: false, firstMark: false } );

		expect(
			offer.querySelector( '[data-event-digest-surface]' ).value
		).toBe( 'event_page' );
	} );

	it( 'a first-ever mark brings a dismissed offer back once; later marks do not', () => {
		window.localStorage.setItem( 'ecEventDigestDismissed:charleston', '1' );
		let offer = mount();
		fireAttendanceChanged( { marked: true, firstMark: false } );
		expect( offer.hidden ).toBe( true );

		fireAttendanceChanged( { marked: true, firstMark: true } );
		expect( offer.hidden ).toBe( false );
		expect(
			window.localStorage.getItem( 'ecEventDigestDismissed:charleston' )
		).toBeNull();

		offer = mount( 'subscribed' );
		expect(
			offer.querySelector( '[data-event-digest-dismiss]' ).hidden
		).toBe( true );
	} );

	it( 'leaves anonymous and confirm states alone', () => {
		const offer = mount( 'confirm' );

		fireAttendanceChanged( { marked: true, firstMark: true } );

		expect(
			offer.querySelector( '[data-event-digest-surface]' ).value
		).toBe( 'event_page' );
	} );
} );
