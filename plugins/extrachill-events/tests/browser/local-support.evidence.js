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
			const response = await fetch( url );
			if ( response.ok ) {
				return;
			}
		} catch {}
		await new Promise( ( resolve ) => setTimeout( resolve, 100 ) );
	}
	throw new Error( 'Rendered fixture server did not start.' );
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
			`${ origin }/tests/browser/local-support-fixture.php?scenario=artist`
		);
		browser = await chromium.launch( { headless: true } );
		const page = await browser.newPage( {
			viewport: { width: 1280, height: 900 },
		} );

		await page.goto(
			`${ origin }/tests/browser/local-support-fixture.php?scenario=artist`
		);
		await page.check( 'input[value="email"]' );
		await page.fill( '[data-contact-field="email"]', 'new@example.com' );
		assert.match(
			await page.textContent( '[data-consent-preview]' ),
			/Email: new@example\.com/
		);
		await page.$eval( '[data-consent-form]', ( form ) =>
			form.addEventListener( 'submit', ( event ) =>
				event.preventDefault()
			)
		);
		await page.click( 'button:has-text("Share selected contact")' );
		assert.equal(
			await page.getAttribute( '[data-consent-form]', 'aria-busy' ),
			'true'
		);

		await page.goto(
			`${ origin }/tests/browser/local-support-fixture.php?scenario=artist-consented`
		);
		assert.equal(
			await page.locator( 'text=Withdraw interest' ).count(),
			0
		);
		const revoke = await page.$(
			'button:has-text("Revoke contact access")'
		);
		assert.ok( revoke );
		await revoke.evaluate( ( button ) =>
			button.form.addEventListener( 'submit', ( event ) =>
				event.preventDefault()
			)
		);
		await revoke.click();
		assert.equal( await revoke.textContent(), 'Revoking access...' );

		await page.goto(
			`${ origin }/tests/browser/local-support-fixture.php?scenario=organizer`
		);
		assert.equal(
			await page.locator( '.ec-local-support__artist-card' ).count(),
			3
		);
		const desktopColumns = await page.$eval(
			'.ec-local-support__cards',
			( node ) =>
				getComputedStyle( node ).gridTemplateColumns.split( ' ' ).length
		);
		assert.ok( desktopColumns > 1 );
		const select = await page.$( 'button:has-text("Select artist")' );
		assert.ok( select );
		await select.evaluate( ( button ) =>
			button.form.addEventListener( 'submit', ( event ) =>
				event.preventDefault()
			)
		);
		assert.equal(
			await select.evaluate(
				( button ) =>
					button.form.querySelector( '[name="_wpnonce"]' ).value
			),
			'nonce-extrachill_events_local_support'
		);
		await select.click();
		assert.equal( await select.textContent(), 'Updating...' );

		await page.setViewportSize( { width: 390, height: 844 } );
		await page.reload();
		const mobileColumns = await page.$eval(
			'.ec-local-support__cards',
			( node ) =>
				getComputedStyle( node ).gridTemplateColumns.split( ' ' ).length
		);
		assert.equal( mobileColumns, 1 );
		const widths = await page.$eval(
			'.ec-local-support__actions',
			( node ) => ( {
				container: node.getBoundingClientRect().width,
				button: node.querySelector( 'button' ).getBoundingClientRect()
					.width,
			} )
		);
		assert.ok( Math.abs( widths.container - widths.button ) < 2 );

		await page.goto(
			`${ origin }/tests/browser/local-support-fixture.php?scenario=unauthorized`
		);
		assert.equal(
			await page.getAttribute( '[role="alert"]', 'role' ),
			'alert'
		);
		await page.goto(
			`${ origin }/tests/browser/local-support-fixture.php?scenario=conflict`
		);
		assert.match(
			await page.textContent( '[role="status"]' ),
			/latest version is shown/
		);

		// eslint-disable-next-line no-console -- Emits deterministic evidence for CI and PR logs.
		console.log(
			JSON.stringify( {
				status: 'passed',
				viewports: [ '1280x900', '390x844' ],
				interactions: [
					'artist-consent-preview-submit',
					'ineligible-consent-revoke',
					'organizer-select',
					'unauthorized-alert',
					'version-conflict-status',
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
