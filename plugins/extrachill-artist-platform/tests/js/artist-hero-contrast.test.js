const fs = require( 'fs' );
const path = require( 'path' );
const postcss = require( 'postcss' );

const css = postcss.parse(
	fs.readFileSync(
		path.resolve( __dirname, '../../assets/css/artist-profile.css' ),
		'utf8'
	)
);

function ruleFor( selector ) {
	let match;
	css.walkRules( ( rule ) => {
		if ( rule.selectors.includes( selector ) ) {
			match = rule;
		}
	} );
	return match;
}

const imageHero = ".artist-hero[style*='background-image']";

test.each( [
	'',
	' .artist-hero-title',
	' .artist-meta-info',
	' .artist-meta-info strong',
	' .artist-meta-separator',
	' .artist-social-links a',
	' .artist-social-links a:hover',
] )( 'photo hero foreground stays light: %s', ( descendant ) => {
	const rule = ruleFor( imageHero + descendant );
	expect( rule ).toBeDefined();
	// Unconditional rules apply equally in light and dark mode.
	expect( rule.parent.type ).toBe( 'root' );
	const color = rule.nodes.find( ( node ) => node.prop === 'color' );
	expect( color.value ).toBe( '#fff' );
	expect( color.important ).toBe( true );
	expect(
		rule.nodes.find( ( node ) => node.prop === 'text-shadow' ).value
	).toBe( '0 1px 3px rgba(0, 0, 0, 0.8)' );
} );

test( 'no-image hero retains theme text and card background', () => {
	const rule = ruleFor( ".artist-hero:not([style*='background-image'])" );
	const declarations = Object.fromEntries(
		rule.nodes
			.filter( ( node ) => node.type === 'decl' )
			.map( ( node ) => [ node.prop, node.value ] )
	);
	expect( declarations.color ).toBe( 'var(--header-text)' );
	expect( declarations[ 'background-color' ] ).toBe(
		'var(--card-background)'
	);
} );
