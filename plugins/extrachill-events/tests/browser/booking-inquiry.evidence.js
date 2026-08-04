/* eslint-disable import/no-extraneous-dependencies */

const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const os = require( 'node:os' );
const path = require( 'node:path' );
const { chromium } = require( 'playwright' );

const root = path.resolve( __dirname, '../..' );
const artifacts =
	process.env.BOOKING_INQUIRY_ARTIFACT_DIR ||
	path.join( os.tmpdir(), 'extrachill-events-booking-inquiry' );

const config = {
	instanceId: 'ec-booking-browser-proof',
	endpoint: '/booking-inquiries',
	availabilityEndpoint: '/booking-availability',
	restNonce: 'booking-proof-nonce',
	authenticated: true,
	heading: 'Booking inquiries',
	buttonLabel: 'Send booking inquiry',
	revision: 7,
	venue: {
		id: 55,
		name: 'The Room',
		description:
			'An independent room presenting touring and local artists.',
		address: '123 King Street, Charleston, SC',
	},
	requirements: [ 'Include a recent live performance link.' ],
	spaces: [ { key: 'main', name: 'Main Room', is_default: true } ],
	fields: [
		{
			key: 'website',
			label: 'Artist website',
			type: 'url',
			required: false,
			options: [],
		},
		{
			key: 'event_type',
			label: 'Event type',
			type: 'select',
			required: true,
			options: [ 'Concert', 'Market', 'Other' ],
		},
		{
			key: 'other_event',
			label: 'Other event details',
			type: 'text',
			required: true,
			options: [],
			visible_when: { field: 'event_type', value: 'Other' },
		},
		{
			key: 'press_links',
			label: 'Press links',
			type: 'url_list',
			required: false,
			options: [],
		},
	],
	presentation: {
		artist_name_label: 'Artist or project name',
		contact_name_label: 'Contact name',
		contact_email_label: 'Contact email',
		contact_phone_label: 'Phone (Emergency use only)',
		message_label: 'Additional performance details',
		message_help: 'Share routing and scheduling notes.',
	},
	linkPage: {
		url: 'https://artist.example/manage-link-page/',
		hasPage: true,
		authenticated: true,
	},
	consent: {
		required: true,
		label: 'I agree that this venue may use these details to review and respond to my booking inquiry.',
	},
};

const fixture = `<!doctype html>
<html lang="en">
	<head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width,initial-scale=1">
		<title>Booking Inquiry Evidence</title>
	</head>
	<body>
		<main>
			<section class="wp-block-extrachill-venue-booking-inquiry ec-venue-booking-inquiry">
				<div data-booking-app></div>
				<script type="application/json">${ JSON.stringify( config ) }</script>
				<div data-booking-turnstile><div class="cf-turnstile">Security check</div></div>
			</section>
		</main>
	</body>
</html>`;

const fixtureStyles = `
	* { box-sizing: border-box; }
	html, body { margin: 0; }
	body { color: #111; font: 16px/1.5 Arial, sans-serif; }
	main { margin-inline: auto; max-width: 1120px; padding-inline: var(--spacing-md, 1rem); }
	button { padding: .75rem 1rem; }
`;

const measure = ( page ) =>
	page.evaluate( () => {
		const bounds = ( selector ) => {
			const rect = document
				.querySelector( selector )
				.getBoundingClientRect();
			return {
				left: rect.left,
				right: rect.right,
				width: rect.width,
			};
		};
		const shell = bounds( '.ec-block-shell' );
		const form = bounds( '.ec-booking-inquiry__form' );
		const controls = Array.from(
			document.querySelectorAll( 'input, select, textarea, button' )
		).map( ( control ) => {
			const rect = control.getBoundingClientRect();
			return { left: rect.left, right: rect.right };
		} );

		return {
			viewportWidth: document.documentElement.clientWidth,
			scrollWidth: document.documentElement.scrollWidth,
			shell,
			panel: bounds( '.ec-booking-inquiry__panel' ),
			form,
			grid: bounds( '.ec-booking-inquiry__step .ec-card-grid' ),
			turnstile: bounds( '.ec-booking-inquiry__turnstile' ),
			actions: bounds( '.ec-action-row' ),
			inlineGutter: form.left - shell.left,
			controlsInsideViewport: controls.every(
				( control ) =>
					control.left >= 0 &&
					control.right <= document.documentElement.clientWidth
			),
		};
	} );

( async () => {
	fs.mkdirSync( artifacts, { recursive: true } );
	const browser = await chromium.launch( { headless: true } );
	try {
		const page = await browser.newPage( {
			viewport: { width: 1280, height: 900 },
		} );
		await page.setContent( fixture );
		await page.addStyleTag( { content: fixtureStyles } );
		await page.addStyleTag( {
			path: path.join(
				root,
				'build/venue-booking-inquiry/style-index.css'
			),
		} );
		await page.addScriptTag( {
			path: path.join(
				root,
				'node_modules/react/umd/react.production.min.js'
			),
		} );
		await page.addScriptTag( {
			path: path.join(
				root,
				'node_modules/react-dom/umd/react-dom.production.min.js'
			),
		} );
		await page.evaluate( () => {
			window.wp = {
				element: {
					...window.React,
					createRoot: window.ReactDOM.createRoot,
				},
			};
			window.bookingAvailable = false;
			window.fetch = async ( url ) => {
				if ( String( url ).includes( 'booking-availability' ) ) {
					return new Response(
						JSON.stringify( {
							available: window.bookingAvailable,
						} ),
						{
							status: 200,
							headers: { 'Content-Type': 'application/json' },
						}
					);
				}
				return new Response( JSON.stringify( { public_id: 'proof' } ), {
					status: 201,
					headers: { 'Content-Type': 'application/json' },
				} );
			};
		} );
		await page.addScriptTag( {
			path: path.join( root, 'build/venue-booking-inquiry/view.js' ),
		} );
		await page.waitForSelector( '.ec-booking-inquiry__form' );

		assert.equal(
			await page.getByText( 'Booking at The Room' ).count(),
			1
		);
		assert.equal(
			await page.getByText( 'Accepting inquiries' ).count(),
			0
		);
		assert.equal( await page.getByText( 'Signed in' ).count(), 0 );
		assert.equal(
			await page.getByText( 'Have your pitch ready' ).count(),
			0
		);
		assert.equal(
			await page.getByText( 'Event type (required)' ).count(),
			0
		);
		assert.equal( await page.getByText( 'Press links' ).count(), 0 );
		assert.equal(
			await page.getByLabel( 'Artist or project name' ).count(),
			0
		);
		assert.ok( await page.getByLabel( 'Requested date' ).count() );
		assert.equal( await page.getByLabel( 'Requested space' ).count(), 0 );
		assert.equal( await page.locator( 'input[type="time"]' ).count(), 0 );
		assert.equal(
			await page.locator( 'input[type="datetime-local"]' ).count(),
			0
		);
		await page.getByLabel( 'Requested date' ).fill( '2030-08-01' );
		await page
			.getByRole( 'button', { name: 'Check availability' } )
			.click();
		await page.getByText( /That date is unavailable/ ).waitFor();
		assert.equal(
			await page.getByLabel( 'Artist or project name' ).count(),
			0
		);
		assert.equal(
			await page.getByLabel( 'Requested date' ).inputValue(),
			'2030-08-01'
		);
		await page.evaluate( () => {
			window.bookingAvailable = true;
		} );
		await page
			.getByRole( 'button', { name: 'Check availability' } )
			.click();
		await page
			.getByText( /That date is available for submissions/ )
			.waitFor();
		await page.getByLabel( 'Artist or project name' ).waitFor();
		assert.ok( await page.getByLabel( 'Artist website' ).count() );
		assert.ok( await page.getByLabel( /I agree that this venue/ ).count() );
		assert.equal(
			await page.getByLabel( 'Other event details' ).count(),
			0
		);
		await page.getByLabel( 'Event type' ).selectOption( 'Other' );
		assert.ok( await page.getByLabel( 'Other event details' ).count() );
		await page.getByLabel( 'Event type' ).selectOption( 'Concert' );
		assert.equal( await page.getByText( /Link Page/ ).count(), 0 );
		assert.equal(
			await page
				.locator( '.ec-booking-inquiry__turnstile .cf-turnstile' )
				.count(),
			1
		);

		const desktop = await measure( page );
		assert.equal( desktop.scrollWidth, desktop.viewportWidth );
		assert.ok( desktop.inlineGutter > 0 );
		assert.equal( desktop.controlsInsideViewport, true );
		assert.ok(
			Number.parseFloat(
				await page
					.locator( '.ec-booking-inquiry__panel' )
					.evaluate(
						( panel ) => getComputedStyle( panel ).borderLeftWidth
					)
			) > 0
		);
		await page.screenshot( {
			path: path.join( artifacts, 'booking-inquiry-desktop.png' ),
			fullPage: true,
		} );

		await page.setViewportSize( { width: 390, height: 844 } );
		const mobile = await measure( page );
		assert.equal( mobile.scrollWidth, mobile.viewportWidth );
		assert.ok( mobile.inlineGutter > 0 );
		assert.ok( mobile.form.right < mobile.viewportWidth );
		assert.equal( mobile.controlsInsideViewport, true );

		await page.getByLabel( 'Artist or project name' ).fill( 'Proof Band' );
		await page.getByLabel( 'Contact name' ).fill( 'Booking Agent' );
		await page.getByLabel( 'Contact email' ).fill( 'agent@example.com' );
		await page.getByLabel( 'Event type' ).selectOption( 'Concert' );
		await page
			.getByLabel( 'Additional performance details' )
			.fill( 'Routing through Charleston.' );
		await page.getByLabel( /I agree that this venue/ ).check();
		await page
			.getByRole( 'button', { name: 'Send booking inquiry' } )
			.click();
		assert.equal(
			await page
				.getByText( 'Complete the security check before sending.' )
				.count(),
			1
		);
		await page.screenshot( {
			path: path.join( artifacts, 'booking-inquiry-mobile.png' ),
			fullPage: true,
		} );

		const evidence = {
			status: 'passed',
			viewports: [ '1280x900', '390x844' ],
			desktop,
			mobile,
			artifacts: [
				path.join( artifacts, 'booking-inquiry-desktop.png' ),
				path.join( artifacts, 'booking-inquiry-mobile.png' ),
			],
		};
		fs.writeFileSync(
			path.join( artifacts, 'measurements.json' ),
			`${ JSON.stringify( evidence, null, 2 ) }\n`
		);
		// eslint-disable-next-line no-console -- Emits deterministic evidence for CI and PR logs.
		console.log( JSON.stringify( evidence ) );
	} finally {
		await browser.close();
	}
} )().catch( ( error ) => {
	console.error( error );
	process.exitCode = 1;
} );
