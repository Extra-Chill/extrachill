/**
 * Generate root.css from tokens.json
 *
 * Reads the canonical tokens.json and produces a CSS file suitable for
 * drop-in use as the Extra Chill theme's root.css. The generated file
 * includes :root {} for light mode and @media (prefers-color-scheme: dark)
 * for dark mode overrides.
 *
 * Tokens with a `cssRef` field emit that reference (e.g. `var(--text-color)`)
 * instead of the resolved value, preserving runtime cascade behavior.
 *
 * Usage:
 *   node scripts/generate-root-css.mjs                    # prints to stdout
 *   node scripts/generate-root-css.mjs --out css/root.css  # writes to file
 */

import { readFileSync, writeFileSync } from 'fs';
import { join, dirname } from 'path';

const ROOT = dirname( new URL( import.meta.url ).pathname );
const PROJECT = join( ROOT, '..' );
const tokensJson = JSON.parse( readFileSync( join( PROJECT, 'tokens.json' ), 'utf-8' ) );

// ─── Category display config ────────────────────────────────────────────────
// Maps JSON category keys to human-readable CSS comment headers.
// Order here determines output order.

const categoryConfig = [
	{ key: 'color', label: 'Colors', type: 'color' },
	{ key: 'typography', label: 'Font Families', type: 'static' },
	{ key: 'font-size', label: 'Font Sizes', type: 'static' },
	{ key: 'font-weight', label: 'Font Weights', type: 'static' },
	{ key: 'border-radius', label: 'Border Radius', type: 'static' },
	{ key: 'layout', label: 'Container Widths', type: 'static' },
	{ key: 'line-height', label: 'Line Heights', type: 'static' },
	{ key: 'spacing', label: 'Spacing Scale', type: 'static' },
	{ key: 'motion', label: 'Motion / Transitions', type: 'static' },
	{ key: 'badge', label: 'Badge Colors', type: 'static' },
	{ key: 'taxonomy-badge', label: 'Taxonomy Badge Colors', type: 'taxonomy-badge' },
];

// ─── Build :root block ──────────────────────────────────────────────────────

const lightLines = [];
const darkLines = [];

for ( const { key, label, type } of categoryConfig ) {
	const tokens = tokensJson.categories[ key ];
	if ( ! tokens ) continue;

	// Light mode
	lightLines.push( `    /* ${ label } */` );

	for ( const [ name, token ] of Object.entries( tokens ) ) {
		if ( type === 'taxonomy-badge' ) {
			// Taxonomy badge tokens emit two vars: --badge-{key}-bg and --badge-{key}-text
			lightLines.push( `    --badge-${ name }-bg: ${ token.bg };` );
			lightLines.push( `    --badge-${ name }-text: ${ token.text };` );
		} else if ( type === 'color' ) {
			const varName = `--${ name }`;
			// Color tokens have light/dark variants
			const lightValue = token.cssRef || token.light;
			lightLines.push( `    ${ varName }: ${ lightValue };` );

			// Dark mode: only emit if the dark value differs from light
			// (or if it has a cssRef, emit that in both)
			if ( token.cssRef ) {
				darkLines.push( `        ${ varName }: ${ token.cssRef };` );
			} else if ( token.dark !== token.light ) {
				darkLines.push( `        ${ varName }: ${ token.dark };` );
			}
		} else {
			const varName = `--${ name }`;
			// Static tokens — only in :root, no dark override
			lightLines.push( `    ${ varName }: ${ token.value };` );
		}
	}

	// Add blank line between sections (light)
	lightLines.push( '' );
}

// Remove trailing blank line
if ( lightLines[ lightLines.length - 1 ] === '' ) {
	lightLines.pop();
}

// ─── Assemble output ────────────────────────────────────────────────────────

const output = [
	':root {',
	...lightLines,
	'}',
	'',
	'@media (prefers-color-scheme: dark) {',
	'    :root {',
	...darkLines,
	'    }',
	'}',
	'',
].join( '\n' );

// ─── Write or print ─────────────────────────────────────────────────────────

const outFlag = process.argv.indexOf( '--out' );
if ( outFlag !== -1 && process.argv[ outFlag + 1 ] ) {
	const outPath = join( PROJECT, process.argv[ outFlag + 1 ] );
	writeFileSync( outPath, output, 'utf-8' );
	console.log( `✅ Generated ${ outPath }` );
} else {
	process.stdout.write( output );
}
