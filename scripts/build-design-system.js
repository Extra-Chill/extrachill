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
// Turn a token slug into a human label as a fallback when no description exists
// (e.g. --font-family-heading -> "Font family heading").
function humanize( token ) {
	return token
		.replace( /^--/, '' )
		.replace( /-/g, ' ' )
		.replace( /^\w/, ( c ) => c.toUpperCase() );
}

function colorSwatch( { token, desc } ) {
	const label = desc || humanize( token );
	// The chip holds a transparent <input type="color"> overlay so clicking it
	// opens the native picker; editing updates the page live (see inline JS).
	return `        <div class="swatch">
          <label class="swatch__chip" style="background:var(${ token });">
            <input type="color" class="swatch__picker" data-token="${ esc( token ) }" aria-label="Edit ${ esc( label ) }" />
          </label>
          <span class="swatch__meta">
            <code class="swatch__token">${ esc( token ) }</code>
            <span class="swatch__label">${ esc( label ) }</span>
            <code class="swatch__value" data-value="${ esc( token ) }">…</code>
          </span>
        </div>`;
}

function fontRow( { token, desc, isBrand } ) {
	const sample = isBrand ? BRAND_SAFE_TEXT : 'Extra Chill — the Online Music Scene';
	const label = desc || humanize( token );
	return `        <div class="font" style="font-family:var(${ token });">
          <span class="font__sample">${ esc( sample ) }</span>
          <span class="font__meta">
            <code class="font__token">${ esc( token ) }</code>
            <span class="font__label">${ esc( label ) }</span>
          </span>
        </div>`;
}

function weightRow( { token, desc } ) {
	const label = desc || humanize( token );
	return `        <div class="weight" style="font-weight:var(${ token });">
          <span class="weight__sample">Extra Chill</span>
          <code class="weight__token">${ esc( token ) }</code>
          <span class="weight__label">${ esc( label ) }</span>
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
  .toolbar__status { font-size: var(--font-size-sm); color: var(--success-color); }
  #export[aria-disabled="true"] { opacity: 0.5; }

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
  .swatch__label, .font__label, .weight__label { font-size: var(--font-size-sm); color: var(--muted-text); }
  .swatch__value { font-size: var(--font-size-xs); color: var(--muted-text); }

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
    The Extra Chill color palette and fonts. Click any color swatch to try a
    different shade and watch the whole page update. Use Light, Dark, or Auto to
    preview each mode — your edits are kept separately for light and dark, and
    they survive refreshes. When you have a palette you like, hit Export to send
    it over. Everything stays in your browser; Reset clears the current mode.
  </p>
  <div class="toolbar">
    <span class="toolbar__group" role="group" aria-label="Color scheme">
      <button type="button" class="mode" data-mode="auto" aria-pressed="true">Auto</button>
      <button type="button" class="mode" data-mode="light" aria-pressed="false">Light</button>
      <button type="button" class="mode" data-mode="dark" aria-pressed="false">Dark</button>
    </span>
    <button type="button" id="export">Export palette</button>
    <button type="button" id="reset">Reset colors</button>
    <span class="toolbar__status" data-status aria-live="polite"></span>
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

    // Base light/dark palettes baked from the tokens at build time.
    var LIGHT = ${ JSON.stringify( lightMap ) };
    var DARK = ${ JSON.stringify( darkMap ) };

    var STORAGE_KEY = 'ec-design-system-overrides';
    var MODE_KEY = 'ec-design-system-mode';

    // Persisted user edits, kept SEPARATELY per scheme: { light:{}, dark:{} }.
    // A color tuned for light must not bleed into dark (the base palettes differ).
    var overrides = loadOverrides();
    // Forced scheme: 'auto' | 'light' | 'dark'. Survives refreshes.
    var forcedMode = loadMode();

    function loadOverrides() {
      try {
        var raw = window.localStorage.getItem( STORAGE_KEY );
        var parsed = raw ? JSON.parse( raw ) : null;
        return {
          light: ( parsed && parsed.light ) || {},
          dark: ( parsed && parsed.dark ) || {},
        };
      } catch ( e ) {
        return { light: {}, dark: {} };
      }
    }
    function saveOverrides() {
      try {
        window.localStorage.setItem( STORAGE_KEY, JSON.stringify( overrides ) );
      } catch ( e ) {}
    }
    function loadMode() {
      try {
        var m = window.localStorage.getItem( MODE_KEY );
        return m === 'light' || m === 'dark' || m === 'auto' ? m : 'auto';
      } catch ( e ) {
        return 'auto';
      }
    }
    function saveMode() {
      try { window.localStorage.setItem( MODE_KEY, forcedMode ); } catch ( e ) {}
    }

    // The scheme actually in effect right now. Auto resolves via the OS.
    function effectiveScheme() {
      if ( forcedMode === 'light' || forcedMode === 'dark' ) { return forcedMode; }
      return window.matchMedia &&
        window.matchMedia( '(prefers-color-scheme: dark)' ).matches
        ? 'dark' : 'light';
    }

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

    function shippedValue( scheme, token ) {
      var base = scheme === 'dark' ? DARK : LIGHT;
      return base[ token ];
    }

    function refreshValues() {
      document.querySelectorAll( '[data-value]' ).forEach( function ( el ) {
        el.textContent = readToken( el.getAttribute( 'data-value' ) );
      } );
    }

    // Paint the page for the active scheme: shipped base for the scheme, then
    // the user's saved edits for that same scheme on top.
    function applyScheme() {
      var scheme = effectiveScheme();
      // Clear every scheme-managed token first.
      Object.keys( LIGHT ).concat( Object.keys( DARK ) ).forEach( function ( token ) {
        root.style.removeProperty( token );
      } );
      // When forcing a mode, pin the shipped base values so it overrides the
      // OS media query. In Auto we let root.css's media query supply the base.
      if ( forcedMode === 'light' || forcedMode === 'dark' ) {
        var base = forcedMode === 'dark' ? DARK : LIGHT;
        Object.keys( base ).forEach( function ( token ) {
          root.style.setProperty( token, base[ token ] );
        } );
      }
      // User edits for this scheme win.
      var edits = overrides[ scheme ] || {};
      Object.keys( edits ).forEach( function ( token ) {
        root.style.setProperty( token, edits[ token ] );
      } );
    }

    function seedPickers() {
      document.querySelectorAll( '.swatch__picker' ).forEach( function ( input ) {
        input.value = toHex( readToken( input.getAttribute( 'data-token' ) ) );
      } );
    }

    function initPickers() {
      document.querySelectorAll( '.swatch__picker' ).forEach( function ( input ) {
        var token = input.getAttribute( 'data-token' );
        input.addEventListener( 'input', function () {
          var scheme = effectiveScheme();
          overrides[ scheme ][ token ] = input.value;
          saveOverrides();
          root.style.setProperty( token, input.value );
          refreshValues();
        } );
      } );
    }

    function syncModeButtons() {
      document.querySelectorAll( '.mode' ).forEach( function ( btn ) {
        btn.setAttribute(
          'aria-pressed',
          String( btn.getAttribute( 'data-mode' ) === forcedMode )
        );
      } );
    }

    function render() {
      applyScheme();
      seedPickers();
      refreshValues();
      syncModeButtons();
    }

    var statusEl = document.querySelector( '[data-status]' );
    var statusTimer;
    function status( msg ) {
      if ( ! statusEl ) { return; }
      statusEl.textContent = msg;
      window.clearTimeout( statusTimer );
      statusTimer = window.setTimeout( function () { statusEl.textContent = ''; }, 4000 );
    }

    document.querySelectorAll( '.mode' ).forEach( function ( btn ) {
      btn.addEventListener( 'click', function () {
        forcedMode = btn.getAttribute( 'data-mode' );
        saveMode();
        render();
      } );
    } );

    document.getElementById( 'reset' ).addEventListener( 'click', function () {
      // Reset clears the CURRENT scheme's edits only (light and dark are separate).
      var scheme = effectiveScheme();
      overrides[ scheme ] = {};
      saveOverrides();
      render();
      status( 'Reset ' + scheme + ' colors to the defaults.' );
    } );

    // --- Export: build a readable changed-tokens summary, copy + download. ---
    function buildExport() {
      var lines = [ 'Extra Chill — proposed palette', '' ];
      var any = false;
      [ 'light', 'dark' ].forEach( function ( scheme ) {
        var edits = overrides[ scheme ] || {};
        var tokens = Object.keys( edits );
        if ( ! tokens.length ) { return; }
        any = true;
        lines.push( scheme.toUpperCase() + ' mode' );
        tokens.forEach( function ( token ) {
          var was = shippedValue( scheme, token );
          lines.push(
            '  ' + token + ': ' + edits[ token ] +
            ( was ? '   (was ' + was + ')' : '' )
          );
        } );
        lines.push( '' );
      } );
      if ( ! any ) {
        lines.push( 'No changes yet — tweak some colors first.' );
      }
      return lines.join( '\\n' );
    }

    function copyText( text ) {
      if ( navigator.clipboard && navigator.clipboard.writeText ) {
        return navigator.clipboard.writeText( text );
      }
      return new Promise( function ( resolve, reject ) {
        try {
          var ta = document.createElement( 'textarea' );
          ta.value = text; ta.style.position = 'fixed'; ta.style.left = '-9999px';
          document.body.appendChild( ta ); ta.select();
          document.execCommand( 'copy' ); document.body.removeChild( ta );
          resolve();
        } catch ( e ) { reject( e ); }
      } );
    }

    function download( text ) {
      var blob = new Blob( [ text ], { type: 'text/plain' } );
      var url = URL.createObjectURL( blob );
      var a = document.createElement( 'a' );
      a.href = url;
      a.download = 'extra-chill-palette.txt';
      document.body.appendChild( a );
      a.click();
      document.body.removeChild( a );
      URL.revokeObjectURL( url );
    }

    document.getElementById( 'export' ).addEventListener( 'click', function () {
      var text = buildExport();
      download( text );
      copyText( text ).then(
        function () { status( 'Palette copied to clipboard and downloaded.' ); },
        function () { status( 'Palette downloaded.' ); }
      );
    } );

    // Re-render if the OS scheme flips while in Auto (so the right edits show).
    if ( window.matchMedia ) {
      window.matchMedia( '(prefers-color-scheme: dark)' ).addEventListener(
        'change',
        function () { if ( forcedMode === 'auto' ) { render(); } }
      );
    }

    initPickers();
    render();
  } )();
</script>
</body>
</html>
`;

const outPath = path.join( __dirname, '..', 'design-system.html' );
fs.writeFileSync( outPath, html );
// eslint-disable-next-line no-console -- build-time progress output.
console.log( `Built ${ outPath } (${ html.split( '\n' ).length } lines)` );
