/* eslint-disable import/no-extraneous-dependencies */

const assert = require( 'node:assert/strict' );
const { spawn } = require( 'node:child_process' );
const fs = require( 'node:fs' );
const net = require( 'node:net' );
const os = require( 'node:os' );
const path = require( 'node:path' );
const { chromium } = require( 'playwright' );

const root = path.resolve( __dirname, '../..' );
const artifacts =
	process.env.VENUE_CLAIM_ARTIFACT_DIR ||
	path.join( os.tmpdir(), 'extrachill-events-venue-claim' );
const workspaceUrl =
	'https://events.example/venue-settings/?venue_id=44#tab-calendar';
const claimReviewUrl =
	'https://events.example/venue-settings/?venue_id=44#tab-claims';
const roles = {
	logged_out: {
		label: 'Sign in to claim or manage',
		href: `https://events.example/wp-login.php?redirect_to=${ encodeURIComponent(
			workspaceUrl
		) }`,
	},
	nonmember: { label: 'Claim or request access', href: workspaceUrl },
	active_member: { label: 'Manage Venue', href: workspaceUrl },
	administrator: { label: 'Review venue claims', href: claimReviewUrl },
};
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
	throw new Error( 'Venue archive fixture server did not start.' );
};

( async () => {
	fs.mkdirSync( artifacts, { recursive: true } );
	const port = await availablePort();
	const origin = `http://127.0.0.1:${ port }`;
	const archiveUrl = `${ origin }/tests/browser/venue-claim-archive-fixture.php`;
	const server = spawn( 'php', [ '-S', `127.0.0.1:${ port }`, '-t', root ], {
		stdio: 'ignore',
	} );
	let browser;

	try {
		await waitForServer( archiveUrl );
		browser = await chromium.launch( { headless: true } );
		const page = await browser.newPage( {
			viewport: { width: 1280, height: 900 },
		} );

		for ( const [ role, expected ] of Object.entries( roles ) ) {
			await page.goto( `${ archiveUrl }?role=${ role }` );
			const action = page.locator( '[data-venue-workspace-action] a' );
			assert.equal( await action.textContent(), expected.label );
			assert.equal( await action.getAttribute( 'href' ), expected.href );
		}

		await page.goto( `${ archiveUrl }?role=nonmember` );
		const heading = page.locator( '.page-title' );
		const booking = page.getByRole( 'link', {
			name: 'Submit a booking inquiry',
		} );
		const disclosure = page.locator( '[data-venue-workspace-action]' );
		const calendar = page.locator( '[data-machine-events-calendar]' );
		const bookingForm = page.locator( '#booking-inquiry' );
		assert.equal( await disclosure.getAttribute( 'open' ), null );
		assert.equal( await disclosure.getByRole( 'link' ).isVisible(), false );
		assert.ok(
			( await heading.boundingBox() ).y <
				( await booking.boundingBox() ).y
		);
		assert.ok(
			( await booking.boundingBox() ).y <
				( await disclosure.boundingBox() ).y
		);
		assert.ok(
			( await disclosure.boundingBox() ).y <
				( await calendar.boundingBox() ).y
		);
		assert.ok(
			( await calendar.boundingBox() ).y <
				( await bookingForm.boundingBox() ).y
		);
		await disclosure.locator( 'summary' ).focus();
		await page.keyboard.press( 'Enter' );
		assert.equal( await disclosure.getAttribute( 'open' ), '' );
		assert.equal( await disclosure.getByRole( 'link' ).isVisible(), true );
		await page.screenshot( {
			path: path.join( artifacts, 'venue-claim-desktop.png' ),
			fullPage: true,
		} );

		await page.setViewportSize( { width: 390, height: 844 } );
		await page.goto( `${ archiveUrl }?role=nonmember` );
		assert.equal( await page.locator( '.page-title' ).count(), 1 );
		assert.equal(
			await page
				.getByRole( 'link', {
					name: 'Submit a booking inquiry',
				} )
				.count(),
			1
		);
		assert.equal(
			await page.locator( '[data-machine-events-calendar]' ).count(),
			1
		);
		assert.equal( await page.locator( '#booking-inquiry' ).count(), 1 );
		assert.equal(
			await page.evaluate(
				() =>
					document.documentElement.scrollWidth ===
					document.documentElement.clientWidth
			),
			true
		);
		await page.locator( '[data-venue-workspace-action] summary' ).click();
		await page.screenshot( {
			path: path.join( artifacts, 'venue-claim-mobile-390.png' ),
			fullPage: true,
		} );

		// eslint-disable-next-line no-console -- Deterministic PR evidence.
		console.log(
			JSON.stringify( {
				status: 'passed',
				template: 'inc/templates/archive.php',
				roles: Object.keys( roles ),
				viewports: [ '1280x900', '390x844' ],
			} )
		);
		await page.close();
	} finally {
		if ( browser ) {
			await browser.close();
		}
		server.kill( 'SIGTERM' );
	}
} )().catch( ( error ) => {
	console.error( error );
	process.exitCode = 1;
} );
