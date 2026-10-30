/**
 * Generate theme.json from tokens.json
 *
 * Reads the canonical tokens.json and produces a WordPress theme.json
 * fragment so core Gutenberg blocks (Cover, Columns, Group, Buttons, etc.)
 * inherit the Extra Chill brand palette, spacing, typography, and layout.
 *
 * theme.json works for CLASSIC themes since WP 5.9 — Extra Chill is not a
 * block/FSE theme, but the settings here still flow into core block UI
 * (the color picker palette, spacing presets, font-size presets, and the
 * default content/wide widths used by alignment controls).
 *
 * This is the SIBLING of generate-root-css.mjs: ONE source (tokens.json),
 * TWO outputs (css/root.css + css/theme.json) so they can never drift.
 *
 * Settings are deliberately CONSERVATIVE — we add presets without disabling
 * custom colors, custom spacing, or custom font sizes, so the generated
 * theme.json augments rather than fights the classic theme.
 *
 * Usage:
 *   node scripts/generate-theme-json.mjs                     # prints to stdout
 *   node scripts/generate-theme-json.mjs --out css/theme.json # writes to file
 */

import { readFileSync, writeFileSync } from 'fs';
import { join, dirname } from 'path';

const ROOT = dirname( new URL( import.meta.url ).pathname );
const PROJECT = join( ROOT, '..' );
const tokensJson = JSON.parse( readFileSync( join( PROJECT, 'tokens.json' ), 'utf-8' ) );

// ─── Helpers ────────────────────────────────────────────────────────────────

/**
 * True if a value is a concrete CSS color (hex / rgb() / rgba() / hsl()).
 * Excludes shadow tokens (e.g. "0 2px 6px rgba(...)") and bare rgb-component
 * lists (e.g. "54, 69, 79") that live in the color category but are not
 * paintable colors a palette swatch can use.
 */
function isCssColor( value ) {
	if ( typeof value !== 'string' ) return false;
	const v = value.trim();
	if ( /^#([0-9a-f]{3,8})$/i.test( v ) ) return true;
	// rgb()/rgba()/hsl()/hsla() — must START with the function, not contain it
	// (a shadow like "0 0 0 3px rgba(...)" starts with a number, so is rejected).
	if ( /^(rgb|rgba|hsl|hsla)\(/i.test( v ) ) return true;
	return false;
}

/**
 * Derive a human-readable name from a token's description, falling back to a
 * Title-Cased version of the slug.
 */
function humanName( slug, token ) {
	if ( token && token.description ) {
		return token.description;
	}
	return slug
		.split( '-' )
		.map( ( part ) => part.charAt( 0 ).toUpperCase() + part.slice( 1 ) )
		.join( ' ' );
}

// ─── settings.color.palette ──────────────────────────────────────────────────
// Use LIGHT-mode resolved values. theme.json palette swatches need concrete
// colors, so for tokens carrying a cssRef alias we emit the resolved light
// value (matching what root.css resolves to in light mode — no drift).

const palette = [];
for ( const [ slug, token ] of Object.entries( tokensJson.categories.color ) ) {
	const value = token.light;
	if ( ! isCssColor( value ) ) {
		// Skip shadows, rgb-component lists, and any non-paintable color token.
		continue;
	}
	palette.push( {
		slug,
		color: value,
		name: humanName( slug, token ),
	} );
}

// ─── settings.spacing.spacingSizes ───────────────────────────────────────────

const spacingSizes = [];
for ( const [ slug, token ] of Object.entries( tokensJson.categories.spacing ) ) {
	spacingSizes.push( {
		slug,
		size: token.value,
		name: humanName( slug, token ),
	} );
}

// ─── settings.typography.fontSizes ───────────────────────────────────────────

const fontSizes = [];
for ( const [ slug, token ] of Object.entries( tokensJson.categories[ 'font-size' ] ) ) {
	fontSizes.push( {
		slug,
		size: token.value,
		name: humanName( slug, token ),
	} );
}

// ─── settings.typography.fontFamilies ────────────────────────────────────────

const fontFamilies = [];
for ( const [ slug, token ] of Object.entries( tokensJson.categories.typography ) ) {
	fontFamilies.push( {
		slug,
		fontFamily: token.value,
		name: humanName( slug, token ),
	} );
}

// ─── settings.layout ─────────────────────────────────────────────────────────
// contentSize = the default block width (single-column reading measure).
// wideSize    = the "wide" alignment width.
// Mapping: content-width (800px) → contentSize, container-wide (1600px) →
// wideSize. content-width is the single-column content measure and is the
// closest analogue to WP's contentSize; container-wide is the explicit wide
// layout token and maps cleanly to wideSize. (container-width / sidebar-width
// / form-width have no theme.json layout analogue and are intentionally
// omitted from settings.layout — they remain available as CSS vars.)

const layoutTokens = tokensJson.categories.layout || {};
const layout = {};
if ( layoutTokens[ 'content-width' ] ) {
	layout.contentSize = layoutTokens[ 'content-width' ].value;
}
if ( layoutTokens[ 'container-wide' ] ) {
	layout.wideSize = layoutTokens[ 'container-wide' ].value;
}

// ─── Assemble theme.json ─────────────────────────────────────────────────────
// Conservative settings: we ADD presets and enable custom* so the picker keeps
// arbitrary values too. We do not flip any "disable" switch.

const themeJson = {
	$schema: 'https://schemas.wp.org/trunk/theme.json',
	version: 3,
	settings: {
		color: {
			custom: true,
			customGradient: true,
			palette,
		},
		spacing: {
			custom: true,
			spacingSizes,
		},
		typography: {
			customFontSize: true,
			fontSizes,
			fontFamilies,
		},
		layout,
	},
};

const output = JSON.stringify( themeJson, null, '\t' ) + '\n';

// ─── Write or print ─────────────────────────────────────────────────────────

const outFlag = process.argv.indexOf( '--out' );
if ( outFlag !== -1 && process.argv[ outFlag + 1 ] ) {
	const outPath = join( PROJECT, process.argv[ outFlag + 1 ] );
	writeFileSync( outPath, output, 'utf-8' );
	console.log( `✅ Generated ${ outPath }` );
} else {
	process.stdout.write( output );
}
