/* global describe, beforeEach, afterEach, it, jest, expect */

/**
 * Venue typeahead (extrachill-events#965): suggestions come from the core
 * wp/v2/venue term search, a pick records the term ID, and any edit after a
 * pick falls back to free text.
 */

const markup = `
	<div class="ec-event-submission" data-venue-search="https://events.test/wp-json/wp/v2/venue">
		<form class="ec-event-submission__form">
			<div class="ec-event-submission__venue">
				<input type="text" name="venue_name" role="combobox" aria-expanded="false" />
				<input type="hidden" name="venue_term_id" value="" />
				<ul class="ec-event-submission__venue-suggestions" id="venue-list" role="listbox" hidden></ul>
			</div>
			<input type="text" name="event_city" />
			<div class="ec-event-submission__status"></div>
		</form>
	</div>`;

const venues = [
	{
		id: 9001,
		name: 'Recovery Room Lounge',
		meta: { _venue_city: 'Austin', _venue_state: 'Texas' },
	},
	{
		id: 104506,
		name: 'Recovery Room Tavern',
		meta: { _venue_city: 'Charleston', _venue_state: 'South Carolina' },
	},
];

const flush = async () => {
	await Promise.resolve();
	await Promise.resolve();
	await Promise.resolve();
};

const initialize = () => {
	document.body.innerHTML = markup;
	jest.isolateModules( () => require( './view.js' ) );
	document.dispatchEvent( new Event( 'DOMContentLoaded' ) );
	return {
		input: document.querySelector( 'input[name="venue_name"]' ),
		id: document.querySelector( 'input[name="venue_term_id"]' ),
		city: document.querySelector( 'input[name="event_city"]' ),
		list: document.querySelector(
			'.ec-event-submission__venue-suggestions'
		),
		form: document.querySelector( 'form' ),
	};
};

const type = async ( input, value ) => {
	input.value = value;
	input.dispatchEvent( new Event( 'input' ) );
	jest.advanceTimersByTime( 250 );
	await flush();
};

describe( 'venue typeahead', () => {
	beforeEach( () => {
		jest.useFakeTimers();
		global.fetch = jest.fn().mockResolvedValue( {
			ok: true,
			json: async () => venues,
		} );
	} );

	afterEach( () => {
		jest.useRealTimers();
		jest.restoreAllMocks();
		document.body.innerHTML = '';
	} );

	it( 'searches existing venues through the core term route', async () => {
		const ui = initialize();
		await type( ui.input, 'Recov' );

		const url = new URL( global.fetch.mock.calls[ 0 ][ 0 ] );
		expect( url.pathname ).toBe( '/wp-json/wp/v2/venue' );
		expect( url.searchParams.get( 'search' ) ).toBe( 'Recov' );
		expect( url.searchParams.get( '_fields' ) ).toBe( 'id,name,meta' );
		expect( ui.list.hidden ).toBe( false );
		expect( ui.list.querySelectorAll( 'li' ) ).toHaveLength( 2 );
		expect( ui.list.textContent ).toContain( 'Charleston, South Carolina' );
	} );

	it( 'does not search for a single character', async () => {
		const ui = initialize();
		await type( ui.input, 'R' );

		expect( global.fetch ).not.toHaveBeenCalled();
		expect( ui.list.hidden ).toBe( true );
	} );

	it( 'lists venues in the typed city first', async () => {
		const ui = initialize();
		ui.city.value = 'charleston';
		await type( ui.input, 'Recov' );

		const first = ui.list.querySelector( 'li' );
		expect( first.dataset.venueId ).toBe( '104506' );
	} );

	it( 'records the term ID and canonical name when a venue is picked', async () => {
		const ui = initialize();
		await type( ui.input, 'Recoery' );

		const option = ui.list.querySelector( 'li[data-venue-id="104506"]' );
		option.dispatchEvent(
			new window.MouseEvent( 'mousedown', { bubbles: true } )
		);

		expect( ui.id.value ).toBe( '104506' );
		expect( ui.input.value ).toBe( 'Recovery Room Tavern' );
		expect( ui.city.value ).toBe( 'Charleston' );
		expect( ui.list.hidden ).toBe( true );
	} );

	it( 'supports keyboard selection', async () => {
		const ui = initialize();
		await type( ui.input, 'Recov' );

		ui.input.dispatchEvent(
			new window.KeyboardEvent( 'keydown', { key: 'ArrowDown' } )
		);
		ui.input.dispatchEvent(
			new window.KeyboardEvent( 'keydown', { key: 'ArrowDown' } )
		);
		ui.input.dispatchEvent(
			new window.KeyboardEvent( 'keydown', { key: 'Enter' } )
		);

		expect( ui.id.value ).toBe( '104506' );
	} );

	it( 'falls back to free text when the name is edited after a pick', async () => {
		const ui = initialize();
		await type( ui.input, 'Recov' );
		ui.list
			.querySelector( 'li[data-venue-id="104506"]' )
			.dispatchEvent(
				new window.MouseEvent( 'mousedown', { bubbles: true } )
			);
		expect( ui.id.value ).toBe( '104506' );

		await type( ui.input, 'Recovery Room Tavern Upstairs' );

		expect( ui.id.value ).toBe( '' );
	} );

	it( 'keeps free text for a venue that does not exist yet', async () => {
		global.fetch = jest.fn().mockResolvedValue( {
			ok: true,
			json: async () => [],
		} );
		const ui = initialize();
		await type( ui.input, 'Brand New Room' );

		expect( ui.list.hidden ).toBe( true );
		expect( ui.id.value ).toBe( '' );
		expect( new FormData( ui.form ).get( 'venue_name' ) ).toBe(
			'Brand New Room'
		);
	} );

	it( 'clears the picked venue when the form resets after submit', async () => {
		const ui = initialize();
		await type( ui.input, 'Recov' );
		ui.list
			.querySelector( 'li[data-venue-id="104506"]' )
			.dispatchEvent(
				new window.MouseEvent( 'mousedown', { bubbles: true } )
			);

		ui.form.reset();

		expect( ui.id.value ).toBe( '' );
	} );
} );
