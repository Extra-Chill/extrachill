import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server.node';
import ShowsTab from '../../src/blocks/artist-manager/components/ShowsTab';

test( 'shows both existing intake paths for the selected artist', () => {
	const html = renderToStaticMarkup(
		<ShowsTab
			artistName="Band & Friends"
			submitUrl="https://events.example/submit/"
		/>
	);
	const container = document.createElement( 'div' );
	container.innerHTML = html;
	const links = container.querySelectorAll( 'a' );
	expect( links[ 0 ].href ).toBe( 'https://events.example/submit/' );
	expect( links[ 1 ].href ).toBe(
		'https://events.example/submit/#ec-event-source-import'
	);
	expect( container.textContent ).toContain( 'Band & Friends' );
} );

test( 'does not render broken links without a submission URL', () => {
	expect(
		renderToStaticMarkup( <ShowsTab artistName="Band" submitUrl="" /> )
	).toBe( '' );
} );
