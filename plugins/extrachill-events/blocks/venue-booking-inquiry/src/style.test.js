/* global describe, expect, it */

/**
 * External dependencies
 */
import { readFileSync } from 'fs';
import path from 'path';

const styles = readFileSync( path.resolve( __dirname, 'style.scss' ), 'utf8' );
const view = readFileSync( path.resolve( __dirname, 'view.js' ), 'utf8' );

describe( 'venue booking inquiry styles', () => {
	it( 'composes the canonical shared component stylesheet', () => {
		expect( styles ).toContain(
			'@use "@extrachill/components/styles/components.scss";'
		);
	} );

	it( 'composes canonical form, identity, and status primitives', () => {
		expect( view ).toContain( 'Grid minColumnWidth="16rem"' );
		expect( view ).toContain( '<PanelHeader' );
		expect( view ).toContain( '<Badge tone="success">Now booking</Badge>' );
		expect( view ).toContain(
			'<Badge tone="info">Signed-in inquiry</Badge>'
		);
	} );

	it( 'keeps unique accessibility behavior token-driven', () => {
		expect( styles ).toContain( '.ec-booking-inquiry__result:focus' );
		expect( styles ).toContain( 'var(--focus-border-color)' );
		expect( styles ).toContain( '@media (prefers-reduced-motion: reduce)' );
		expect( styles ).toContain( 'transition: none !important' );
	} );

	it( 'does not reintroduce local colors or arbitrary breakpoints', () => {
		expect( styles ).not.toMatch( /#[0-9a-f]{3,8}\b/i );
		expect( styles ).not.toMatch( /max-width:\s*(?:700|720)px/ );
	} );
} );
