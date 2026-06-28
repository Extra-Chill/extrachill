#!/usr/bin/env node
/**
 * Build design-system.html
 *
 * Generates a self-contained static reference page showing the Extra Chill
 * color palette and font families. The token LIST is regenerated from the
 * tokens package (the same source root.css comes from), so new tokens show
 * up automatically. The page links the theme's root.css live, so the actual
 * VALUES (including the prefers-color-scheme dark overrides) always reflect
 * what's shipped — a tiny inline script fills each swatch's computed value.
 *
 * Colors are editable inline: click a swatch's chip to open a color picker and
 * the whole page updates live (overrides are local-only). A Reset link clears
 * them back to the shipped tokens.
 *
 * Output is a plain .html served directly by the web server at
 * /wp-content/themes/extrachill/design-system.html — no WordPress routing,
 * no PHP, no theme coupling.
 */

/**
 * External dependencies
 */
const fs = require( 'fs' );
const path = require( 'path' );

const tokens = require(
	path.join( __dirname, '..', 'node_modules', '@extrachill', 'tokens', 'tokens.json' )
);

const esc = ( s ) =>
	String( s ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' );

// The brand font (Lobster) is a SUBSET woff2 — for perf it only contains the
// glyphs for the site title "Extra Chill". Any other character falls back to
// sans-serif, so the brand specimen must only ever render this exact string.
const BRAND_SAFE_TEXT = 'Extra Chill';

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

// Light/dark color maps baked from the token source so a visitor can FORCE a
// mode (root.css only exposes dark via @media prefers-color-scheme, with no
// class/attr hook). The toggle applies these via setProperty on :root, which
// re-themes the whole page because every surface consumes the tokens. "Auto"
// removes the overrides and falls back to root.css's media query.
const lightMap = {};
const darkMap = {};
for ( const [ name, def ] of Object.entries( tokens.categories.color ) ) {
	if ( def.light !== undefined ) {
		lightMap[ '--' + name ] = def.light;
	}
	if ( def.dark !== undefined ) {
		darkMap[ '--' + name ] = def.dark;
	}
}

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
	// Brand font is subset-limited; render only the safe string for it.
	isBrand: name === 'font-family-brand',
} ) );

const weightTokens = Object.entries( tokens.categories['font-weight'] ).map(
	( [ name, def ] ) => ( {
		token: '--' + name,
		desc: def.description || '',
	} )
);

// --- HTML builders ----------------------------------------------------------
function colorSwatch( { token, desc } ) {
	// The chip holds a transparent <input type="color"> overlay so clicking it
	// opens the native picker; editing updates the page live (see inline JS).
	return `        <div class="swatch">
          <label class="swatch__chip" style="background:var(${ token });">
            <input type="color" class="swatch__picker" data-token="${ esc( token ) }" aria-label="Edit ${ esc( token ) }" />
          </label>
          <span class="swatch__meta">
            <code class="swatch__token">${ esc( token ) }</code>
            ${ desc ? `<span class="swatch__desc">${ esc( desc ) }</span>` : '' }
            <code class="swatch__value" data-value="${ esc( token ) }">…</code>
          </span>
        </div>`;
}

function fontRow( { token, desc, isBrand } ) {
	const sample = isBrand ? BRAND_SAFE_TEXT : 'Extra Chill — the Online Music Scene';
	return `        <div class="font" style="font-family:var(${ token });">
          <span class="font__sample">${ esc( sample ) }</span>
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
    font-family: var(--font-family-heading);
    font-size: var(--font-size-3xl);
    margin: 0 0 var(--spacing-sm);
  }
  /* Lobster is subset to the site-title glyphs only — apply it to nothing but
     that exact string. */
  .brandmark { font-family: var(--font-family-brand); font-size: var(--font-size-brand); }
  .intro { color: var(--muted-text); margin: 0 0 var(--spacing-lg); max-width: var(--content-width); }
  .toolbar {
    display: flex; flex-wrap: wrap; align-items: center; gap: var(--spacing-sm);
    margin: 0 0 var(--spacing-xl);
  }
  .toolbar button {
    font: inherit;
    color: var(--link-color);
    background: none;
    border: 1px solid var(--border-color);
    border-radius: var(--border-radius-sm);
    padding: var(--spacing-xs) var(--spacing-md);
    cursor: pointer;
  }
  .toolbar__group { display: inline-flex; }
  .toolbar__group .mode { border-radius: 0; margin-left: -1px; }
  .toolbar__group .mode:first-child { border-radius: var(--border-radius-sm) 0 0 var(--border-radius-sm); margin-left: 0; }
  .toolbar__group .mode:last-child { border-radius: 0 var(--border-radius-sm) var(--border-radius-sm) 0; }
  .toolbar .mode[aria-pressed="true"] {
    background: var(--accent);
    color: var(--button-text-color);
    border-color: var(--accent);
  }
  .toolbar__hint { margin-left: var(--spacing-sm); font-size: var(--font-size-sm); color: var(--muted-text); }

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
    position: relative;
    flex-shrink: 0; width: var(--spacing-xl); height: var(--spacing-xl);
    border-radius: var(--border-radius-sm); border: 1px solid var(--border-color);
    cursor: pointer;
    overflow: hidden;
  }
  /* The color input fills the chip but stays invisible — the chip's background
     shows the live token; clicking anywhere on it opens the picker. */
  .swatch__picker {
    position: absolute; inset: 0;
    width: 100%; height: 100%;
    margin: 0; padding: 0; border: 0;
    opacity: 0; cursor: pointer;
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

  <h1><span class="brandmark">${ esc( BRAND_SAFE_TEXT ) }</span> — Colors &amp; Fonts</h1>
  <p class="intro">
    A static reference of the shipped design tokens. Generated from
    <code>@extrachill/tokens</code> and rendered with the live <code>root.css</code>,
    so values reflect exactly what's deployed (including dark mode — toggle your OS theme).
    Click any color swatch to try a different value — changes are local to your browser.
  </p>
  <div class="toolbar">
    <span class="toolbar__group" role="group" aria-label="Color scheme">
      <button type="button" class="mode" data-mode="auto" aria-pressed="true">Auto</button>
      <button type="button" class="mode" data-mode="light" aria-pressed="false">Light</button>
      <button type="button" class="mode" data-mode="dark" aria-pressed="false">Dark</button>
    </span>
    <button type="button" id="reset">Reset colors</button>
    <span class="toolbar__hint">Edits are local-only; the source of truth is <code>@extrachill/tokens</code>.</span>
  </div>

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
  ( function () {
    var root = document.documentElement;

    // Light/dark token maps baked from @extrachill/tokens at build time.
    var LIGHT = ${ JSON.stringify( lightMap ) };
    var DARK = ${ JSON.stringify( darkMap ) };

    // Resolve any CSS color to #rrggbb so <input type="color"> can seed it.
    function toHex( value ) {
      if ( ! value ) { return '#000000'; }
      var v = value.trim();
      if ( /^#[0-9a-fA-F]{6}$/.test( v ) ) { return v.toLowerCase(); }
      if ( /^#[0-9a-fA-F]{3}$/.test( v ) ) {
        return ( '#' + v[1] + v[1] + v[2] + v[2] + v[3] + v[3] ).toLowerCase();
      }
      try {
        var ctx = document.createElement( 'canvas' ).getContext( '2d' );
        ctx.fillStyle = '#000000';
        ctx.fillStyle = v;
        var out = ctx.fillStyle;
        if ( /^#[0-9a-fA-F]{6}$/.test( out ) ) { return out.toLowerCase(); }
        var m = out.match( /rgba?\\((\\d+),\\s*(\\d+),\\s*(\\d+)/ );
        if ( m ) {
          return '#' + [ m[1], m[2], m[3] ].map( function ( n ) {
            var h = parseInt( n, 10 ).toString( 16 );
            return h.length === 1 ? '0' + h : h;
          } ).join( '' );
        }
      } catch ( e ) {}
      return '#000000';
    }

    function readToken( token ) {
      return getComputedStyle( root ).getPropertyValue( token ).trim();
    }

    // Fill each value label with the live computed token value.
    function refreshValues() {
      document.querySelectorAll( '[data-value]' ).forEach( function ( el ) {
        el.textContent = readToken( el.getAttribute( 'data-value' ) );
      } );
    }

    // Seed each color picker from the live computed value.
    function initPickers() {
      document.querySelectorAll( '.swatch__picker' ).forEach( function ( input ) {
        var token = input.getAttribute( 'data-token' );
        input.value = toHex( readToken( token ) );
        input.addEventListener( 'input', function () {
          root.style.setProperty( token, input.value );
          refreshValues();
        } );
      } );
    }

    // Force a color scheme. "auto" clears the forced map and falls back to
    // root.css's prefers-color-scheme media query. light/dark apply the baked
    // token maps to :root, re-theming the whole page. User color edits (set on
    // root.style by the pickers) still win, since they're applied after.
    var forcedMode = 'auto';
    function applyMode( mode ) {
      forcedMode = mode;
      // Clear any previously forced scheme values.
      Object.keys( DARK ).concat( Object.keys( LIGHT ) ).forEach( function ( token ) {
        root.style.removeProperty( token );
      } );
      var map = mode === 'light' ? LIGHT : mode === 'dark' ? DARK : null;
      if ( map ) {
        Object.keys( map ).forEach( function ( token ) {
          root.style.setProperty( token, map[ token ] );
        } );
      }
      document.querySelectorAll( '.mode' ).forEach( function ( btn ) {
        btn.setAttribute( 'aria-pressed', String( btn.getAttribute( 'data-mode' ) === mode ) );
      } );
      // Re-seed pickers + value labels to the now-active scheme.
      initPickers();
      refreshValues();
    }

    document.querySelectorAll( '.mode' ).forEach( function ( btn ) {
      btn.addEventListener( 'click', function () {
        applyMode( btn.getAttribute( 'data-mode' ) );
      } );
    } );

    document.getElementById( 'reset' ).addEventListener( 'click', function () {
      // Clear user color edits, then re-apply the current forced scheme (if any).
      document.querySelectorAll( '.swatch__picker' ).forEach( function ( input ) {
        root.style.removeProperty( input.getAttribute( 'data-token' ) );
      } );
      applyMode( forcedMode );
    } );

    // Load in Auto: follow the OS via root.css's media query.
    refreshValues();
    initPickers();
  } )();
</script>
</body>
</html>
`;

const outPath = path.join( __dirname, '..', 'design-system.html' );
fs.writeFileSync( outPath, html );
// eslint-disable-next-line no-console -- build-time progress output.
console.log( `Built ${ outPath } (${ html.split( '\n' ).length } lines)` );
