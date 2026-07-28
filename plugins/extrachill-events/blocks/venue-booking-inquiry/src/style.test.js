/* global describe, expect, it */

/**
 * External dependencies
 */
import { readFileSync } from 'fs';
import path from 'path';

const styles = readFileSync( path.resolve( __dirname, 'style.scss' ), 'utf8' );

describe( 'venue booking inquiry styles', () => {
	it( 'composes the canonical shared component stylesheet', () => {
		expect( styles ).toContain(
			'@use "@extrachill/components/styles/components.scss";'
		);
	} );

	it( 'retains inquiry-specific responsive and accessibility behavior', () => {
		expect( styles ).toContain( '.ec-booking-inquiry__grid' );
		expect( styles ).toContain(
			'grid-template-columns: repeat(2, minmax(0, 1fr))'
		);
		expect( styles ).toContain( '@media (max-width: 700px)' );
		expect( styles ).toContain( 'grid-template-columns: 1fr' );
		expect( styles ).toContain( '.ec-booking-inquiry__result:focus' );
		expect( styles ).toContain( '@media (prefers-reduced-motion: reduce)' );
		expect( styles ).toContain( 'transition: none !important' );
	} );
} );
