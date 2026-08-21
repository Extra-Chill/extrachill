/* eslint-disable import/no-extraneous-dependencies */

const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const os = require( 'node:os' );
const path = require( 'node:path' );
const { chromium } = require( 'playwright' );

const root = path.resolve( __dirname, '../..' );
const artifacts =
	process.env.VENUE_CLAIM_ARTIFACT_DIR ||
	path.join( os.tmpdir(), 'extrachill-events-venue-claim' );
const workspaceUrl =
	'https://events.example/venue-settings/?venue_id=44#tab-calendar';
const aggregateBookingUrl =
	'https://events.example/venue-settings/?booking_id=91&booking_venue_id=44#tab-calendar';
const canonicalBookingUrl =
	'https://events.example/venue-settings/?venue_id=44&booking_id=91#tab-calendar';
const claimReviewUrl =
	'https://events.example/venue-settings/?venue_id=44#tab-claims';
const archiveUrl = 'https://events.example/venue/the-room/';
const calendarStyles = fs.readFileSync(
	path.join( root, 'assets/css/calendar.css' ),
	'utf8'
);

const roles = {
	logged_out: {
		label: 'Sign in to claim or manage',
		href: `https://events.example/wp-login.php?redirect_to=${ encodeURIComponent(
			workspaceUrl
		) }`,
	},
	non_member: {
		label: 'Claim or request access',
		href: workspaceUrl,
	},
	pending_claim: {
		label: 'Claim or request access',
		href: workspaceUrl,
	},
	revoked_member: {
		label: 'Claim or request access',
		href: workspaceUrl,
	},
	active_member: { label: 'Manage Venue', href: workspaceUrl },
	administrator: { label: 'Review venue claims', href: claimReviewUrl },
};

const documentShell = ( body ) => `<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<style>
* { box-sizing: border-box; }
html, body { margin: 0; }
body { --spacing-xs: .25rem; --spacing-sm: .5rem; --spacing-md: 1rem; --spacing-lg: 1.5rem; --spacing-xl: 2rem; --font-size-sm: .875rem; --font-size-body: 1rem; --muted-text: #555; --link-color: #174ea6; --link-hover-color: #123b7d; font: 16px/1.5 Arial, sans-serif; }
.page-content { margin-inline: auto; max-width: 70rem; padding: 1rem; }
.button-1, .button-3 { display: inline-block; padding: .65rem .9rem; }
${ calendarStyles }
</style></head><body>${ body }</body></html>`;

const archiveFixture = ( role, bookingEnabled = true ) => {
	const action = roles[ role ];
	return documentShell( `<main class="events-calendar-container">
	<div class="page-content">
		<header class="taxonomy-archive-header venue-archive-header">
			<h1 class="page-title">The Room Live Music Calendar</h1>
			${
				bookingEnabled
					? '<p class="venue-booking-cta"><a class="button-1" href="#booking">Submit a booking inquiry</a></p>'
					: ''
			}
			<div class="taxonomy-description"><p>An independent music room.</p></div>
		</header>
		<details class="venue-workspace-disclosure" data-venue-workspace-action>
			<summary>Own or manage this venue?</summary>
			<div class="venue-workspace-disclosure__content">
				<p>Venue operators can manage inquiries, calendar details, and team access.</p>
				<a class="button-3 button-small" href="${ action.href }">${ action.label }</a>
			</div>
		</details>
	</div>
	<div class="page-content"><section class="calendar">Upcoming shows</section></div>
	${
		bookingEnabled
			? '<div class="page-content" id="booking"><h2>Booking at The Room</h2></div>'
			: ''
	}
</main>` );
};

const workspaceFixture = ( url, expired ) => {
	const params = new URL( url ).searchParams;
	const venueId =
		params.get( 'venue_id' ) || params.get( 'booking_venue_id' );
	const bookingId = params.get( 'booking_id' );
	const destination = `https://events.example/venue-settings/?venue_id=${ venueId }${
		bookingId ? `&booking_id=${ bookingId }` : ''
	}#tab-calendar`;
	return documentShell( `<main class="page-content"><h1>Venue settings</h1>
		<p data-requested-venue>${ venueId }</p>
		${
			expired
				? `<a href="https://events.example/wp-login.php?redirect_to=${ encodeURIComponent(
						destination
				  ) }">Sign in</a>`
				: '<p>ClaimPanel</p>'
		}
	</main>` );
};

( async () => {
	fs.mkdirSync( artifacts, { recursive: true } );
	const browser = await chromium.launch( { headless: true } );
	let currentRole = 'logged_out';
	let bookingEnabled = true;
	let expired = false;

	try {
		const page = await browser.newPage( {
			viewport: { width: 1280, height: 900 },
		} );
		await page.route( 'https://events.example/**', async ( route ) => {
			const url = route.request().url();
			const pathname = new URL( url ).pathname;
			if ( pathname.startsWith( '/venue-settings/' ) ) {
				await route.fulfill( {
					contentType: 'text/html',
					body: workspaceFixture( url, expired ),
				} );
				return;
			}
			await route.fulfill( {
				contentType: 'text/html',
				body: archiveFixture( currentRole, bookingEnabled ),
			} );
		} );

		for ( const [ role, expected ] of Object.entries( roles ) ) {
			currentRole = role;
			await page.goto( `${ archiveUrl }?role=${ role }` );
			const action = page.locator( '[data-venue-workspace-action] a' );
			assert.equal( await action.textContent(), expected.label );
			assert.equal( await action.getAttribute( 'href' ), expected.href );
		}

		currentRole = 'non_member';
		bookingEnabled = true;
		await page.goto( archiveUrl );
		const details = page.locator( '[data-venue-workspace-action]' );
		const summary = details.locator( 'summary' );
		const booking = page.getByRole( 'link', {
			name: 'Submit a booking inquiry',
		} );
		assert.equal( await details.getAttribute( 'open' ), null );
		assert.equal( await details.getByRole( 'link' ).isVisible(), false );
		assert.ok(
			( await booking.boundingBox() ).y <
				( await details.boundingBox() ).y
		);
		await summary.focus();
		assert.equal(
			await summary.evaluate(
				( element ) => element === element.ownerDocument.activeElement
			),
			true
		);
		await page.keyboard.press( 'Enter' );
		assert.equal( await details.getAttribute( 'open' ), '' );
		assert.equal( await details.getByRole( 'link' ).isVisible(), true );
		await page.screenshot( {
			path: path.join( artifacts, 'venue-claim-desktop.png' ),
			fullPage: false,
		} );

		await details.getByRole( 'link' ).click();
		assert.equal( page.url(), workspaceUrl );
		assert.equal(
			await page.locator( '[data-requested-venue]' ).textContent(),
			'44'
		);
		await page.goBack();
		assert.equal( new URL( page.url() ).pathname, '/venue/the-room/' );
		await page.reload();
		assert.equal(
			await page
				.locator( '[data-venue-workspace-action]' )
				.getAttribute( 'open' ),
			null
		);

		expired = true;
		await page.goto( workspaceUrl );
		const expiredLogin = page.getByRole( 'link', { name: 'Sign in' } );
		const expiredRedirect = new URL(
			await expiredLogin.getAttribute( 'href' )
		).searchParams.get( 'redirect_to' );
		assert.equal( expiredRedirect, workspaceUrl );

		await page.goto( aggregateBookingUrl );
		const aggregateRedirect = new URL(
			await page
				.getByRole( 'link', { name: 'Sign in' } )
				.getAttribute( 'href' )
		).searchParams.get( 'redirect_to' );
		assert.equal( aggregateRedirect, canonicalBookingUrl );

		expired = false;
		bookingEnabled = false;
		await page.setViewportSize( { width: 390, height: 844 } );
		await page.goto( `${ archiveUrl }?booking=disabled` );
		assert.equal(
			await page
				.getByRole( 'link', {
					name: 'Submit a booking inquiry',
				} )
				.count(),
			0
		);
		assert.equal(
			await page.locator( '[data-venue-workspace-action]' ).count(),
			1
		);
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
			fullPage: false,
		} );

		// eslint-disable-next-line no-console -- Deterministic PR evidence.
		console.log(
			JSON.stringify( {
				status: 'passed',
				roles: Object.keys( roles ),
				viewports: [ '1280x900', '390x844' ],
			} )
		);
		await page.close();
	} finally {
		await browser.close();
	}
} )().catch( ( error ) => {
	console.error( error );
	process.exitCode = 1;
} );
