/**
 * The committed CSS and theme.json are generated from tokens.json. These
 * tests fail when someone edits tokens.json without regenerating (or edits a
 * generated file by hand), and run the cross-format consistency validator.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync, spawnSync } from 'node:child_process';
import { cpSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
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

test( 'validator rejects incorrect body and mono generic font fallbacks', () => {
	const dir = mkdtempSync( join( tmpdir(), 'ec-tokens-' ) );
	try {
		mkdirSync( join( dir, 'scripts' ) );
		const validator = join( dir, 'scripts', 'validate.mjs' );
		cpSync( join( PROJECT, 'scripts', 'validate.mjs' ), validator );
		cpSync( join( PROJECT, 'css' ), join( dir, 'css' ), { recursive: true } );
		const tokens = JSON.parse( readFileSync( join( PROJECT, 'tokens.json' ), 'utf-8' ) );
		tokens.categories.typography[ 'font-family-body' ].value = "'Helvetica', 'Open Sans', serif";
		tokens.categories.typography[ 'font-family-mono' ].value = "'Helvetica', Arial, sans-serif";
		writeFileSync( join( dir, 'tokens.json' ), JSON.stringify( tokens ) );

		const result = spawnSync( process.execPath, [ validator ], { encoding: 'utf-8' } );
		assert.equal( result.status, 1 );
		assert.match( result.stderr, /"font-family-body" must end in the unquoted generic sans-serif/ );
		assert.match( result.stderr, /"font-family-mono" must end in the unquoted generic monospace/ );
	} finally {
		rmSync( dir, { recursive: true, force: true } );
	}
} );
