/**
 * The committed CSS and theme.json are generated from tokens.json. These
 * tests fail when someone edits tokens.json without regenerating (or edits a
 * generated file by hand), and run the cross-format consistency validator.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const PROJECT = join( dirname( fileURLToPath( import.meta.url ) ), '..' );

function run( script, args = [] ) {
	return execFileSync( process.execPath, [ join( PROJECT, 'scripts', script ), ...args ], {
		cwd: PROJECT,
		encoding: 'utf-8',
	} );
}

function committed( file ) {
	return readFileSync( join( PROJECT, 'css', file ), 'utf-8' );
}

const REGENERATE = 'Regenerate with `npm run generate` and commit the result.';

test( 'css/tokens.css and css/tokens-dark.css match tokens.json', () => {
	const dir = mkdtempSync( join( tmpdir(), 'ec-tokens-' ) );
	try {
		// The generator resolves --out against the package root.
		const out = relative( PROJECT, dir );
		run( 'generate-tokens-css.mjs', [ '--out', join( out, 'tokens.css' ), '--out-dark', join( out, 'tokens-dark.css' ) ] );
		assert.equal( readFileSync( join( dir, 'tokens.css' ), 'utf-8' ), committed( 'tokens.css' ), `css/tokens.css is stale. ${ REGENERATE }` );
		assert.equal( readFileSync( join( dir, 'tokens-dark.css' ), 'utf-8' ), committed( 'tokens-dark.css' ), `css/tokens-dark.css is stale. ${ REGENERATE }` );
	} finally {
		rmSync( dir, { recursive: true, force: true } );
	}
} );

for ( const [ script, file ] of [
	[ 'generate-root-css.mjs', 'root.css' ],
	[ 'generate-taxonomy-badges-css.mjs', 'taxonomy-badges.css' ],
	[ 'generate-theme-json.mjs', 'theme.json' ],
] ) {
	test( `css/${ file } matches tokens.json`, () => {
		assert.equal( run( script ), committed( file ), `css/${ file } is stale. ${ REGENERATE }` );
	} );
}

test( 'tokens.json, CSS and theme.json agree (scripts/validate.mjs)', () => {
	run( 'validate.mjs' );
} );
