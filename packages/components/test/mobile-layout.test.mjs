import assert from 'node:assert/strict';
import { pathToFileURL } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';

const fixtureUrl = pathToFileURL( new URL( '../fixtures/mobile-layout.html', import.meta.url ).pathname ).href;

async function readLayout( page ) {
	return page.evaluate( () => {
		const bounds = ( selector ) => {
			const element = document.querySelector( selector );
			const rect = element.getBoundingClientRect();
			return { left: rect.left, right: rect.right, width: rect.width };
		};

		return {
			clientWidth: document.documentElement.clientWidth,
			scrollWidth: document.documentElement.scrollWidth,
			standalone: bounds( '[data-fixture="standalone"] > .ec-block-shell' ),
			nested: bounds( '[data-fixture="nested"] .ec-block-shell .ec-block-shell' ),
			responsiveTabs: bounds( '[data-fixture="responsive-tabs"] .ec-responsive-tabs' ),
			responsivePanel: bounds( '[data-fixture="responsive-tabs"] .ec-responsive-tabs__panel .ec-block-shell' ),
		};
	} );
}

for ( const width of [ 320, 390 ] ) {
	test( `mobile breakout stays within a ${ width }px viewport`, async () => {
		const browser = await chromium.launch( { headless: true } );
		const page = await browser.newPage( { viewport: { width, height: 844 } } );

		try {
			await page.goto( fixtureUrl );
			const layout = await readLayout( page );

			assert.equal( layout.scrollWidth, layout.clientWidth );
			assert.deepEqual( layout.standalone, { left: 0, right: width, width } );
			assert.deepEqual( layout.nested, { left: 0, right: width, width } );
			assert.deepEqual( layout.responsiveTabs, { left: 0, right: width, width } );
			assert.deepEqual( layout.responsivePanel, { left: 0, right: width, width } );
		} finally {
			await browser.close();
		}
	} );
}

test( 'desktop shells retain the content gutter', async () => {
	const browser = await chromium.launch( { headless: true } );
	const page = await browser.newPage( { viewport: { width: 1024, height: 768 } } );

	try {
		await page.goto( fixtureUrl );
		const layout = await readLayout( page );

		assert.equal( layout.scrollWidth, layout.clientWidth );
		assert.deepEqual( layout.standalone, { left: 16, right: 1008, width: 992 } );
		assert.deepEqual( layout.nested, { left: 16, right: 1008, width: 992 } );
		assert.deepEqual( layout.responsiveTabs, { left: 16, right: 1008, width: 992 } );
		assert.deepEqual( layout.responsivePanel, { left: 17, right: 1007, width: 990 } );
	} finally {
		await browser.close();
	}
} );
