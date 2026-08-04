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
		expect( styles.trim() ).toBe(
			'@use "@extrachill/components/styles/components.scss";'
		);
	} );

	it( 'relies on shared components and the theme badge system', () => {
		expect( view ).toContain(
			'<BlockShellInner className="ec-panel ec-booking-inquiry__panel">'
		);
		expect( view ).toContain( 'Grid minColumnWidth="16rem"' );
		expect( view ).toContain( 'className="taxonomy-badge"' );
		expect( view ).not.toContain( '<Badge' );
		expect( styles ).not.toContain( '.ec-' );
	} );

	it( 'surfaces configured intake requirements before availability', () => {
		expect( view ).toContain( 'Have your pitch ready' );
		expect( view ).toContain( 'config.fields.map( ( field ) =>' );
		expect( view ).toContain( "field.required ? ' (required)' : ''" );
	} );

	it( 'asks for one date without exposing time controls', () => {
		expect( view ).toContain( 'label="Requested date"' );
		expect( view ).toContain( 'type="date"' );
		expect( view ).not.toContain( 'type="datetime-local"' );
		expect( view ).not.toContain( '<fieldset' );
		expect( view ).toContain( 'config.spaces.length > 1' );
	} );
} );
