/* global describe, afterEach, it, jest, expect */

const markup = `
	<div class="ec-event-submission"
		data-event-source-preview="/event-source/preview"
		data-event-source-submit="/event-source/submit"
		data-rest-nonce="nonce">
		<div class="ec-event-submission__url-import" data-state="idle">
			<input class="ec-event-submission__url-import-input" value="https://venue.test/events" />
			<button class="ec-event-submission__url-import-try" type="button">Try</button>
			<div class="ec-event-submission__url-import-status"></div>
			<div class="ec-event-submission__url-import-confirm" hidden>
				<p class="ec-event-submission__url-import-summary"></p>
				<ul class="ec-event-submission__url-import-events"></ul>
				<button class="ec-event-submission__url-import-submit" type="button">Submit</button>
				<button class="ec-event-submission__url-import-cancel" type="button">Cancel</button>
			</div>
		</div>
		<form class="ec-event-submission__form"><div class="ec-event-submission__status"></div></form>
	</div>`;

const initialize = () => {
	document.body.innerHTML = markup;
	jest.isolateModules( () => require( './view.js' ) );
	document.dispatchEvent( new Event( 'DOMContentLoaded' ) );
	return {
		button: document.querySelector(
			'.ec-event-submission__url-import-try'
		),
		status: document.querySelector(
			'.ec-event-submission__url-import-status'
		),
		confirm: document.querySelector(
			'.ec-event-submission__url-import-confirm'
		),
	};
};

describe( 'qualified event-source preview', () => {
	afterEach( () => {
		jest.useRealTimers();
		jest.restoreAllMocks();
		document.body.innerHTML = '';
	} );

	it( 'allows the bounded 60-second qualification window before aborting', async () => {
		jest.useFakeTimers();
		global.fetch = jest.fn(
			( url, options ) =>
				new Promise( ( resolve, reject ) => {
					options.signal.addEventListener( 'abort', () => {
						const error = new Error( 'aborted' );
						error.name = 'AbortError';
						reject( error );
					} );
				} )
		);
		const ui = initialize();
		ui.button.click();

		jest.advanceTimersByTime( 59999 );
		expect( ui.status.textContent ).toBe( 'Checking that URL…' );
		jest.advanceTimersByTime( 1 );
		await Promise.resolve();
		await Promise.resolve();
		expect( ui.status.textContent ).toContain( 'took too long' );
	} );

	it( 'does not offer submission for an ineligible preview', async () => {
		global.fetch = jest.fn().mockResolvedValue( {
			ok: true,
			status: 200,
			json: async () => ( {
				success: true,
				recurring_eligible: false,
				warnings: [ 'Unbounded aggregator source.' ],
			} ),
		} );
		const ui = initialize();
		ui.button.click();
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

		expect( ui.status.textContent ).toBe( 'Unbounded aggregator source.' );
		expect( ui.confirm.hidden ).toBe( true );
	} );
} );
