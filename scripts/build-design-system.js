#!/usr/bin/env node
/**
 * Build design-system.html
 *
 * Generates a self-contained static reference page showing the Extra Chill
 * color palette and font families. The token LIST is regenerated from
 * @extrachill/tokens (the same source root.css comes from), so new tokens show
 * up automatically. The page links the theme's root.css live, so the actual
 * VALUES (including @media prefers-color-scheme: dark overrides) always reflect
 * what's shipped — a tiny inline script fills each swatch's computed value.
 *
 * Output is a plain .html served directly by the web server at
 * /wp-content/themes/extrachill/design-system.html — no WordPress routing,
 * no PHP, no theme coupling.
 */

const fs = require( 'fs' );
const path = require( 'path' );

const tokens = require(
	path.join( __dirname, '..', 'node_modules', '@extrachill', 'tokens', 'tokens.json' )
);

const esc = ( s ) =>
	String( s ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' );

// --- Colors -----------------------------------------------------------------
// Core palette: the human-facing colors (skip rgb tuples, shadows, derived
// focus/interactive helpers that aren't useful as swatches).
const colorSkip = new Set( [
	'accent-2-rgb',
	'card-shadow',
	'card-hover-shadow',
	'focus-box-shadow',
	'warning-bg',
	'info-bg',
	'notice-bg',
	'notice-border',
	'interactive-hover-bg',
	'interactive-active-bg',
	'interactive-active-border',
	'focus-border-color',
	'post-title-link-color',
	'post-title-link-hover-color',
	'link-hover-color',
] );

const colorTokens = Object.entries( tokens.categories.color )
	.filter( ( [ name ] ) => ! colorSkip.has( name ) )
	.map( ( [ name, def ] ) => ( {
		token: '--' + name,
		desc: def.description || '',
	} ) );

// Identity badge accent colors (artist / team / professional) live in the
// `badge` category. Surface them as swatches too.
const badgeColorTokens = [];
if ( tokens.categories.badge ) {
	for ( const [ name, def ] of Object.entries( tokens.categories.badge ) ) {
		badgeColorTokens.push( { token: '--' + name, desc: def.description || '' } );
	}
}

// --- Fonts ------------------------------------------------------------------
const fontTokens = Object.entries( tokens.categories.typography ).map( ( [ name, def ] ) => ( {
	token: '--' + name,
	value: def.value,
	desc: def.description || '',
} ) );

const weightTokens = Object.entries( tokens.categories['font-weight'] ).map(
	( [ name, def ] ) => ( {
		token: '--' + name,
		desc: def.description || '',
	} )
);

// --- HTML builders ----------------------------------------------------------
function colorSwatch( { token, desc } ) {
	return `        <div class="swatch">
          <span class="swatch__chip" style="background:var(${ token });"></span>
          <span class="swatch__meta">
            <code class="swatch__token">${ esc( token ) }</code>
            ${ desc ? `<span class="swatch__desc">${ esc( desc ) }</span>` : '' }
            <code class="swatch__value" data-value="${ esc( token ) }">…</code>
          </span>
        </div>`;
}

function fontRow( { token, desc } ) {
	return `        <div class="font" style="font-family:var(${ token });">
          <span class="font__sample">Extra Chill — the Online Music Scene</span>
          <span class="font__meta">
            <code class="font__token">${ esc( token ) }</code>
            ${ desc ? `<span class="font__desc">${ esc( desc ) }</span>` : '' }
            <code class="font__value" data-value="${ esc( token ) }">…</code>
          </span>
        </div>`;
}

function weightRow( { token, desc } ) {
	return `        <div class="weight" style="font-weight:var(${ token });">
          <span class="weight__sample">Extra Chill</span>
          <code class="weight__token">${ esc( token ) }</code>
          ${ desc ? `<span class="weight__desc">${ esc( desc ) }</span>` : '' }
        </div>`;
}

const html = `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="robots" content="noindex, nofollow" />
<title>Extra Chill — Colors &amp; Fonts</title>

<!-- Live theme tokens. Values (incl. dark mode) always reflect what's shipped. -->
<link rel="stylesheet" href="assets/css/root.css" />

<!-- Custom fonts shipped in the theme (root.css declares the families, not the src). -->
<style>
  @font-face {
    font-family: "Loft Sans";
    src: url("assets/fonts/WilcoLoftSans-Treble.woff2") format("woff2");
    font-weight: 100 900;
    font-display: swap;
  }
  @font-face {
    font-family: "Lobster";
    src: url("assets/fonts/Lobster2.woff2") format("woff2");
    font-weight: 400;
    font-display: swap;
  }

  body {
    margin: 0;
    padding: var(--spacing-xl) var(--spacing-md);
    background: var(--background-color);
    color: var(--text-color);
    font-family: var(--font-family-body);
    line-height: var(--line-height-base);
  }
  .wrap { max-width: var(--container-width); margin: 0 auto; }
  h1 {
    font-family: var(--font-family-brand);
    font-size: var(--font-size-brand);
    margin: 0 0 var(--spacing-sm);
  }
  .intro { color: var(--muted-text); margin: 0 0 var(--spacing-xl); max-width: var(--content-width); }
  h2 {
    font-family: var(--font-family-heading);
    font-size: var(--font-size-2xl);
    margin: var(--spacing-xl) 0 var(--spacing-md);
    border-bottom: 1px solid var(--border-color);
    padding-bottom: var(--spacing-sm);
  }
  h3 {
    font-family: var(--font-family-heading);
    font-size: var(--font-size-lg);
    margin: var(--spacing-lg) 0 var(--spacing-md);
  }
  code { font-family: var(--font-family-mono); font-size: var(--font-size-sm); }

  .swatch-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: var(--spacing-md);
  }
  .swatch {
    display: flex; align-items: center; gap: var(--spacing-md);
    background: var(--card-background);
    border: 1px solid var(--border-color);
    border-radius: var(--border-radius-md);
    padding: var(--spacing-sm);
  }
  .swatch__chip {
    flex-shrink: 0; width: var(--spacing-xl); height: var(--spacing-xl);
    border-radius: var(--border-radius-sm); border: 1px solid var(--border-color);
  }
  .swatch__meta { display: flex; flex-direction: column; gap: var(--spacing-xs); min-width: 0; }
  .swatch__token, .font__token, .weight__token { color: var(--link-color); word-break: break-all; }
  .swatch__desc, .font__desc, .weight__desc { font-size: var(--font-size-sm); color: var(--muted-text); }
  .swatch__value, .font__value { font-size: var(--font-size-xs); color: var(--muted-text); }

  .fonts { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: var(--spacing-md); }
  .font {
    background: var(--card-background);
    border: 1px solid var(--border-color);
    border-radius: var(--border-radius-md);
    padding: var(--spacing-md);
  }
  .font__sample { display: block; font-size: var(--font-size-xl); margin-bottom: var(--spacing-sm); }
  .font__meta { display: flex; flex-direction: column; gap: var(--spacing-xs); }

  .weights { display: flex; flex-wrap: wrap; gap: var(--spacing-lg); }
  .weight { display: flex; flex-direction: column; gap: var(--spacing-xs); }
  .weight__sample { font-family: var(--font-family-heading); font-size: var(--font-size-xl); }
</style>
</head>
<body>
<div class="wrap">

  <h1>Extra Chill — Colors &amp; Fonts</h1>
  <p class="intro">
    A static reference of the shipped design tokens. Generated from
    <code>@extrachill/tokens</code> and rendered with the live <code>root.css</code>,
    so values reflect exactly what's deployed (including dark mode — toggle your OS theme).
  </p>

  <h2>Colors</h2>
  <div class="swatch-grid">
${ colorTokens.map( colorSwatch ).join( '\n' ) }
  </div>
${
	badgeColorTokens.length
		? `
  <h3>Badge Identity Colors</h3>
  <div class="swatch-grid">
${ badgeColorTokens.map( colorSwatch ).join( '\n' ) }
  </div>
`
		: ''
}
  <h2>Fonts</h2>
  <h3>Families</h3>
  <div class="fonts">
${ fontTokens.map( fontRow ).join( '\n' ) }
  </div>

  <h3>Weights</h3>
  <div class="weights">
${ weightTokens.map( weightRow ).join( '\n' ) }
  </div>

</div>

<script>
  // Fill each swatch/font with its live computed token value.
  ( function () {
    var cs = getComputedStyle( document.documentElement );
    document.querySelectorAll( '[data-value]' ).forEach( function ( el ) {
      el.textContent = cs.getPropertyValue( el.getAttribute( 'data-value' ) ).trim();
    } );
  } )();
</script>
</body>
</html>
`;

const outPath = path.join( __dirname, '..', 'design-system.html' );
fs.writeFileSync( outPath, html );
console.log( `Built ${ outPath } (${ html.split( '\n' ).length } lines)` );
