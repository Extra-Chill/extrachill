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
	followThrough: {
		status: '/follow-through/status',
		correction: '/follow-through/correction',
		withdrawal: '/follow-through/withdrawal',
		receiptRecovery: '/follow-through/receipt-recovery',
	},
	restNonce: 'booking-proof-nonce',
	authenticated: true,
	heading: 'Booking inquiries',
	headingLevel: 2,
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
			<section class="wp-block-extrachill-venue-booking-inquiry ec-venue-booking-inquiry" aria-labelledby="ec-booking-browser-proof-heading">
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
		await page.route( 'https://booking.test/**', ( route ) =>
			route.fulfill( { body: fixture, contentType: 'text/html' } )
		);
		const mount = async (
			expectedSelector = '.ec-booking-inquiry__form'
		) => {
			await page.goto( 'https://booking.test/' );
			await page.addStyleTag( { content: fixtureStyles } );
			await page.addStyleTag( {
				path: path.join(
					root,
					'build/venue-booking-inquiry/style-index.css'
				),
			} );
			if ( ! ( await page.evaluate( () => Boolean( window.React ) ) ) ) {
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
			}
			await page.evaluate( () => {
				window.wp = {
					element: {
						...window.React,
						createRoot: window.ReactDOM.createRoot,
					},
				};
				window.bookingAvailable = false;
				window.requestedUrls = [];
				window.fetch = async ( url ) => {
					window.requestedUrls.push( String( url ) );
					if ( String( url ).includes( 'booking-availability' ) ) {
						return new Response(
							JSON.stringify( {
								available: window.bookingAvailable,
							} ),
							{
								status: 200,
								headers: {
									'Content-Type': 'application/json',
								},
							}
						);
					}
					if ( String( url ).includes( 'follow-through/status' ) ) {
						return new Response(
							JSON.stringify( {
								public_id:
									'123e4567-e89b-42d3-a456-426614174000',
								status: 'submitted',
								status_label: 'Pending review',
								version: 1,
								requested_interval: {
									start_at: '2030-08-01 00:00:00',
									end_at: '2030-08-02 00:00:00',
								},
								requested_space: {
									key: 'main',
									label: 'Main Room',
								},
								permitted_actions: [
									'request_correction',
									'withdraw',
								],
							} ),
							{
								status: 200,
								headers: { 'Content-Type': 'application/json' },
							}
						);
					}
					return new Response(
						JSON.stringify( {
							public_id: '123e4567-e89b-42d3-a456-426614174000',
							venue_term_id: 55,
							capability: 'a'.repeat( 64 ),
						} ),
						{
							status: 201,
							headers: { 'Content-Type': 'application/json' },
						}
					);
				};
			} );
			await page.addScriptTag( {
				path: path.join( root, 'build/venue-booking-inquiry/view.js' ),
			} );
			await page.waitForSelector( expectedSelector );
		};
		const assertBookingSemantics = async () => {
			const heading = page.getByRole( 'heading', {
				level: 2,
				name: 'Booking at The Room',
			} );
			assert.equal( await heading.count(), 1 );
			assert.equal(
				await heading.getAttribute( 'id' ),
				'ec-booking-browser-proof-heading'
			);
			assert.equal(
				await page
					.getByRole( 'region', { name: 'Booking at The Room' } )
					.count(),
				1
			);
		};
		await mount();
		await assertBookingSemantics();

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
			await page.getByText( 'Send a concise booking pitch' ).count(),
			0
		);
		assert.equal(
			await page
				.getByRole( 'button', {
					name: 'Recover an existing inquiry',
				} )
				.count(),
			1
		);
		assert.equal(
			await page.getByText( 'Event type (required)' ).count(),
			0
		);
		assert.equal( await page.getByText( 'Press links' ).count(), 0 );
		assert.equal(
			await page.getByLabel( 'Artist or band name' ).count(),
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
			await page.getByLabel( 'Artist or band name' ).count(),
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
		await page.getByLabel( 'Artist or band name' ).waitFor();
		assert.equal( await page.getByLabel( 'Artist website' ).count(), 1 );
		assert.equal( await page.getByLabel( 'Press links' ).count(), 1 );
		assert.equal(
			await page.getByText( 'Optional links and details' ).count(),
			1
		);
		assert.equal(
			await page
				.locator( 'textarea#ec-booking-browser-proof-press_links' )
				.count(),
			1
		);
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

		await page.getByLabel( 'Artist or band name' ).fill( 'Proof Band' );
		await page.getByLabel( 'Your name' ).fill( 'Booking Agent' );
		await page.getByLabel( 'Email' ).fill( 'agent@example.com' );
		await page.getByLabel( 'Event type' ).selectOption( 'Concert' );
		await page
			.getByLabel( "What's your vision for the show?" )
			.fill( 'Routing through Charleston.' );
		await page.waitForFunction( () => sessionStorage.length === 1 );
		const sessionDraft = await page.evaluate( () =>
			JSON.parse( sessionStorage.getItem( sessionStorage.key( 0 ) ) )
		);
		assert.equal( sessionDraft.scope, 'session' );
		assert.equal( await page.evaluate( () => localStorage.length ), 0 );
		assert.deepEqual( Object.keys( sessionDraft.values ).sort(), [
			'artistName',
			'contactEmail',
			'contactName',
			'contactPhone',
			'fields',
			'message',
			'requestedDate',
			'spaceKey',
		] );
		assert.equal(
			JSON.stringify( sessionDraft ).includes( 'consent' ),
			false
		);

		await mount();
		await page.getByText( 'Your saved answers were restored.' ).waitFor();
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
		await page.getByLabel( 'Artist or band name' ).waitFor();
		assert.equal(
			await page.getByLabel( 'Artist or band name' ).inputValue(),
			'Proof Band'
		);
		assert.equal(
			await page.getByLabel( /I agree that this venue/ ).isChecked(),
			false
		);
		// Drafts are session-scoped only; the device-scope opt-in was retired.
		await page.waitForFunction(
			() => sessionStorage.length === 1 && localStorage.length === 0
		);
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
		await page.evaluate( () => {
			const originalFetch = window.fetch;
			window.fetch = async ( url, options ) => {
				if (
					! String( url ).includes( 'booking-availability' ) &&
					window.retryFailed
				) {
					return new Response(
						JSON.stringify( { message: 'Temporary failure.' } ),
						{ status: 503 }
					);
				}
				return originalFetch( url, options );
			};
			window.retryFailed = true;
			const token = document.createElement( 'input' );
			token.name = 'cf-turnstile-response';
			token.value = 'browser-proof-token';
			document.querySelector( '.cf-turnstile' ).appendChild( token );
		} );
		await page
			.getByRole( 'button', { name: 'Send booking inquiry' } )
			.click();
		await page.getByText( 'Temporary failure.' ).waitFor();
		assert.equal(
			await page.getByLabel( 'Artist or band name' ).inputValue(),
			'Proof Band'
		);
		await page.evaluate( () => {
			window.retryFailed = false;
			const token = document.createElement( 'input' );
			token.name = 'cf-turnstile-response';
			token.value = 'browser-proof-token-retry';
			document.querySelector( '.cf-turnstile' ).appendChild( token );
		} );
		await page
			.getByRole( 'button', { name: 'Send booking inquiry' } )
			.click();
		await page.getByText( /Inquiry received/ ).waitFor();
		await assertBookingSemantics();
		await page.getByText( 'Pending review' ).waitFor();
		assert.equal(
			await page
				.getByText(
					/confirmation email should arrive within a few minutes/i
				)
				.count(),
			1
		);
		assert.equal(
			await page
				.getByRole( 'button', { name: 'Withdraw inquiry' } )
				.count(),
			1
		);
		assert.deepEqual(
			await page.evaluate( () => ( {
				local: Object.keys( localStorage ),
				session: Object.keys( sessionStorage ),
			} ) ),
			{
				local: [],
				session: [ 'extrachill.booking-inquiry.receipt.v1.55' ],
			}
		);
		const storedReceipt = await page.evaluate( () =>
			JSON.parse(
				sessionStorage.getItem(
					'extrachill.booking-inquiry.receipt.v1.55'
				)
			)
		);
		assert.deepEqual( Object.keys( storedReceipt ).sort(), [
			'capability',
			'public_id',
			'venue_term_id',
		] );
		assert.equal(
			await page.evaluate( () =>
				window.requestedUrls.some( ( url ) =>
					url.includes( 'a'.repeat( 64 ) )
				)
			),
			false
		);
		await mount( '.ec-booking-inquiry__result' );
		await page.getByText( 'Pending review' ).waitFor();
		assert.equal(
			await page
				.getByRole( 'button', { name: 'Refresh status' } )
				.count(),
			1
		);
		const withdraw = page.getByRole( 'button', {
			name: 'Withdraw inquiry',
		} );
		await withdraw.focus();
		await withdraw.press( 'Enter' );
		await page
			.getByRole( 'button', { name: 'Confirm withdrawal' } )
			.waitFor();
		assert.equal(
			await page
				.getByText( /ends this pending inquiry immediately/ )
				.count(),
			1
		);
		assert.equal(
			await page
				.locator( '.ec-booking-inquiry__result' )
				.evaluate(
					( node ) => node === node.ownerDocument.activeElement
				),
			true
		);
		await page.screenshot( {
			path: path.join( artifacts, 'booking-inquiry-mobile.png' ),
			fullPage: true,
		} );
		await page.evaluate( () => {
			const key = 'extrachill.booking-inquiry.v1.55.7';
			sessionStorage.setItem( key, 'private-session-draft' );
			localStorage.setItem( key, 'private-device-draft' );
		} );
		await page
			.getByRole( 'button', { name: 'Clear this receipt' } )
			.click();
		await page.getByLabel( 'Requested date' ).waitFor();
		assert.deepEqual(
			await page.evaluate( () => ( {
				session: sessionStorage.length,
				device: localStorage.length,
				date: document.querySelector( 'input[type="date"]' ).value,
			} ) ),
			{ session: 0, device: 0, date: '' }
		);

		await page.addInitScript( () => {
			Object.defineProperty( window, 'sessionStorage', {
				configurable: true,
				get: () => {
					throw new Error( 'session storage denied' );
				},
			} );
			Object.defineProperty( window, 'localStorage', {
				configurable: true,
				get: () => {
					throw new Error( 'local storage denied' );
				},
			} );
		} );
		await mount();
		await page
			.getByText(
				'Draft storage is unavailable. Your details will remain only on this page.'
			)
			.waitFor();
		await page.getByLabel( 'Requested date' ).fill( '2030-09-01' );
		await page
			.getByRole( 'button', { name: 'Check availability' } )
			.click();
		await page.getByText( /That date is unavailable/ ).waitFor();

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
