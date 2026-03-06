/**
 * Post-build script for @extrachill/tokens
 *
 * After tsc compiles, this script:
 * 1. Copies the ESM build into the right export path
 * 2. Creates a package.json shim for ESM consumers
 */

import { readFileSync, writeFileSync, existsSync, mkdirSync, readdirSync, copyFileSync } from 'fs';
import { join, dirname } from 'path';

const ROOT = dirname( new URL( import.meta.url ).pathname );
const DIST = join( ROOT, '..', 'dist' );
const ESM = join( DIST, 'esm' );

// If ESM build exists, rename the .js files to .mjs
if ( existsSync( ESM ) ) {
	const files = readdirSync( ESM );
	for ( const file of files ) {
		if ( file.endsWith( '.js' ) ) {
			const src = join( ESM, file );
			const dest = join( DIST, file.replace( /\.js$/, '.mjs' ) );
			copyFileSync( src, dest );
		}
	}
	console.log( '✅ ESM .mjs files copied to dist/' );
}

// Verify key files exist
const required = [
	'dist/index.js',
	'dist/index.d.ts',
	'dist/index.mjs',
	'css/tokens.css',
	'css/tokens-dark.css',
	'css/tokens-all.css',
	'css/root.css',
	'tokens.json',
];

const root = join( ROOT, '..' );
let allFound = true;

for ( const file of required ) {
	const path = join( root, file );
	if ( ! existsSync( path ) ) {
		console.error( `❌ Missing: ${ file }` );
		allFound = false;
	}
}

if ( allFound ) {
	console.log( '✅ All required build artifacts present' );
} else {
	process.exit( 1 );
}
