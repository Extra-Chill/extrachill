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
		expect( view ).toContain(
			'<BlockShellInner className="ec-panel ec-booking-inquiry__panel">'
		);
		expect( view ).toContain( 'Grid minColumnWidth="16rem"' );
		expect( view ).toContain( '<PanelHeader' );
		expect( view ).toContain( '<Badge tone="success">Now booking</Badge>' );
		expect( view ).toContain(
			'<Badge tone="info">Signed-in inquiry</Badge>'
		);
	} );

	it( 'contains the shared grid within the booking panel', () => {
		expect( styles ).toMatch(
			/\.ec-booking-inquiry__form\s*\{[^}]*min-width:\s*0;[^}]*width:\s*100%;[^}]*\}/
		);
		expect( styles ).toMatch(
			/\.ec-booking-inquiry__form > \.ec-card-grid\s*\{[^}]*min-width:\s*0;[^}]*width:\s*100%;[^}]*\}/
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
