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
		expect( consoleSource ).toContain(
			'className="ec-booking-console__view-switcher"'
		);
		expect( consoleSource ).not.toContain( '<legend>View</legend>' );
		expect( consoleSource ).toContain(
			'className="ec-booking-console__search"'
		);
		expect( consoleSource ).toContain( 'aria-pressed={ view ===' );
		expect( consoleSource ).toContain(
			"? 'button-1 button-medium is-active'"
		);
		expect( consoleSource ).toContain(
			'ec-booking-card button-3 button-medium button-block'
		);
		expect( consoleSource ).toContain(
			'ec-booking-calendar__support-action button-2 button-small'
		);
		expect( consoleSource ).toContain(
			'className="ec-booking-console__toolbar"'
		);
		expect( consoleSource ).toContain(
			'<ul className="ec-booking-console__list">'
		);
		expect( consoleSource ).toContain( 'Booking inbox' );
		expect( consoleSource ).toContain( 'Booking actions' );
		expect( styles ).toContain(
			'grid-template-columns: auto minmax(16rem, 1fr) minmax(10rem, 14rem);'
		);
		expect( styles ).not.toContain(
			'.ec-booking-console__view-switcher button.is-active'
		);
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

	it( 'keeps technical and mobile preview details progressive', () => {
		expect( styles ).toContain( '.ec-booking-embed-advanced' );
		expect( styles ).toContain(
			'.ec-booking-form-editor__preview-toggle.button-2'
		);
		expect( styles ).toContain( '@media screen and (max-width: 1200px)' );
	} );

	it( 'reserves enough desktop width for the production form preview', () => {
		expect( styles ).toContain(
			'grid-template-columns: minmax(0, 0.85fr) minmax(0, 1.15fr);'
		);
	} );
} );
