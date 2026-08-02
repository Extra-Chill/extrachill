/* eslint-disable import/no-extraneous-dependencies */

const assert = require( 'node:assert/strict' );
const { spawn } = require( 'node:child_process' );
const net = require( 'node:net' );
const path = require( 'node:path' );
const { chromium } = require( 'playwright' );

const root = path.resolve( __dirname, '../..' );
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
	throw new Error( 'Vendor fixture server did not start.' );
};

( async () => {
	const port = await availablePort();
	const origin = `http://127.0.0.1:${ port }`;
	const server = spawn( 'php', [ '-S', `127.0.0.1:${ port }`, '-t', root ], {
		stdio: 'ignore',
	} );
	let browser;
	try {
		const publicUrl = `${ origin }/tests/browser/vendor-request-fixture.php`;
		await waitForServer( publicUrl );
		browser = await chromium.launch( { headless: true } );
		const page = await browser.newPage( {
			viewport: { width: 1280, height: 900 },
		} );
		await page.route( '**/api/vendor-applications', async ( route ) => {
			const input = route.request().postDataJSON();
			assert.equal( input.turnstile_response, 'fixture-token' );
			assert.equal( input.contact_consent, true );
			await route.fulfill( {
				status: 201,
				contentType: 'application/json',
				body: JSON.stringify( {
					public_id: '223e4567-e89b-42d3-a456-000000000001',
					access_token: 'a'.repeat( 64 ),
				} ),
			} );
		} );
		await page.goto( publicUrl );
		assert.equal( await page.locator( 'label' ).count(), 11 );
		await page.fill( '[name="business_name"]', 'Lowcountry Goods' );
		await page.fill( '[name="contact_name"]', 'Vendor Person' );
		await page.fill( '[name="contact_email"]', 'vendor@example.com' );
		await page.fill( '[name="footprint"]', '10 x 10 feet' );
		await page.fill( '[name="power_needs"]', 'One circuit' );
		await page.fill( '[name="message"]', 'Handmade goods' );
		await page.check( '[name="contact_consent"]' );
		await page.click( 'button[type="submit"]' );
		await page.waitForFunction( () =>
			document
				.querySelector( '[role="status"]' )
				.textContent.includes( 'Save this private withdrawal receipt' )
		);
		assert.match(
			await page.textContent( '[role="status"]' ),
			/223e4567.*:{1}a{64}/
		);

		await page.goto( `${ publicUrl }?scenario=workspace` );
		assert.ok(
			( await page.locator( '.ec-vendor-request__card' ).count() ) === 3
		);
		assert.ok(
			( await page.$eval(
				'.ec-vendor-request__cards',
				( node ) =>
					getComputedStyle( node ).gridTemplateColumns.split( ' ' )
						.length
			) ) > 1
		);
		await page.setViewportSize( { width: 390, height: 844 } );
		await page.reload();
		assert.equal(
			await page.$eval(
				'.ec-vendor-request__cards',
				( node ) =>
					getComputedStyle( node ).gridTemplateColumns.split( ' ' )
						.length
			),
			1
		);
		assert.ok(
			await page.$eval(
				'button',
				( button ) => button.getBoundingClientRect().height >= 44
			)
		);
		assert.ok(
			await page.$eval(
				'html',
				( html ) => html.scrollWidth <= html.clientWidth
			)
		);

		// eslint-disable-next-line no-console -- Deterministic CI evidence.
		console.log(
			JSON.stringify( {
				status: 'passed',
				viewports: [ '1280x900', '390x844' ],
				privacy: 'no-public-directory',
				receipt: 'opaque-withdrawal-token',
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
