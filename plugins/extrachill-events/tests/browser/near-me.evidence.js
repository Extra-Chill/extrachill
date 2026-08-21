/* eslint-disable import/no-extraneous-dependencies */

const assert = require( 'node:assert/strict' );
const { spawn } = require( 'node:child_process' );
const net = require( 'node:net' );
const path = require( 'node:path' );
const { chromium } = require( 'playwright' );

const root = path.resolve( __dirname, '../..' );
const fallbackMessage =
	"We couldn't determine your location. Choose a city or search an area.";

const availablePort = () =>
	new Promise( ( resolve, reject ) => {
		const server = net.createServer();
		server.once( 'error', reject );
		server.listen( 0, '127.0.0.1', () => {
			const { port } = server.address();
			server.close( () => resolve( port ) );
		} );
	} );

const waitForServer = async ( url ) => {
	for ( let attempt = 0; attempt < 50; attempt++ ) {
		try {
			if ( ( await fetch( url ) ).ok ) {
				return;
			}
		} catch {}
		await new Promise( ( resolve ) => setTimeout( resolve, 100 ) );
	}
	throw new Error( 'Near Me fixture server did not start.' );
};

( async () => {
	const port = await availablePort();
	const origin = `http://127.0.0.1:${ port }`;
	const server = spawn( 'php', [ '-S', `127.0.0.1:${ port }`, '-t', root ], {
		stdio: 'ignore',
	} );
	let browser;
	try {
		await waitForServer(
			`${ origin }/tests/browser/near-me-fixture.html?scenario=unresolved`
		);
		browser = await chromium.launch( { headless: true } );
		const viewports = [
			{ label: 'desktop', width: 1280, height: 900 },
			{ label: 'mobile-390', width: 390, height: 844 },
		];
		const failureStates = [
			'unsupported',
			'denied',
			'timeout',
			'lookup-failure',
		];

		for ( const viewport of viewports ) {
			const page = await browser.newPage( { viewport } );
			const errors = [];
			page.on( 'pageerror', ( error ) => errors.push( error.message ) );

			await page.goto(
				`${ origin }/tests/browser/near-me-fixture.html?scenario=unresolved`
			);
			assert.equal(
				await page.locator( '.near-me-cities' ).isVisible(),
				true
			);
			assert.equal(
				await page
					.locator( '.data-machine-events-calendar' )
					.isVisible(),
				false
			);

			for ( const state of failureStates ) {
				await page.goto(
					`${ origin }/tests/browser/near-me-fixture.html?scenario=${ state }`
				);
				await page.waitForFunction(
					( message ) =>
						document.querySelector( '.near-me-status' )
							?.textContent === message,
					fallbackMessage
				);
				assert.equal(
					await page.locator( '.near-me-cities' ).isVisible(),
					true
				);
				assert.equal(
					await page
						.locator( '.data-machine-events-calendar' )
						.isVisible(),
					false
				);
				assert.equal(
					await page.getAttribute( '.near-me-loading', 'aria-live' ),
					'polite'
				);
			}

			await page.goto(
				`${ origin }/tests/browser/near-me-fixture.html?scenario=success`
			);
			await page.waitForFunction(
				() =>
					document
						.querySelector( '.near-me-results' )
						?.classList.contains( 'is-location-pending' ) === false
			);
			assert.equal(
				await page
					.locator( '.data-machine-events-calendar' )
					.isVisible(),
				true
			);
			assert.match(
				await page.textContent( '.data-machine-events-calendar' ),
				/Scoped nearby calendar/
			);
			assert.equal( errors.length, 0, errors.join( '\n' ) );
			await page.close();
		}

		// eslint-disable-next-line no-console -- Deterministic PR evidence.
		console.log(
			JSON.stringify( {
				status: 'passed',
				viewports: [ '1280x900', '390x844' ],
				states: [
					'unresolved',
					'unsupported',
					'denied',
					'timeout',
					'lookup-failure',
					'success',
				],
			} )
		);
	} finally {
		if ( browser ) {
			await browser.close();
		}
		server.kill();
	}
} )().catch( ( error ) => {
	console.error( error );
	process.exitCode = 1;
} );
