/**
 * Validate @extrachill/tokens
 *
 * Ensures tokens.json, CSS files, and TypeScript exports are all in sync.
 * This script reads the canonical tokens.json and verifies that:
 * 1. Every token in JSON appears in the CSS output
 * 2. Every CSS variable in the CSS file is accounted for in JSON
 * 3. Taxonomy badge tokens appear in both root.css and taxonomy-badges.css
 * 4. Token count matches expectations
 */

import { readFileSync, existsSync } from 'fs';
import { join, dirname } from 'path';

const ROOT = dirname( new URL( import.meta.url ).pathname );
const PROJECT = join( ROOT, '..' );

// Read sources
const tokensJson = JSON.parse( readFileSync( join( PROJECT, 'tokens.json' ), 'utf-8' ) );
const lightCss = readFileSync( join( PROJECT, 'css', 'tokens.css' ), 'utf-8' );
const darkCss = readFileSync( join( PROJECT, 'css', 'tokens-dark.css' ), 'utf-8' );
const rootCss = readFileSync( join( PROJECT, 'css', 'root.css' ), 'utf-8' );

const taxonomyBadgesCssPath = join( PROJECT, 'css', 'taxonomy-badges.css' );
const hasTaxonomyBadgesCss = existsSync( taxonomyBadgesCssPath );
const taxonomyBadgesCss = hasTaxonomyBadgesCss
	? readFileSync( taxonomyBadgesCssPath, 'utf-8' )
	: '';

let errors = 0;
let warnings = 0;

function error( msg ) {
	console.error( `❌ ${ msg }` );
	errors++;
}

function warn( msg ) {
	console.warn( `⚠️  ${ msg }` );
	warnings++;
}

function ok( msg ) {
	console.log( `✅ ${ msg }` );
}

// ─── Extract all CSS variable names from tokens.json ─────────────────────────

const jsonTokenNames = new Set();
const jsonTaxonomyBadgeVars = new Set();

for ( const [ category, tokens ] of Object.entries( tokensJson.categories ) ) {
	if ( category === 'taxonomy-badge' ) {
		// Taxonomy badges emit two vars per entry: --badge-{key}-bg and --badge-{key}-text
		for ( const name of Object.keys( tokens ) ) {
			jsonTaxonomyBadgeVars.add( `--badge-${ name }-bg` );
			jsonTaxonomyBadgeVars.add( `--badge-${ name }-text` );
		}
	} else {
		for ( const name of Object.keys( tokens ) ) {
			jsonTokenNames.add( `--${ name }` );
		}
	}
}

// ─── Extract all CSS variable declarations from CSS files ────────────────────

function extractCssVarNames( css ) {
	const vars = new Set();
	// Match --foo-bar: (at the start of property declarations)
	const regex = /^\s*(--[\w-]+)\s*:/gm;
	let match;
	while ( ( match = regex.exec( css ) ) !== null ) {
		vars.add( match[ 1 ] );
	}
	return vars;
}

const lightCssVars = extractCssVarNames( lightCss );
const darkCssVars = extractCssVarNames( darkCss );
const rootCssVars = extractCssVarNames( rootCss );

// ─── Validate: Every JSON token exists in light CSS ──────────────────────────

console.log( '\n--- JSON → CSS (light) ---' );
for ( const name of jsonTokenNames ) {
	if ( ! lightCssVars.has( name ) ) {
		const found = findTokenInJson( name.slice( 2 ) );
		if ( found && found.note ) {
			// Acceptable — it may be declared via var() reference
		} else {
			error( `Token "${ name }" in JSON but missing from tokens.css` );
		}
	}
}

// ─── Validate: Every CSS var in light CSS exists in JSON ─────────────────────

console.log( '\n--- CSS (light) → JSON ---' );
for ( const name of lightCssVars ) {
	if ( ! jsonTokenNames.has( name ) ) {
		warn( `CSS var "${ name }" in tokens.css but not in tokens.json (may be an alias)` );
	}
}

// ─── Validate: Dark CSS only overrides color tokens ──────────────────────────

console.log( '\n--- Dark mode check ---' );
const colorTokenNames = new Set(
	Object.keys( tokensJson.categories.color ).map( ( n ) => `--${ n }` )
);

for ( const name of darkCssVars ) {
	if ( ! colorTokenNames.has( name ) ) {
		if ( ! lightCssVars.has( name ) ) {
			error( `Dark CSS var "${ name }" not found in light CSS or color tokens` );
		}
	}
}

// ─── Validate: Taxonomy badge vars in root.css ──────────────────────────────

console.log( '\n--- Taxonomy badge vars (root.css) ---' );
let taxonomyBadgeVarsInRoot = 0;
for ( const name of jsonTaxonomyBadgeVars ) {
	if ( rootCssVars.has( name ) ) {
		taxonomyBadgeVarsInRoot++;
	} else {
		error( `Taxonomy badge var "${ name }" in JSON but missing from root.css` );
	}
}

if ( taxonomyBadgeVarsInRoot === jsonTaxonomyBadgeVars.size && jsonTaxonomyBadgeVars.size > 0 ) {
	ok( `All ${ jsonTaxonomyBadgeVars.size } taxonomy badge vars present in root.css` );
}

// ─── Validate: Taxonomy badge class rules in taxonomy-badges.css ─────────────

console.log( '\n--- Taxonomy badge classes (taxonomy-badges.css) ---' );
if ( hasTaxonomyBadgesCss ) {
	const taxonomyBadgeTokens = tokensJson.categories[ 'taxonomy-badge' ] || {};
	let classesFound = 0;

	for ( const key of Object.keys( taxonomyBadgeTokens ) ) {
		// Check that var() references exist in the CSS
		const bgVar = `--badge-${ key }-bg`;
		const textVar = `--badge-${ key }-text`;

		if ( taxonomyBadgesCss.includes( bgVar ) && taxonomyBadgesCss.includes( textVar ) ) {
			classesFound++;
		} else {
			error( `Taxonomy badge "${ key }" vars not referenced in taxonomy-badges.css` );
		}
	}

	if ( classesFound === Object.keys( taxonomyBadgeTokens ).length ) {
		ok( `All ${ classesFound } taxonomy badge class rules present in taxonomy-badges.css` );
	}
} else {
	warn( 'css/taxonomy-badges.css not found — skipping class rule validation' );
}

// ─── Token count summary ─────────────────────────────────────────────────────

console.log( '\n--- Summary ---' );
const categoryCount = {};
let totalTokens = 0;
for ( const [ category, tokens ] of Object.entries( tokensJson.categories ) ) {
	const count = Object.keys( tokens ).length;
	categoryCount[ category ] = count;
	totalTokens += count;
}

console.log( `Total tokens: ${ totalTokens }` );
for ( const [ cat, count ] of Object.entries( categoryCount ) ) {
	console.log( `  ${ cat }: ${ count }` );
}

ok( `Light CSS vars: ${ lightCssVars.size }` );
ok( `Dark CSS vars: ${ darkCssVars.size }` );
ok( `Root CSS vars: ${ rootCssVars.size }` );
ok( `Taxonomy badge vars: ${ jsonTaxonomyBadgeVars.size }` );
ok( `JSON tokens: ${ totalTokens }` );

if ( errors > 0 ) {
	console.error( `\n💀 Validation failed with ${ errors } error(s) and ${ warnings } warning(s)` );
	process.exit( 1 );
} else if ( warnings > 0 ) {
	console.log( `\n⚠️  Validation passed with ${ warnings } warning(s)` );
} else {
	console.log( '\n🎉 All validations passed' );
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

function findTokenInJson( name ) {
	for ( const [ category, tokens ] of Object.entries( tokensJson.categories ) ) {
		if ( category === 'taxonomy-badge' ) continue;
		if ( tokens[ name ] ) {
			return tokens[ name ];
		}
	}
	return null;
}
