/* global describe, afterEach, it, jest, expect */

const markup = `
	<section class="ec-submit-landing">
		<div class="ec-submit-landing__paths">
			<a class="ec-submit-landing__path" href="#ec-event-source-import">Calendar</a>
			<a class="ec-submit-landing__path" href="#ec-event-form-1">One show</a>
		</div>
	</section>
	<div class="ec-event-submission">
		<div class="ec-event-submission__url-import" id="ec-event-source-import">
			<input class="ec-event-submission__url-import-input" />
			<button class="ec-event-submission__url-import-try" type="button">Try</button>
			<div class="ec-event-submission__url-import-status"></div>
			<div class="ec-event-submission__url-import-confirm" hidden>
				<p class="ec-event-submission__url-import-summary"></p>
				<ul class="ec-event-submission__url-import-events"></ul>
				<button class="ec-event-submission__url-import-submit" type="button">Submit</button>
				<button class="ec-event-submission__url-import-cancel" type="button">Cancel</button>
			</div>
		</div>
		<form id="ec-event-form-1" class="ec-event-submission__form">
			<input type="hidden" name="nonce" />
			<input name="contact_name" />
			<div class="ec-event-submission__status"></div>
		</form>
	</div>`;

const initialize = () => {
	document.body.innerHTML = markup;
	jest.isolateModules( () => require( './view.js' ) );
	document.dispatchEvent( new Event( 'DOMContentLoaded' ) );
};

describe( 'submit landing path cards', () => {
	afterEach( () => {
		document.body.innerHTML = '';
	} );

	it( 'focuses the URL field when the calendar card is chosen', () => {
		initialize();
		const input = document.querySelector(
			'.ec-event-submission__url-import-input'
		);
		document.querySelector( 'a[href="#ec-event-source-import"]' ).click();
		expect( input.ownerDocument.activeElement ).toBe( input );
	} );

	it( 'focuses the first visible manual field when the one-show card is chosen', () => {
		initialize();
		const input = document.querySelector( 'input[name="contact_name"]' );
		document.querySelector( 'a[href="#ec-event-form-1"]' ).click();
		expect( input.ownerDocument.activeElement ).toBe( input );
	} );
} );
