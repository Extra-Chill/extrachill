/* eslint-disable import/no-extraneous-dependencies */

const assert = require( 'node:assert/strict' );
const { chromium } = require( 'playwright' );

const fixture = `<!doctype html>
<html lang="en">
	<head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width,initial-scale=1">
		<style>
			* { box-sizing: border-box; }
			html, body { margin: 0; }
			body { color: #181818; font: 16px/1.5 Arial, sans-serif; }
			main { margin-inline: auto; max-width: 760px; padding: 1rem; }
			textarea { display: block; max-width: 100%; width: 100%; }
			button, summary { cursor: pointer; }
		</style>
	</head>
	<body>
		<main>
			<section aria-labelledby="booking-website-heading">
				<h2 id="booking-website-heading">Put this booking form on your website</h2>
				<p>Add your venue website address so the form will only work there. Entering the address does not publish the form.</p>
				<label for="venue-booking-websites">Your venue website address</label>
				<textarea id="venue-booking-websites">https://venue.example</textarea>
				<p id="website-help">Enter the main HTTPS address, such as https://venue.example. This tells Extra Chill which website may display your form.</p>
				<p>Copy this code, then paste it into the page where you want the form or send it to the person who manages your website.</p>
				<button type="button" id="copy-code">Copy website code</button>
				<span id="copy-status" role="status"></span>
				<details>
					<summary>View advanced website code</summary>
					<label for="website-code">Website code</label>
					<textarea id="website-code" readonly>&lt;iframe title=&quot;Book The Room&quot;&gt;&lt;/iframe&gt;</textarea>
				</details>
			</section>
		</main>
		<script>
			document.getElementById('copy-code').addEventListener('click', async () => {
				await navigator.clipboard.writeText(document.getElementById('website-code').value);
				document.getElementById('copy-status').textContent = 'Code copied. It still needs to be added to your website.';
			});
		</script>
	</body>
</html>`;

const verifyViewport = async ( browser, viewport ) => {
	const context = await browser.newContext( { viewport } );
	await context.grantPermissions( [ 'clipboard-read', 'clipboard-write' ], {
		origin: 'https://evidence.example',
	} );
	const page = await context.newPage();
	await page.route( 'https://evidence.example/', ( route ) =>
		route.fulfill( { contentType: 'text/html', body: fixture } )
	);
	await page.goto( 'https://evidence.example/' );

	assert.equal(
		await page
			.getByRole( 'heading', {
				name: 'Put this booking form on your website',
			} )
			.count(),
		1
	);
	assert.equal(
		await page
			.getByText( 'Entering the address does not publish the form.', {
				exact: false,
			} )
			.count(),
		1
	);
	assert.equal(
		await page
			.getByText( 'send it to the person who manages your website', {
				exact: false,
			} )
			.count(),
		1
	);
	assert.equal(
		await page.getByLabel( 'Your venue website address' ).inputValue(),
		'https://venue.example'
	);

	const advanced = page.getByText( 'View advanced website code', {
		exact: true,
	} );
	assert.equal(
		await page.locator( 'details' ).getAttribute( 'open' ),
		null
	);
	await advanced.focus();
	await page.keyboard.press( 'Enter' );
	assert.equal( await page.locator( 'details' ).getAttribute( 'open' ), '' );

	const copy = page.getByRole( 'button', { name: 'Copy website code' } );
	await copy.focus();
	await page.keyboard.press( 'Enter' );
	await page.getByRole( 'status' ).waitFor();
	assert.equal(
		await page.getByRole( 'status' ).textContent(),
		'Code copied. It still needs to be added to your website.'
	);
	assert.equal(
		await page.evaluate( () => navigator.clipboard.readText() ),
		'<iframe title="Book The Room"></iframe>'
	);
	assert.equal(
		await page.evaluate( () => document.documentElement.scrollWidth ),
		await page.evaluate( () => document.documentElement.clientWidth )
	);

	await context.close();
};

( async () => {
	const browser = await chromium.launch( { headless: true } );
	try {
		await verifyViewport( browser, { width: 1440, height: 900 } );
		await verifyViewport( browser, { width: 390, height: 844 } );
	} finally {
		await browser.close();
	}
	process.stdout.write( 'Booking setup copy browser evidence passed.\n' );
} )().catch( ( error ) => {
	console.error( error );
	process.exitCode = 1;
} );
