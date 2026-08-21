/* eslint-disable import/no-extraneous-dependencies */

const assert = require( 'node:assert/strict' );
const { chromium } = require( 'playwright' );

const eventsUrl = 'https://events.example/my-shows/';
const registrationBase = 'https://community.example/login/';
const registrationUrl = `${ registrationBase }?redirect_to=${ encodeURIComponent(
	eventsUrl
) }#tab-register`;

const documentShell = ( body ) => `<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<style>
* { box-sizing: border-box; }
body { margin: 0; font: 16px/1.5 Arial, sans-serif; }
main { margin: 0 auto; max-width: 70rem; padding: 1rem; }
.hero { min-height: 42rem; }
.closing { padding-block: 3rem; }
.button-1 { display: inline-block; padding: .75rem 1rem; }
form { display: grid; gap: 1rem; max-width: 30rem; }
</style></head><body>${ body }</body></html>`;

const myShowsFixture = () =>
	documentShell( `<main>
	<section class="hero"><h1>Every show has a story. Keep yours.</h1>
		<a class="button-1" href="${ registrationUrl }">Start My Shows</a>
	</section>
	<section class="closing"><h2>Your next favorite show belongs here.</h2>
		<a class="button-1" href="${ registrationUrl }">Create Free Account</a>
	</section>
</main>` );

const registrationFixture = ( requestUrl ) => {
	const url = new URL( requestUrl );
	const requestedRedirect = url.searchParams.get( 'redirect_to' );
	const safeRedirect =
		requestedRedirect === eventsUrl ? requestedRedirect : '';
	return documentShell( `<main>
	<h1 tabindex="-1">Join Extra Chill</h1>
	<section id="register-panel" aria-labelledby="register-heading">
		<h2 id="register-heading">Create your account</h2>
		<form><label>Email address <input name="email" type="email" autocomplete="email"></label>
			<input name="success_redirect_url" type="hidden" value="${ safeRedirect }">
			<button type="submit">Register</button>
		</form>
	</section>
</main>` );
};

const assertRegistrationSurface = async ( page ) => {
	assert.equal( new URL( page.url() ).hash, '#tab-register' );
	assert.equal(
		new URL( page.url() ).searchParams.get( 'redirect_to' ),
		eventsUrl
	);
	await page.getByRole( 'heading', { name: 'Join Extra Chill' } ).waitFor();
	assert.equal(
		await page
			.getByRole( 'heading', { name: 'Create your account' } )
			.isVisible(),
		true
	);
	assert.equal( await page.getByLabel( 'Email address' ).isVisible(), true );
	await page.getByRole( 'heading', { name: 'Join Extra Chill' } ).focus();
	await page.keyboard.press( 'Tab' );
	assert.equal(
		await page
			.getByLabel( 'Email address' )
			.evaluate(
				( element ) => element === element.ownerDocument.activeElement
			),
		true
	);
};

( async () => {
	const browser = await chromium.launch( { headless: true } );
	try {
		const page = await browser.newPage( {
			viewport: { width: 1440, height: 1000 },
		} );
		await page.route( 'https://events.example/**', ( route ) =>
			route.fulfill( {
				contentType: 'text/html',
				body: myShowsFixture(),
			} )
		);
		await page.route( 'https://community.example/**', ( route ) =>
			route.fulfill( {
				contentType: 'text/html',
				body: registrationFixture( route.request().url() ),
			} )
		);

		await page.goto( eventsUrl );
		await page.getByRole( 'link', { name: 'Start My Shows' } ).click();
		await assertRegistrationSurface( page );
		await page.goBack();
		assert.equal( page.url(), eventsUrl );
		await page.reload();
		assert.equal(
			await page
				.getByRole( 'link', { name: 'Start My Shows' } )
				.isVisible(),
			true
		);

		await page.setViewportSize( { width: 390, height: 844 } );
		await page.goto( eventsUrl );
		await page
			.getByRole( 'link', { name: 'Create Free Account' } )
			.scrollIntoViewIfNeeded();
		await page.getByRole( 'link', { name: 'Create Free Account' } ).click();
		await assertRegistrationSurface( page );

		await page.goto(
			`${ registrationBase }?redirect_to=${ encodeURIComponent(
				'https://attacker.example/phish'
			) }#tab-register`
		);
		assert.equal(
			await page
				.locator( 'input[name="success_redirect_url"]' )
				.getAttribute( 'value' ),
			''
		);

		// eslint-disable-next-line no-console -- Deterministic PR evidence.
		console.log(
			JSON.stringify( {
				status: 'passed',
				viewports: [ '1440x1000', '390x844' ],
				continuation: eventsUrl,
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
