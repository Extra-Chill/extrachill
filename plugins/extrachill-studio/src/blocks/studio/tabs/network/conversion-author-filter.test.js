/* global describe, expect, it */

/**
 * External dependencies
 */
import fs from 'node:fs';
import path from 'node:path';

const networkSource = fs.readFileSync(
	path.resolve( __dirname, 'index.tsx' ),
	'utf8'
);
const chartSource = fs.readFileSync(
	path.resolve( __dirname, 'conversion-map-chart.tsx' ),
	'utf8'
);
const clientSource = fs.readFileSync(
	path.resolve( __dirname, '../../app/client.ts' ),
	'utf8'
);

describe( 'Network conversion author filter', () => {
	it( 'offers all-post and current-user scopes', () => {
		expect( networkSource ).toContain( "<option value=\"all\">" );
		expect( networkSource ).toContain( "<option value=\"mine\"" );
		expect( networkSource ).toContain( "useSelect( ( selectStore )" );
		expect( networkSource ).toContain( "selectStore( 'core' )" );
		expect( networkSource ).toContain( 'authorId={ conversionAuthorId }' );
	} );

	it( 'requests a server-filtered conversion report', () => {
		expect( chartSource ).toContain( 'author_id: authorId' );
		expect( chartSource ).toContain( '[ authorId, days ]' );
		expect( clientSource ).toContain( "query.set( 'author_id'" );
	} );
} );
