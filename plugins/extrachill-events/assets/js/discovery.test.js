/**
 * Discovery scope navigation request-state coverage.
 */
/* eslint-env jest */

function deferred() {
	let resolve;
	let reject;
	const promise = new Promise( ( promiseResolve, promiseReject ) => {
		resolve = promiseResolve;
		reject = promiseReject;
	} );

	return { promise, resolve, reject };
}

function response( html, success = true ) {
	return {
		ok: true,
		json: () =>
			Promise.resolve( {
				success,
				html,
				pagination: null,
				counter: null,
				navigation: null,
			} ),
	};
}

async function flushPromises() {
	await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
}

function clickScope( scope ) {
	document
		.querySelector( `a[data-scope="${ scope }"]` )
		.dispatchEvent(
			new MouseEvent( 'click', { bubbles: true, cancelable: true } )
		);
}

describe( 'discovery scope navigation', () => {
	let nav;
	let calendar;
	let content;

	beforeAll( () => {
		document.body.innerHTML = `
			<h1 class="page-title"></h1>
			<nav class="discovery-scope-nav" data-term-id="44" data-term-name="Austin">
				<ul></ul>
			</nav>
			<div class="data-machine-events-calendar"
				data-archive-taxonomy="location"
				data-archive-term-id="44"
				data-scope-token="44.signed-token">
				<div class="data-machine-events-content"></div>
			</div>`;

		nav = document.querySelector( '.discovery-scope-nav' );
		calendar = document.querySelector( '.data-machine-events-calendar' );
		content = document.querySelector( '.data-machine-events-content' );

		require( './discovery' );
		document.dispatchEvent( new Event( 'DOMContentLoaded' ) );
	} );

	beforeEach( () => {
		nav.querySelector( 'ul' ).innerHTML = `
			<li class="active"><a href="/location/austin/tonight/" data-scope="tonight" aria-current="page">Tonight</a></li>
			<li><a href="/location/austin/this-weekend/" data-scope="this-weekend">This Weekend</a></li>
			<li><a href="/location/austin/this-week/" data-scope="this-week">This Week</a></li>
			<li><a href="/location/austin/" data-scope="">All Shows</a></li>`;
		content.className = 'data-machine-events-content';
		content.innerHTML = '<p>Initial results</p>';
		calendar.setAttribute( 'data-scope', 'tonight' );
		window.history.replaceState(
			{ scope: 'tonight' },
			'',
			'/location/austin/tonight/'
		);
		global.fetch = jest.fn();
	} );

	it( 'preserves filters while keeping scope-owned values authoritative', async () => {
		window.history.replaceState(
			{ scope: 'tonight' },
			'',
			'/location/austin/tonight/?event_search=jazz&date_start=2026-07-20&date_end=2026-07-21&past=1&tax_filter%5Bartist%5D%5B%5D=7&tax_filter%5Bartist%5D%5B%5D=9&lat=30.1&lng=-97.7&radius=40&radius_unit=mi&paged=3&scope=forged&archive_taxonomy=venue&archive_term_id=99&scope_token=forged'
		);
		fetch.mockResolvedValue( response( '<p>Weekend results</p>' ) );

		clickScope( 'this-weekend' );
		await flushPromises();

		const browserParams = new URLSearchParams( window.location.search );
		expect( window.location.pathname ).toBe(
			'/location/austin/this-weekend/'
		);
		expect( browserParams.get( 'event_search' ) ).toBe( 'jazz' );
		expect( browserParams.getAll( 'tax_filter[artist][]' ) ).toEqual( [
			'7',
			'9',
		] );
		expect( browserParams.get( 'lat' ) ).toBe( '30.1' );
		expect( browserParams.has( 'paged' ) ).toBe( false );
		expect( browserParams.has( 'scope' ) ).toBe( false );
		expect( browserParams.has( 'archive_taxonomy' ) ).toBe( false );
		expect( browserParams.has( 'scope_token' ) ).toBe( false );

		const requestUrl = new URL(
			fetch.mock.calls[ 0 ][ 0 ],
			window.location
		);
		expect( requestUrl.searchParams.get( 'scope' ) ).toBe( 'this-weekend' );
		expect( requestUrl.searchParams.getAll( 'scope' ) ).toHaveLength( 1 );
		expect( requestUrl.searchParams.get( 'archive_taxonomy' ) ).toBe(
			'location'
		);
		expect( requestUrl.searchParams.get( 'archive_term_id' ) ).toBe( '44' );
		expect( requestUrl.searchParams.get( 'scope_token' ) ).toBe(
			'44.signed-token'
		);
		expect( requestUrl.searchParams.getAll( 'scope_token' ) ).toHaveLength(
			1
		);
		expect( requestUrl.searchParams.has( 'paged' ) ).toBe( false );
		expect( content.innerHTML ).toBe( '<p>Weekend results</p>' );
	} );

	it( 'ignores an older response that resolves after the latest request', async () => {
		const older = deferred();
		const latest = deferred();
		fetch
			.mockImplementationOnce( () => older.promise )
			.mockImplementationOnce( () => latest.promise );

		clickScope( 'this-weekend' );
		clickScope( 'this-week' );
		latest.resolve( response( '<p>Latest week</p>' ) );
		await flushPromises();
		older.resolve( response( '<p>Stale weekend</p>' ) );
		await flushPromises();

		expect( content.innerHTML ).toBe( '<p>Latest week</p>' );
		expect( window.location.pathname ).toBe(
			'/location/austin/this-week/'
		);
		expect( calendar.getAttribute( 'data-scope' ) ).toBe( 'this-week' );
	} );

	it( 'aborts superseded requests without exposing an error or clearing loading', async () => {
		const older = deferred();
		const latest = deferred();
		fetch
			.mockImplementationOnce( () => older.promise )
			.mockImplementationOnce( () => latest.promise );

		clickScope( 'this-weekend' );
		const olderSignal = fetch.mock.calls[ 0 ][ 1 ].signal;
		clickScope( 'this-week' );

		expect( olderSignal.aborted ).toBe( true );
		expect( content.classList.contains( 'loading' ) ).toBe( true );

		const abortError = new Error( 'Aborted' );
		abortError.name = 'AbortError';
		older.reject( abortError );
		await flushPromises();
		expect( content.classList.contains( 'loading' ) ).toBe( true );
		expect(
			content.querySelector( '.data-machine-events-error' )
		).toBeNull();

		latest.resolve( response( '<p>Latest week</p>' ) );
		await flushPromises();
		expect( content.classList.contains( 'loading' ) ).toBe( false );
	} );

	it( 'replaces stale HTML with a recoverable latest-request error', async () => {
		content.insertAdjacentHTML(
			'afterend',
			'<nav class="data-machine-events-pagination">Stale pages</nav><div class="data-machine-events-results-counter">Stale count</div>'
		);
		fetch
			.mockRejectedValueOnce( new Error( 'Offline' ) )
			.mockResolvedValueOnce( response( '<p>Recovered week</p>' ) );

		clickScope( 'this-week' );
		await flushPromises();

		expect( content.textContent ).not.toContain( 'Initial results' );
		expect( content.textContent ).toContain( 'Error loading events' );
		expect(
			calendar.querySelector( '.data-machine-events-pagination' )
		).toBeNull();
		expect(
			calendar.querySelector( '.data-machine-events-results-counter' )
		).toBeNull();
		expect(
			content.querySelector( '.data-machine-events-retry' )
		).not.toBeNull();

		content
			.querySelector( '.data-machine-events-retry' )
			.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );
		await flushPromises();

		expect( content.innerHTML ).toBe( '<p>Recovered week</p>' );
		expect( fetch ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'restores scope, filters, and results from popstate URL state', async () => {
		fetch.mockResolvedValue( response( '<p>Back to weekend jazz</p>' ) );
		window.history.replaceState(
			null,
			'',
			'/location/austin/this-weekend/?event_search=jazz&tax_filter%5Bvenue%5D%5B%5D=12'
		);

		window.dispatchEvent(
			new PopStateEvent( 'popstate', { state: null } )
		);
		await flushPromises();

		expect(
			document
				.querySelector( 'a[data-scope="this-weekend"]' )
				.getAttribute( 'aria-current' )
		).toBe( 'page' );
		expect( document.querySelector( '.page-title' ).textContent ).toBe(
			'Live Music in Austin This Weekend'
		);
		expect( document.title ).toContain( 'Austin This Weekend' );
		expect( content.innerHTML ).toBe( '<p>Back to weekend jazz</p>' );

		const requestUrl = new URL(
			fetch.mock.calls[ 0 ][ 0 ],
			window.location
		);
		expect( requestUrl.searchParams.get( 'event_search' ) ).toBe( 'jazz' );
		expect( requestUrl.searchParams.get( 'tax_filter[venue][]' ) ).toBe(
			'12'
		);
	} );

	it( 'restores a valid URL scope that has no navigation tab', async () => {
		fetch.mockResolvedValue( response( '<p>Today results</p>' ) );
		window.history.replaceState( null, '', '/location/austin/today/' );

		window.dispatchEvent(
			new PopStateEvent( 'popstate', { state: null } )
		);
		await flushPromises();

		expect( nav.querySelector( 'li.active' ) ).toBeNull();
		expect( document.querySelector( '.page-title' ).textContent ).toBe(
			'Live Music in Austin Today'
		);
		const requestUrl = new URL(
			fetch.mock.calls[ 0 ][ 0 ],
			window.location
		);
		expect( requestUrl.searchParams.get( 'scope' ) ).toBe( 'today' );
		expect( content.innerHTML ).toBe( '<p>Today results</p>' );
	} );
} );
