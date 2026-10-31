/* global MouseEvent, navigator */

function mountTrackingPage() {
	document.body.innerHTML = `
		<div class="extrch-link-page-content-wrapper">
			<a href="https://destination.example/song">
				<span class="extrch-link-page-link-text">Listen now</span>
			</a>
		</div>
	`;
	document.body.dataset.extrchTrackingClickUrl =
		'https://artist.example/analytics/click';
	document.body.dataset.extrchLinkPageId = '42';
}

describe( 'public link page tracking', () => {
	beforeEach( () => {
		jest.resetModules();
		global.fetch = jest.fn().mockResolvedValue( {} );
		Object.defineProperty( navigator, 'sendBeacon', {
			configurable: true,
			value: undefined,
		} );
		mountTrackingPage();
	} );

	test( 'tracks clicks without a legacy view endpoint', () => {
		require( '../../inc/link-pages/live/assets/js/link-page-public-tracking.js' );

		document.querySelector( 'a' ).dispatchEvent(
			new MouseEvent( 'click', {
				bubbles: true,
				cancelable: true,
			} )
		);

		expect( fetch ).toHaveBeenCalledTimes( 1 );
		expect( fetch ).toHaveBeenCalledWith(
			'https://artist.example/analytics/click',
			expect.objectContaining( {
				method: 'POST',
				body: JSON.stringify( {
					click_type: 'link_page_link',
					link_page_id: '42',
					source_url: window.location.href,
					destination_url: 'https://destination.example/song',
					element_text: 'Listen now',
				} ),
			} )
		);
	} );
} );
