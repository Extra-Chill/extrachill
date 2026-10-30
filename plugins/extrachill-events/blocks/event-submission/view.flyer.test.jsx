/* global describe, afterEach, it, jest, expect */

const markup = `
	<div class="ec-event-submission">
		<form class="ec-event-submission__form">
			<input type="file" name="flyer" />
			<label>Event Title <span data-ec-flyer-optional-marker>*</span></label>
			<input type="text" name="event_title" required data-ec-flyer-optional />
			<label>Event Date <span data-ec-flyer-optional-marker>*</span></label>
			<input type="date" name="event_date" required data-ec-flyer-optional />
			<input type="text" name="contact_name" required />
			<div class="ec-event-submission__status"></div>
		</form>
	</div>`;

const setFiles = ( input, files ) => {
	Object.defineProperty( input, 'files', { value: files, configurable: true } );
	input.dispatchEvent( new Event( 'change' ) );
};

const initialize = () => {
	document.body.innerHTML = markup;
	jest.isolateModules( () => require( './view.js' ) );
	document.dispatchEvent( new Event( 'DOMContentLoaded' ) );
	return {
		flyer: document.querySelector( 'input[name="flyer"]' ),
		title: document.querySelector( 'input[name="event_title"]' ),
		date: document.querySelector( 'input[name="event_date"]' ),
		name: document.querySelector( 'input[name="contact_name"]' ),
		markers: document.querySelectorAll( '[data-ec-flyer-optional-marker]' ),
	};
};

describe( 'flyer-only submissions', () => {
	afterEach( () => {
		document.body.innerHTML = '';
	} );

	it( 'requires title and date when no flyer is selected', () => {
		const ui = initialize();
		expect( ui.title.required ).toBe( true );
		expect( ui.date.required ).toBe( true );
		ui.markers.forEach( ( m ) => expect( m.hidden ).toBe( false ) );
	} );

	it( 'makes title and date optional while a flyer is selected, and restores them when cleared', () => {
		const ui = initialize();

		setFiles( ui.flyer, [ new File( [ 'x' ], 'flyer.jpg', { type: 'image/jpeg' } ) ] );
		expect( ui.title.required ).toBe( false );
		expect( ui.date.required ).toBe( false );
		ui.markers.forEach( ( m ) => expect( m.hidden ).toBe( true ) );
		// Contact fields are still required.
		expect( ui.name.required ).toBe( true );

		setFiles( ui.flyer, [] );
		expect( ui.title.required ).toBe( true );
		expect( ui.date.required ).toBe( true );
		ui.markers.forEach( ( m ) => expect( m.hidden ).toBe( false ) );
	} );
} );
