/* eslint-disable no-undef -- Homeboy's file-scoped ESLint runner does not load the Jest environment. */

/**
 * External dependencies
 */
import fs from 'node:fs';
import path from 'node:path';

const read = ( relativePath ) =>
	fs.readFileSync( path.resolve( __dirname, relativePath ), 'utf8' );

describe( 'Network exact date contract', () => {
	it( 'forwards exact date query parameters in first-party clients', () => {
		const clientSource = read( '../../app/client.ts' );
		expect(
			clientSource.match( /query\.set\( 'start_date'/g )
		).toHaveLength( 3 );
		expect( clientSource.match( /query\.set\( 'end_date'/g ) ).toHaveLength(
			3
		);
	} );

	it( 'sends the same exact pair through every report', () => {
		for ( const file of [
			'sessions-chart.tsx',
			'surface-growth-chart.tsx',
			'retention-chart.tsx',
			'conversion-map-chart.tsx',
		] ) {
			const source = read( file );
			expect( source ).toContain( 'start_date: dateRange.startDate' );
			expect( source ).toContain( 'end_date: dateRange.endDate' );
		}
		expect( read( 'surface-growth-chart.tsx' ) ).not.toContain(
			'Math.round'
		);
	} );

	it( 'preserves site, cohort, and author controls', () => {
		expect( read( 'retention-chart.tsx' ) ).toContain( 'blog_id: blogId' );
		expect( read( 'retention-chart.tsx' ) ).toContain(
			'cohort_weeks: cohortWeeks'
		);
		expect( read( 'conversion-map-chart.tsx' ) ).toContain(
			'author_id: authorId'
		);
	} );
} );
