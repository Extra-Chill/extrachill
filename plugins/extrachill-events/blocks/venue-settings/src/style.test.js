/* global describe, expect, it */

/**
 * External dependencies
 */
import { readFileSync } from 'fs';
import path from 'path';

const styles = readFileSync( path.resolve( __dirname, 'style.scss' ), 'utf8' );
const consoleSource = readFileSync(
	path.resolve( __dirname, 'booking-console.js' ),
	'utf8'
);

describe( 'venue booking workspace composition', () => {
	it( 'loads and composes the canonical shared primitives', () => {
		expect( styles ).toContain(
			'@use "@extrachill/components/styles/components.scss";'
		);
		expect( consoleSource ).toContain( '<Badge tone=' );
		expect( consoleSource ).toContain( '<PanelHeader' );
		expect( consoleSource ).toContain( '<Tabs' );
		expect( consoleSource ).not.toContain( 'aria-pressed={ view ===' );
		expect( consoleSource ).toContain(
			'className="ec-booking-console__toolbar"'
		);
		expect( consoleSource ).toContain( '`ec-panel ec-booking-card${' );
	} );

	it( 'uses canonical semantic tokens for unique calendar states', () => {
		[ 'info', 'warning', 'success', 'error' ].forEach( ( tone ) => {
			expect( styles ).toContain( `var(--${ tone }-color)` );
		} );
	} );

	it( 'does not reintroduce local colors or arbitrary breakpoints', () => {
		expect( styles ).not.toMatch( /#[0-9a-f]{3,8}\b/i );
		expect( styles ).not.toMatch( /max-width:\s*(?:700|720)px/ );
		expect( styles ).toContain( '@media screen and (max-width: 480px)' );
	} );
} );
