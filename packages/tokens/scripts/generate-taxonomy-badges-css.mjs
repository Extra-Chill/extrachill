/**
 * Generate taxonomy-badges.css from tokens.json
 *
 * Reads taxonomy-badge tokens and produces CSS class rules that map
 * `.taxonomy-badge.{taxonomy}-{slug}` selectors to CSS custom properties.
 *
 * Output is a drop-in replacement for the hardcoded badge colors in
 * extrachill-multisite/assets/css/taxonomy-badges.css.
 *
 * Usage:
 *   node scripts/generate-taxonomy-badges-css.mjs                              # prints to stdout
 *   node scripts/generate-taxonomy-badges-css.mjs --out css/taxonomy-badges.css # writes to file
 */

import { readFileSync, writeFileSync } from 'fs';
import { join, dirname } from 'path';

const ROOT = dirname( new URL( import.meta.url ).pathname );
const PROJECT = join( ROOT, '..' );
const tokensJson = JSON.parse( readFileSync( join( PROJECT, 'tokens.json' ), 'utf-8' ) );

const badges = tokensJson.categories[ 'taxonomy-badge' ];
if ( ! badges ) {
	console.error( '❌ No taxonomy-badge category found in tokens.json' );
	process.exit( 1 );
}

// ─── Group badges by taxonomy prefix ────────────────────────────────────────

const groups = {};

for ( const [ key, token ] of Object.entries( badges ) ) {
	// Key format: "festival-bonnaroo", "location-charleston", "category-concerts", etc.
	const dashIndex = key.indexOf( '-' );
	const taxonomy = dashIndex > 0 ? key.slice( 0, dashIndex ) : 'other';

	if ( ! groups[ taxonomy ] ) {
		groups[ taxonomy ] = [];
	}

	groups[ taxonomy ].push( { key, ...token } );
}

// ─── Section labels ─────────────────────────────────────────────────────────

const sectionLabels = {
	festival: 'Festival Badges',
	location: 'Location Badges',
	category: 'Category Badges',
	venue: 'Venue Badges',
	artist: 'Artist Badges',
};

// ─── Build CSS output ───────────────────────────────────────────────────────

const lines = [
	'/*',
	' * Taxonomy Badge Colors',
	' *',
	' * Generated from @extrachill/tokens — DO NOT EDIT MANUALLY.',
	' * Source: tokens.json > categories > taxonomy-badge',
	' *',
	` * ${ Object.keys( badges ).length } badges across ${ Object.keys( groups ).length } taxonomies`,
	' */',
	'',
];

const sectionOrder = [ 'festival', 'location', 'category', 'venue', 'artist' ];

// Render sections in defined order, then any remaining
const renderedSections = new Set();

for ( const section of sectionOrder ) {
	if ( ! groups[ section ] ) continue;
	renderedSections.add( section );
	renderSection( section, groups[ section ] );
}

// Render any groups not in the predefined order
for ( const [ section, entries ] of Object.entries( groups ) ) {
	if ( renderedSections.has( section ) ) continue;
	renderSection( section, entries );
}

function renderSection( section, entries ) {
	const label = sectionLabels[ section ] || `${ section.charAt( 0 ).toUpperCase() + section.slice( 1 ) } Badges`;
	lines.push( `/* === ${ label } === */` );
	lines.push( '' );

	for ( const entry of entries ) {
		const { key } = entry;
		const varBg = `--badge-${ key }-bg`;
		const varText = `--badge-${ key }-text`;

		// Category badges in the existing CSS use a different class pattern:
		// .taxonomy-badge.category-{slug}-badge instead of .taxonomy-badge.category-{slug}
		// We need to support both patterns for backwards compatibility.
		const selectorSuffix = section === 'category' ? `-badge` : '';

		// Special case: venue-default maps to .taxonomy-badge.venue-badge (base class)
		if ( key === 'venue-default' ) {
			lines.push( `.taxonomy-badge.venue-badge {` );
		} else {
			lines.push( `.taxonomy-badge.${ key }${ selectorSuffix } {` );
		}

		lines.push( `    background-color: var(${ varBg });` );
		lines.push( `    color: var(${ varText });` );
		lines.push( `}` );
	}

	lines.push( '' );
}

// Remove trailing blank line
while ( lines.length > 0 && lines[ lines.length - 1 ] === '' ) {
	lines.pop();
}
lines.push( '' ); // Single trailing newline

const output = lines.join( '\n' );

// ─── Write or print ─────────────────────────────────────────────────────────

const outFlag = process.argv.indexOf( '--out' );
if ( outFlag !== -1 && process.argv[ outFlag + 1 ] ) {
	const outPath = join( PROJECT, process.argv[ outFlag + 1 ] );
	writeFileSync( outPath, output, 'utf-8' );
	console.log( `✅ Generated ${ outPath } (${ Object.keys( badges ).length } badges)` );
} else {
	process.stdout.write( output );
}
