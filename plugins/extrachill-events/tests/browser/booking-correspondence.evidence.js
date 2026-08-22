/* eslint-disable import/no-extraneous-dependencies */

const assert = require( 'node:assert/strict' );
const path = require( 'node:path' );
const { chromium } = require( 'playwright' );

const root = path.resolve( __dirname, '../..' );
const fixture = `<!doctype html>
<html lang="en">
	<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
	<body>
		<main class="ec-booking-console">
			<section class="ec-booking-detail__section">
				<h3>Correspondence</h3>
				<form class="ec-booking-console__form">
					<div class="ec-card-grid"><label>Subject<input value="Offer details" maxlength="200" required></label><label>Reply-to email<input value="venue@example.com"></label></div>
					<label>Message<textarea>Hold this date.</textarea></label>
					<button type="submit" class="button-1">Email artist@example.com</button>
				</form>
				<div role="status" aria-live="polite"><div class="ec-inline-status ec-inline-status--warning">Send outcome not confirmed. Draft preserved; retry will safely reuse the same message identity.</div></div>
			</section>
		</main>
	</body>
</html>`;

const measure = ( page ) =>
	page.evaluate( () => {
		const status = document.querySelector( '[role="status"]' );
		return {
			announcement: status.textContent,
			subject: document.querySelector( 'input' ).value,
			subjectMaxLength: document.querySelector( 'input' ).maxLength,
			live: status.getAttribute( 'aria-live' ),
			documentWidth: document.documentElement.scrollWidth,
			viewportWidth: document.documentElement.clientWidth,
		};
	} );

( async () => {
	const browser = await chromium.launch( { headless: true } );
	try {
		const page = await browser.newPage( {
			viewport: { width: 1440, height: 900 },
		} );
		await page.setContent( fixture );
		await page.addStyleTag( {
			path: path.join( root, 'build/venue-settings/style-index.css' ),
		} );
		const results = {};
		for ( const width of [ 1440, 390 ] ) {
			await page.setViewportSize( {
				width,
				height: width === 390 ? 844 : 900,
			} );
			results[ width ] = await measure( page );
			assert.equal( results[ width ].live, 'polite' );
			assert.match( results[ width ].announcement, /Draft preserved/ );
			assert.equal( results[ width ].subject, 'Offer details' );
			assert.equal( results[ width ].subjectMaxLength, 200 );
			assert.equal(
				results[ width ].documentWidth,
				results[ width ].viewportWidth
			);
		}
		// eslint-disable-next-line no-console -- Emits deterministic evidence for CI and PR logs.
		console.log( JSON.stringify( { status: 'passed', results } ) );
	} finally {
		await browser.close();
	}
} )().catch( ( error ) => {
	console.error( error );
	process.exitCode = 1;
} );
