import fs from 'node:fs';
import path from 'node:path';

const readTab = ( relativePath ) =>
	fs.readFileSync( path.resolve( __dirname, relativePath ), 'utf8' );

describe( 'Studio usability regression contracts', () => {
	it( 'names the draft picker and tears down Blocks Everywhere', () => {
		const source = readTab( 'compose/index.ts' );
		expect( source ).toContain( "'aria-label': __( 'Choose draft'" );
		expect( source ).toContain(
			'window.blocksEverywhere.unmount( textarea )'
		);
	} );

	it( 'activates the Transcribe dropzone from Enter and Space', () => {
		const source = readTab( 'transcribe/index.ts' );
		expect( source ).toContain(
			"event.key === 'Enter' || event.key === ' '"
		);
		expect( source ).toContain( 'onKeyDown: onDropZoneKeyDown' );
	} );

	it( 'owns publish drafts by platform above capability views', () => {
		const source = readTab( 'socials/index.ts' );
		expect( source ).toContain( 'publishDrafts[ selectedPlatform.slug ]' );
		expect( source ).toContain( '[ selectedPlatform.slug ]: draft' );
	} );

	it( 'invalidates all giveaway result state when its target changes', () => {
		const source = readTab( 'giveaway/index.ts' );
		for ( const reset of [
			'setAllComments( [] )',
			'setStats( null )',
			'setWinners( [] )',
			'setExcludedWinners( [] )',
			"setIsAnnouncing( '' )",
		] ) {
			expect( source ).toContain( reset );
		}
		expect( source ).toContain( 'resetPreview();' );
	} );
} );
