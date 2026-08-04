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

	it( 'relies on shared form components without local decoration', () => {
		expect( view ).toContain(
			'<BlockShellInner className="ec-panel ec-booking-inquiry__panel">'
		);
		expect( view ).toContain( 'Grid minColumnWidth="16rem"' );
		expect( view ).not.toContain( '<Badge' );
		expect( view ).toContain( 'className="taxonomy-badge"' );
		expect( styles ).not.toContain( '.ec-' );
	} );

	it( 'does not duplicate the configured intake before availability', () => {
		expect( view ).not.toContain( 'Have your pitch ready' );
		expect( view ).not.toContain( 'ec-booking-inquiry__field-preview' );
		expect( view ).not.toContain( 'submission notes' );
		expect( view ).not.toContain( 'Link Page' );
		expect( view ).not.toContain( 'Accepting inquiries' );
	} );

	it( 'asks for one date without exposing time controls', () => {
		expect( view ).toContain( 'label="Requested date"' );
		expect( view ).toContain( 'type="date"' );
		expect( view ).not.toContain( 'type="datetime-local"' );
		expect( view ).not.toContain( '<fieldset' );
		expect( view ).toContain( 'config.spaces.length > 1' );
	} );
} );
