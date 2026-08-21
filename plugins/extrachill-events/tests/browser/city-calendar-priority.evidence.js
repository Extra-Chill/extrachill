/* eslint-disable import/no-extraneous-dependencies */

const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { chromium } = require( 'playwright' );

const root = path.resolve( __dirname, '../..' );
const screenshotDirectory = path.join( __dirname, 'screenshots' );

const fixture = ( city, venueCount ) => {
	const venues = Array.from( { length: venueCount }, ( _, index ) => {
		const number = String( index + 1 ).padStart( 2, '0' );
		return `<a href="/venue/${ number }/" class="taxonomy-badge venue-badge">Venue ${ number } (${
			venueCount - index
		})</a>`;
	} ).join( '' );

	return `<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body><main class="events-calendar-container"><div class="page-content">
<header class="taxonomy-archive-header"><h1 class="page-title">Live Music in ${ city }</h1><p class="calendar-stats">120 upcoming events at ${ venueCount } venues</p></header>
<details class="location-archive-venue-directory"><summary class="location-archive-venue-directory__summary">Browse ${ venueCount } venues</summary><div class="taxonomy-badges ec-edge-gutter location-archive-venue-badges">${ venues }</div></details>
<section class="events-map">Map</section></div><div class="page-content"><section class="calendar"><label>Search events<input type="search"></label><nav><button>This Weekend</button></nav><article class="event-card">First show</article></section></div>
</main></body></html>`;
};

const fixtureStyles = `
	* { box-sizing: border-box; }
	html, body { margin: 0; }
	body { --spacing-sm: .5rem; --spacing-md: 1rem; --spacing-lg: 1.5rem; --spacing-xl: 2rem; --link-color: #2255aa; --link-hover-color: #163b79; font: 16px/1.5 Arial, sans-serif; }
	.page-content { margin-inline: auto; max-width: 70rem; padding: 1rem; }
	.taxonomy-badges { display: flex; flex-wrap: wrap; gap: .5rem; }
	.taxonomy-badge { border: 1px solid #bbb; padding: .35rem .6rem; }
	.events-map { height: 9rem; margin-bottom: 1rem; padding: 1rem; background: #eee; }
	.calendar { display: grid; gap: .75rem; }
	.event-card { min-height: 8rem; padding: 1rem; background: #f2f2f2; }
`;

( async () => {
	fs.mkdirSync( screenshotDirectory, { recursive: true } );
	const browser = await chromium.launch( { headless: true } );
	const results = [];

	try {
		for ( const market of [
			{ city: 'Charleston', venues: 22 },
			{ city: 'Chicago', venues: 97 },
		] ) {
			for ( const viewport of [
				{ label: 'desktop', width: 1280, height: 900 },
				{ label: 'mobile-390', width: 390, height: 844 },
			] ) {
				const page = await browser.newPage( { viewport } );
				await page.setContent( fixture( market.city, market.venues ) );
				await page.addStyleTag( { content: fixtureStyles } );
				await page.addStyleTag( {
					path: path.join( root, 'assets/css/calendar.css' ),
				} );

				const details = page.locator(
					'.location-archive-venue-directory'
				);
				const summary = details.locator( 'summary' );
				const firstShow = page.getByText( 'First show' );
				assert.equal( await details.getAttribute( 'open' ), null );
				assert.equal(
					await details.locator( 'a' ).count(),
					market.venues
				);
				assert.equal(
					await details.locator( 'a' ).first().isVisible(),
					false
				);
				assert.equal(
					await page.getByLabel( 'Search events' ).isVisible(),
					true
				);
				assert.equal(
					await page
						.getByRole( 'button', { name: 'This Weekend' } )
						.isVisible(),
					true
				);
				assert.ok(
					( await firstShow.boundingBox() ).y < viewport.height
				);

				await summary.focus();
				assert.equal(
					await summary.evaluate(
						( element ) =>
							element === element.ownerDocument.activeElement
					),
					true
				);
				await page.keyboard.press( 'Enter' );
				assert.equal( await details.getAttribute( 'open' ), '' );
				assert.equal(
					await details.locator( 'a' ).first().isVisible(),
					true
				);
				await page.keyboard.press( 'Enter' );
				assert.equal( await details.getAttribute( 'open' ), null );

				if ( 'Chicago' === market.city ) {
					await page.screenshot( {
						path: path.join(
							screenshotDirectory,
							`city-calendar-priority-${ viewport.label }.png`
						),
						fullPage: false,
					} );
				}

				results.push(
					`${ market.city }-${ viewport.width }x${ viewport.height }`
				);
				await page.close();
			}
		}

		// eslint-disable-next-line no-console -- Deterministic PR evidence.
		console.log( JSON.stringify( { status: 'passed', results } ) );
	} finally {
		await browser.close();
	}
} )().catch( ( error ) => {
	console.error( error );
	process.exitCode = 1;
} );
