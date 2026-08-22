/* eslint-disable import/no-extraneous-dependencies */

const assert = require( 'node:assert/strict' );
const { chromium } = require( 'playwright' );

const child = `<!doctype html><html><body style="margin:0"><main style="height:840px"><section aria-labelledby="ec-booking-embed-heading"><h1 id="ec-booking-embed-heading">Booking at The Room</h1></section><a href="https://events.example/venue/the-room/#booking-inquiry" target="_blank">Open this booking form on Extra Chill</a></main><script>(function(){const parentOrigin='https://allowed.example';const sendHeight=()=>window.parent.postMessage({type:'extrachill:booking-height',height:Math.ceil(document.documentElement.scrollHeight)},parentOrigin);new ResizeObserver(sendHeight).observe(document.documentElement);window.addEventListener('load',sendHeight);}());</script></body></html>`;
const parent = ( childUrl ) =>
	`<!doctype html><html><body><iframe id="booking" src="${ childUrl }" title="Book The Room" loading="lazy" style="width:100%;min-height:320px;border:0"></iframe><script>(function(){const frame=document.getElementById('booking');window.bookingMessages=[];window.addEventListener('message',function(event){const data=event.data;if(event.source!==frame.contentWindow||event.origin!=='https://events.example'||!data||data.type!=='extrachill:booking-height'||!Number.isInteger(data.height)||data.height<320||data.height>10000){return;}window.bookingMessages.push(data);frame.style.height=data.height+'px';});}());</script></body></html>`;

( async () => {
	const browser = await chromium.launch( { headless: true } );
	try {
		const childUrl =
			'https://events.example/venue/the-room/?booking-embed=1&parent-origin=https%3A%2F%2Fallowed.example';
		const allowed = await browser.newPage();
		await allowed.route( '**/*', async ( route ) => {
			const url = route.request().url();
			if ( url.startsWith( 'https://events.example/' ) ) {
				await route.fulfill( {
					contentType: 'text/html',
					headers: {
						'Content-Security-Policy':
							"frame-ancestors 'self' https://allowed.example",
						'Cache-Control': 'no-store, private',
					},
					body: child,
				} );
				return;
			}
			await route.fulfill( {
				contentType: 'text/html',
				body: parent( childUrl ),
			} );
		} );
		await allowed.goto( 'https://allowed.example/' );
		await allowed.waitForFunction(
			() => window.bookingMessages.length > 0
		);
		const evidence = await allowed.evaluate( () => ( {
			messages: window.bookingMessages,
			height: document.getElementById( 'booking' ).style.height,
		} ) );
		assert.match( evidence.height, /^\d+px$/ );
		assert.deepEqual( Object.keys( evidence.messages[ 0 ] ).sort(), [
			'height',
			'type',
		] );
		assert.equal(
			await allowed
				.frameLocator( '#booking' )
				.getByRole( 'heading', {
					level: 1,
					name: 'Booking at The Room',
				} )
				.count(),
			1
		);
		assert.equal(
			await allowed
				.frameLocator( '#booking' )
				.getByRole( 'region', { name: 'Booking at The Room' } )
				.count(),
			1
		);
		assert.equal(
			await allowed
				.frameLocator( '#booking' )
				.getByRole( 'link', {
					name: 'Open this booking form on Extra Chill',
				} )
				.count(),
			1
		);

		const denied = await browser.newPage();
		await denied.route( '**/*', async ( route ) => {
			if (
				route.request().url().startsWith( 'https://events.example/' )
			) {
				await route.fulfill( {
					contentType: 'text/html',
					headers: {
						'Content-Security-Policy':
							"frame-ancestors 'self' https://allowed.example",
					},
					body: child,
				} );
				return;
			}
			await route.fulfill( {
				contentType: 'text/html',
				body: parent( childUrl ),
			} );
		} );
		await denied.goto( 'https://denied.example/' );
		await denied.waitForTimeout( 250 );
		assert.equal(
			await denied.evaluate( () => window.bookingMessages.length ),
			0
		);
		assert.equal(
			denied.frames().some( ( frame ) => frame.url() === childUrl ),
			false
		);
	} finally {
		await browser.close();
	}
	process.stdout.write( 'Booking embed browser evidence passed.\n' );
} )().catch( ( error ) => {
	console.error( error );
	process.exitCode = 1;
} );
