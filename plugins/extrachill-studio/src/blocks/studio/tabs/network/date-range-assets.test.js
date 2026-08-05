/* eslint-disable no-undef -- Homeboy's file-scoped ESLint runner does not load the Jest environment. */

/**
 * External dependencies
 */
import fs from 'node:fs';
import path from 'node:path';

const projectRoot = path.resolve( __dirname, '../../../../..' );

describe( 'Network date range asset contract', () => {
	it( 'externalizes the Analytics runtime to its script handle', () => {
		const webpackSource = fs.readFileSync(
			path.join( projectRoot, 'webpack.config.js' ),
			'utf8'
		);
		expect( webpackSource ).toContain(
			"const DATE_RANGE_REQUEST = 'extrachill-analytics-date-range'"
		);
		expect( webpackSource ).toContain(
			"const DATE_RANGE_GLOBAL = 'ExtraChillAnalyticsDateRange'"
		);
		expect( webpackSource ).not.toContain( "require( 'flatpickr' )" );
	} );

	it( 'packages the Analytics-owned style handle', () => {
		const metadata = JSON.parse(
			fs.readFileSync(
				path.join( projectRoot, 'src/blocks/studio/block.json' ),
				'utf8'
			)
		);
		expect( metadata.viewStyle ).toEqual( [
			'file:./view.css',
			'extrachill-analytics-date-range',
		] );
	} );
} );
