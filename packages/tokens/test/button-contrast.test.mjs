import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

function declarations( css ) {
	return Object.fromEntries( Array.from(
		css.matchAll( /([\w-]+)\s*:\s*([^;{}]+);/g ),
		( [ , name, value ] ) => [ name, value.trim() ]
	) );
}

function luminance( hex ) {
	assert.match( hex, /^#(?:[\da-f]{3}|[\da-f]{6})$/i );
	const digits = hex.slice( 1 );
	const rgb = digits.length === 3 ? digits.replace( /./g, '$&$&' ) : digits;
	const linear = rgb.match( /../g ).map( ( channel ) => {
		const srgb = parseInt( channel, 16 ) / 255;
		return srgb <= 0.04045 ? srgb / 12.92 : ( ( srgb + 0.055 ) / 1.055 ) ** 2.4;
	} );
	return 0.2126 * linear[ 0 ] + 0.7152 * linear[ 1 ] + 0.0722 * linear[ 2 ];
}

function contrast( a, b ) {
	const [ first, second ] = [ luminance( a ), luminance( b ) ];
	return ( Math.max( first, second ) + 0.05 ) / ( Math.min( first, second ) + 0.05 );
}

const read = ( path ) => readFileSync( new URL( path, import.meta.url ), 'utf-8' );
const root = read( '../css/root.css' );
const [ rootLight, rootDark ] = root.split( '@media (prefers-color-scheme: dark)' );
const stylesheet = read( '../../../themes/extrachill/style.css' ).replace( /\/\*[\s\S]*?\*\//g, '' );
const themeRules = Array.from( stylesheet.matchAll( /([^{}]+)\{([^{}]*)\}/g ), ( [ , selectors, body ] ) => ( {
	selectors: selectors.split( ',' ).map( ( selector ) => selector.trim() ),
	props: declarations( body ),
} ) );

// Read the token references from the theme's flat variant rules, so a change
// to which tokens a button uses is checked as well as changes to token values.
function buttonPair( selector ) {
	const rules = themeRules.filter( ( rule ) => rule.selectors.includes( selector ) );
	const props = Object.assign( {}, ...rules.map( ( rule ) => rule.props ) );
	return [ 'color', 'background-color' ].map( ( property ) => {
		const token = props[ property ]?.match( /^var\((--[\w-]+)\)$/ )?.[ 1 ];
		assert.ok( token, `${ selector } must set ${ property } to a color token` );
		return token;
	} );
}

for ( const [ artifact, light, dark ] of [
	[ 'root.css', rootLight, rootDark ],
	[ 'tokens.css + tokens-dark.css', read( '../css/tokens.css' ), read( '../css/tokens-dark.css' ) ],
] ) {
	const base = declarations( light );
	for ( const [ scheme, values ] of [ [ 'light', base ], [ 'dark', { ...base, ...declarations( dark ) } ] ] ) {
		test( `${ artifact } button colors meet WCAG AA for normal text in ${ scheme } mode`, () => {
			for ( const variant of [ '1', '2', '3', 'danger', 'success' ] ) {
				for ( const element of [ '', 'a', 'input', 'button' ] ) {
					const selector = `${ element }.button-${ variant }`;
					const hover = variant === 'success' ? ':not(:disabled):hover' : ':hover';
					for ( const state of [ selector, selector + hover ] ) {
						const [ foreground, background ] = buttonPair( state );
						assert.ok( values[ foreground ] && values[ background ], `${ state } references missing tokens: ${ foreground }, ${ background }` );
						const ratio = contrast( values[ foreground ], values[ background ] );
						assert.ok( ratio >= 4.5, `${ state }: ${ foreground } on ${ background } is ${ ratio.toFixed( 2 ) }:1 (need 4.5:1)` );
					}
				}
			}
		} );
	}
}
