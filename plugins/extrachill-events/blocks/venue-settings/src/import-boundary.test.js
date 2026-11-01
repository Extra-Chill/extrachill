/** Booking dependencies may only be imported by explicitly owned modules. */
import fs from 'fs';
import path from 'path';

const owned = new Set( [
	'booking-console.js',
	'booking-embed.js',
	'booking-form-tab.js',
	'booking-settings.js',
	'booking-state.js',
	'booking-tab.js',
	'intake-tab.js',
] );

function violations( filename, source ) {
	if ( owned.has( filename ) ) {
		return [];
	}
	return [
		...source.matchAll(
			/(?:\bfrom\s*|\bimport\s*\(?\s*|\brequire\s*\(\s*)['"]([^'"]+)['"]/g
		),
	]
		.map( ( match ) => match[ 1 ] )
		.filter( ( module ) => {
			const target =
				path.basename( module ).replace( /\.js$/, '' ) + '.js';
			return owned.has( target );
		} );
}

test( 'non-booking venue settings modules do not import booking modules', () => {
	const failures = [];
	for ( const filename of fs.readdirSync( __dirname ) ) {
		if ( ! filename.endsWith( '.js' ) || filename.endsWith( '.test.js' ) ) {
			continue;
		}
		for ( const module of violations(
			filename,
			fs.readFileSync( path.join( __dirname, filename ), 'utf8' )
		) ) {
			failures.push( `${ filename }: ${ module }` );
		}
	}
	expect( failures ).toEqual( [] );
} );

test( 'guard recognizes static, dynamic and CommonJS booking imports', () => {
	for ( const source of [
		"import { BookingTab } from './booking-tab';",
		"import './booking-settings';",
		"import('./booking-console');",
		"require('./booking-embed');",
	] ) {
		expect( violations( 'view.js', source ) ).toHaveLength( 1 );
	}
} );
