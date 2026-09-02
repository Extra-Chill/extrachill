/* eslint-disable import/no-extraneous-dependencies */

const assert = require( 'node:assert/strict' );
const path = require( 'node:path' );
const { chromium } = require( 'playwright' );

const root = path.resolve( __dirname, '../..' );

const fixture = `<!doctype html>
<html lang="en">
	<head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width,initial-scale=1">
	</head>
	<body>
		<main>
			<div class="ec-booking-form-editor">
				<div class="ec-booking-form-editor__controls ec-panel">Booking form controls</div>
				<aside class="ec-booking-form-editor__preview" aria-label="Booking form preview">
					<button class="ec-booking-form-editor__preview-toggle button-2">Preview booking form</button>
					<div class="ec-booking-form-editor__preview-content">
						<div class="ec-panel-header"><div class="ec-panel-header__main"><h2 class="ec-panel-header__title">Preview</h2></div></div>
						<div class="ec-venue-booking-inquiry">
							<div class="ec-block-shell">
								<div class="ec-block-shell-header"><h2 class="ec-block-shell-header__title">Booking at The Room</h2></div>
								<div class="ec-block-shell-inner ec-panel ec-booking-inquiry__panel">
									<form class="ec-booking-inquiry__form">
										<section class="ec-booking-inquiry__step">
											<h3>Complete your booking inquiry</h3>
											<div class="ec-card-grid" style="--ec-card-grid-min:16rem;max-width:calc(16rem * 2 + var(--spacing-md, 1rem))">
												<label class="ec-field-group"><span class="ec-field-group__label">Artist or band name</span><span class="ec-field-group__control"><input></span></label>
												<label class="ec-field-group"><span class="ec-field-group__label">Phone (optional)</span><span class="ec-field-group__control"><input type="tel"></span></label>
											</div>
										</section>
										<label class="ec-checkbox-row"><input type="checkbox"><span>I agree that this venue may use these details to review and respond to my booking inquiry.</span></label>
									</form>
								</div>
							</div>
						</div>
					</div>
				</aside>
			</div>
		</main>
	</body>
</html>`;

const fixtureStyles = `
	* { box-sizing: border-box; }
	html, body { margin: 0; }
	body { color: var(--text-color); font: 16px/1.5 Arial, sans-serif; }
	body.is-dark { --background-color: #111; --card-background: #222; --text-color: #eee; --border-color: #555; }
	body:not(.is-dark) { --background-color: #fff; --card-background: #f5f5f5; --text-color: #111; --border-color: #ddd; }
	main { margin-inline: auto; max-width: 1120px; padding: 1rem; }
`;

const measure = ( page ) =>
	page.evaluate( () => {
		const preview = document.querySelector(
			'.ec-booking-form-editor__preview'
		);
		const form = document.querySelector( '.ec-booking-inquiry__form' );
		const toggle = document.querySelector(
			'.ec-booking-form-editor__preview-toggle'
		);
		return {
			previewWidth: preview.clientWidth,
			formClientWidth: form.clientWidth,
			formScrollWidth: form.scrollWidth,
			toggleDisplay: getComputedStyle( toggle ).display,
		};
	} );

( async () => {
	const browser = await chromium.launch( { headless: true } );
	try {
		const page = await browser.newPage( {
			viewport: { width: 1440, height: 900 },
		} );
		await page.setContent( fixture );
		await page.addStyleTag( { content: fixtureStyles } );
		await page.addStyleTag( {
			path: path.join( root, 'build/venue-settings/style-index.css' ),
		} );

		const results = {};
		for ( const theme of [ 'light', 'dark' ] ) {
			await page.locator( 'body' ).evaluate( ( body, currentTheme ) => {
				body.classList.toggle( 'is-dark', currentTheme === 'dark' );
			}, theme );
			results[ `desktop-${ theme }` ] = await measure( page );
			assert.ok(
				results[ `desktop-${ theme }` ].formScrollWidth <=
					results[ `desktop-${ theme }` ].formClientWidth
			);
			assert.equal(
				results[ `desktop-${ theme }` ].toggleDisplay,
				'none'
			);
		}

		await page.setViewportSize( { width: 1100, height: 900 } );
		results.narrowDesktop = await measure( page );
		assert.ok(
			results.narrowDesktop.formScrollWidth <=
				results.narrowDesktop.formClientWidth
		);
		assert.notEqual( results.narrowDesktop.toggleDisplay, 'none' );

		await page.setViewportSize( { width: 768, height: 1024 } );
		results.tablet = await measure( page );
		assert.ok(
			results.tablet.formScrollWidth <= results.tablet.formClientWidth
		);
		assert.notEqual( results.tablet.toggleDisplay, 'none' );

		await page.setViewportSize( { width: 390, height: 844 } );
		await page
			.locator( '.ec-booking-form-editor__preview-content' )
			.evaluate( ( preview ) => {
				preview.hidden = true;
			} );
		results.mobile = await page.evaluate( () => ( {
			previewHidden: document.querySelector(
				'.ec-booking-form-editor__preview-content'
			).hidden,
			toggleDisplay: getComputedStyle(
				document.querySelector(
					'.ec-booking-form-editor__preview-toggle'
				)
			).display,
			documentScrollWidth: document.documentElement.scrollWidth,
			documentClientWidth: document.documentElement.clientWidth,
		} ) );
		assert.equal( results.mobile.previewHidden, true );
		assert.notEqual( results.mobile.toggleDisplay, 'none' );
		assert.equal(
			results.mobile.documentScrollWidth,
			results.mobile.documentClientWidth
		);

		// eslint-disable-next-line no-console -- Emits deterministic evidence for CI and PR logs.
		console.log( JSON.stringify( { status: 'passed', results } ) );
	} finally {
		await browser.close();
	}
} )().catch( ( error ) => {
	console.error( error );
	process.exitCode = 1;
} );
