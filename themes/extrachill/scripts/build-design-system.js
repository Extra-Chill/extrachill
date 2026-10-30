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
// Colors are organised by PURPOSE so the page reads for a non-developer: what
// is this color actually for? Each entry pairs a token with a plain-English
// "what it's for" blurb. Tokens not listed here (rgb tuples, shadows, derived
// focus/interactive helpers) are intentionally omitted as swatches.
const colorGroups = [
	{
		title: 'Text',
		blurb: 'The colors words are printed in.',
		items: [
			[ '--text-color', 'Main body text.' ],
			[ '--muted-text', 'Quieter text — captions, hints, metadata.' ],
			[ '--link-color', 'Links.' ],
			[ '--link-color-hover', 'Links when you hover over them.' ],
			[ '--button-text-color', 'Text sitting on a colored button.' ],
		],
	},
	{
		title: 'Surfaces',
		blurb: 'Backgrounds and the lines between things.',
		items: [
			[ '--background-color', 'The page background.' ],
			[ '--card-background', 'Cards and raised panels.' ],
			[ '--border-color', 'Borders and divider lines.' ],
		],
	},
	{
		title: 'Brand accents',
		blurb: 'The signature Extra Chill colors used to draw the eye.',
		items: [
			[ '--accent', 'Primary accent — the Extra Chill green.' ],
			[ '--accent-hover', 'Primary accent when hovered.' ],
			[ '--accent-2', 'Secondary accent — slate / light blue.' ],
			[ '--accent-3', 'Tertiary accent — cyan / blue.' ],
		],
	},
	{
		title: 'Status',
		blurb: 'Colors that signal what happened — used in notices and messages.',
		items: [
			[ '--success-color', 'Success — it worked.' ],
			[ '--info-color', 'Info — a neutral heads-up.' ],
			[ '--warning-color', 'Warning — proceed with care.' ],
			[ '--error-color', 'Error — something went wrong.' ],
		],
	},
];



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
	// Lead with the plain-English label; the raw token name + value are the
	// quieter detail underneath.
	return `        <div class="swatch">
          <label class="swatch__chip" style="background:var(${ token });">
            <input type="color" class="swatch__picker" data-token="${ esc( token ) }" aria-label="Edit ${ esc( label ) }" />
          </label>
          <span class="swatch__meta">
            <span class="swatch__label">${ esc( label ) }</span>
            <span class="swatch__detail"><code class="swatch__token">${ esc( token ) }</code> <code class="swatch__value" data-value="${ esc( token ) }">…</code></span>
          </span>
        </div>`;
}

// Render one purpose-grouped block of color swatches with a heading + blurb.
function colorGroup( { title, blurb, items } ) {
	const swatches = items
		.map( ( [ token, desc ] ) => colorSwatch( { token, desc } ) )
		.join( '\n' );
	return `  <section class="group">
    <h3 class="group__title">${ esc( title ) }</h3>
    <p class="group__blurb">${ esc( blurb ) }</p>
    <div class="swatch-grid">
${ swatches }
    </div>
  </section>`;
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
    grid-template-columns: repeat(auto-fill, minmax(min(100%, 220px), 1fr));
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
  .swatch__label { font-size: var(--font-size-sm); }
  .swatch__detail { display: flex; gap: var(--spacing-sm); flex-wrap: wrap; }
  .swatch__token, .font__token, .weight__token { color: var(--link-color); word-break: break-all; }
  .swatch__value { font-size: var(--font-size-xs); color: var(--muted-text); }
  .font__label, .weight__label { font-size: var(--font-size-sm); color: var(--muted-text); }

  .section-note { color: var(--muted-text); margin: 0 0 var(--spacing-lg); max-width: var(--content-width); }
  .group { margin-bottom: var(--spacing-xl); }
  .group__title {
    font-family: var(--font-family-heading);
    font-size: var(--font-size-lg);
    margin: 0 0 var(--spacing-xs);
  }
  .group__blurb { color: var(--muted-text); font-size: var(--font-size-sm); margin: 0 0 var(--spacing-md); }

  /* In-context examples — built entirely from live tokens. */
  .examples { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 300px), 1fr)); gap: var(--spacing-lg); }
  .example {
    background: var(--card-background);
    border: 1px solid var(--border-color);
    border-radius: var(--border-radius-md);
    padding: var(--spacing-md);
  }
  .example__title { font-family: var(--font-family-heading); font-size: var(--font-size-base); margin: 0 0 var(--spacing-sm); }
  .example a { color: var(--link-color); }
  .example a:hover { color: var(--link-color-hover); }
  .example .muted { color: var(--muted-text); }
  .example__inline { color: var(--link-color); }
  .example__row { display: flex; flex-wrap: wrap; gap: var(--spacing-sm); }

  .btn {
    display: inline-block;
    padding: var(--spacing-xs) var(--spacing-md);
    border-radius: var(--border-radius-sm);
    color: var(--button-text-color);
    font-size: var(--font-size-sm);
  }
  .btn--primary { background: var(--accent); }
  .btn--accent2 { background: var(--accent-2); }
  .btn--accent3 { background: var(--accent-3); }
  .btn--danger { background: var(--error-color); }

  .notice {
    border-left: 4px solid var(--border-color);
    background: var(--background-color);
    padding: var(--spacing-sm) var(--spacing-md);
    margin-bottom: var(--spacing-sm);
    border-radius: var(--border-radius-sm);
    font-size: var(--font-size-sm);
  }
  .notice--success { border-left-color: var(--success-color); }
  .notice--info { border-left-color: var(--info-color); }
  .notice--warning { border-left-color: var(--warning-color); }
  .notice--error { border-left-color: var(--error-color); }

  .card {
    background: var(--card-background);
    border: 1px solid var(--border-color);
    border-radius: var(--border-radius-md);
    box-shadow: var(--card-shadow);
    padding: var(--spacing-md);
  }
  .card p { margin: var(--spacing-xs) 0 var(--spacing-md); font-size: var(--font-size-sm); }

  .fonts { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 280px), 1fr)); gap: var(--spacing-md); }
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
    The Extra Chill colors and fonts, with a live preview of how they look in
    real interface pieces. Click any color to try a different shade and watch the
    whole page update. Use Light, Dark, or Auto to preview each mode — your edits
    are kept separately for light and dark and survive refreshes. When you like
    what you see, hit Export to download a snapshot you can open and send over.
    Everything stays in your browser; Reset clears the current mode.
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
  <p class="section-note">Grouped by what they're for. Click a swatch to recolor it everywhere on the page.</p>
${ colorGroups.map( colorGroup ).join( '\n' ) }
${
	badgeColorTokens.length
		? `  <section class="group">
    <h3 class="group__title">Member badges</h3>
    <p class="group__blurb">Colors that mark a person's role across the platform.</p>
    <div class="swatch-grid">
${ badgeColorTokens.map( colorSwatch ).join( '\n' ) }
    </div>
  </section>
`
		: ''
}
  <h2>In context</h2>
  <p class="section-note">The same colors shown in real interface pieces, so you can see how a change actually lands. These update live as you edit.</p>

  <div class="examples">
    <div class="example">
      <h3 class="example__title">Links &amp; text</h3>
      <p>Body text in <code class="example__inline">--text-color</code>, with
        <a href="#in-context">a link</a> and
        <span class="muted">quieter muted text</span> alongside it.</p>
    </div>

    <div class="example">
      <h3 class="example__title">Buttons</h3>
      <p class="example__row">
        <span class="btn btn--primary">Primary</span>
        <span class="btn btn--accent2">Secondary</span>
        <span class="btn btn--accent3">Tertiary</span>
        <span class="btn btn--danger">Delete</span>
      </p>
    </div>

    <div class="example">
      <h3 class="example__title">Notices</h3>
      <div class="notice notice--success">Success — your changes were saved.</div>
      <div class="notice notice--info">Info — here's something to know.</div>
      <div class="notice notice--warning">Warning — double-check this first.</div>
      <div class="notice notice--error">Error — that didn't work.</div>
    </div>

    <div class="example">
      <h3 class="example__title">Card</h3>
      <div class="card">
        <strong>A card / panel</strong>
        <p class="muted">Sits on the card background with a border and the
          card shadow. This is how raised surfaces look.</p>
        <span class="btn btn--primary">Action</span>
      </div>
    </div>
  </div>

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

    // --- Export: a self-contained HTML snapshot of the page as edited. ---
    // We clone the current document, bake the effective scheme + the user's
    // edits in as an inline :root{} block (so it renders identically with no
    // toggle / OS dependency), strip the interactive chrome, and download it.
    // Open the file and you SEE exactly the proposed design.
    // Every CSS custom property currently in effect on :root — colors AND the
    // spacing/font/radius tokens that come from root.css. Freezing the full set
    // makes the downloaded file render standalone, with no root.css dependency.
    function snapshotAllTokens() {
      var out = {};
      // Pull declared properties off the loaded stylesheets' :root rules.
      try {
        for ( var s = 0; s < document.styleSheets.length; s++ ) {
          var rules;
          try { rules = document.styleSheets[ s ].cssRules; } catch ( e ) { continue; }
          if ( ! rules ) { continue; }
          for ( var r = 0; r < rules.length; r++ ) {
            var rule = rules[ r ];
            if ( ! rule.style || ! rule.selectorText ) { continue; }
            if ( rule.selectorText.indexOf( ':root' ) === -1 ) { continue; }
            for ( var p = 0; p < rule.style.length; p++ ) {
              var prop = rule.style[ p ];
              if ( prop.indexOf( '--' ) === 0 ) { out[ prop ] = true; }
            }
          }
        }
      } catch ( e ) {}
      // Always include the colors we manage (covers edits + base).
      Object.keys( LIGHT ).concat( Object.keys( DARK ) ).forEach( function ( t ) {
        out[ t ] = true;
      } );
      // Resolve each to its live computed value (this already includes the
      // active scheme + the user's edits, since they're applied to :root).
      var resolved = {};
      Object.keys( out ).forEach( function ( t ) {
        var v = readToken( t );
        if ( v ) { resolved[ t ] = v; }
      } );
      return resolved;
    }

    function buildSnapshot() {
      var scheme = effectiveScheme();
      var effective = snapshotAllTokens();

      var clone = document.documentElement.cloneNode( true );

      // Replace the live stylesheet link with a frozen :root holding every
      // token's current value, so the file renders identically standalone.
      var linkEl = clone.querySelector( 'link[rel="stylesheet"]' );
      var inlineRoot = '';
      Object.keys( effective ).forEach( function ( t ) {
        inlineRoot += t + ':' + effective[ t ] + ';';
      } );
      var styleFreeze = document.createElement( 'style' );
      styleFreeze.textContent = ':root{' + inlineRoot + '}';
      if ( linkEl && linkEl.parentNode ) {
        linkEl.parentNode.replaceChild( styleFreeze, linkEl );
      } else {
        clone.querySelector( 'head' ).appendChild( styleFreeze );
      }

      // Strip the interactive chrome so it's a clean static document.
      var toolbar = clone.querySelector( '.toolbar' );
      if ( toolbar ) { toolbar.parentNode.removeChild( toolbar ); }
      clone.querySelectorAll( 'script' ).forEach( function ( s ) {
        s.parentNode.removeChild( s );
      } );
      clone.querySelectorAll( '.swatch__picker' ).forEach( function ( inp ) {
        inp.parentNode.removeChild( inp );
      } );

      var note = clone.querySelector( '.intro' );
      if ( note ) {
        note.textContent =
          'Proposed Extra Chill palette (' + scheme + ' mode). A static snapshot — ' +
          'every color is exactly as it was set when exported.';
      }

      return '<!DOCTYPE html>\\n' + clone.outerHTML;
    }

    function download( text, filename, type ) {
      var blob = new Blob( [ text ], { type: type } );
      var url = URL.createObjectURL( blob );
      var a = document.createElement( 'a' );
      a.href = url;
      a.download = filename;
      document.body.appendChild( a );
      a.click();
      document.body.removeChild( a );
      URL.revokeObjectURL( url );
    }

    document.getElementById( 'export' ).addEventListener( 'click', function () {
      var scheme = effectiveScheme();
      download(
        buildSnapshot(),
        'extra-chill-design-' + scheme + '.html',
        'text/html'
      );
      status( 'Exported a visual ' + scheme + '-mode snapshot you can open and share.' );
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
