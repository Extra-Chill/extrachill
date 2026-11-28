/* global describe, it, expect */

/**
 * Internal dependencies
 */
import { getStudioTabs } from '../../app/tabs';
import { buildFontJob, createFontDownload, fontJobError } from './font-builder';

describe( 'Studio font builder', () => {
	it( 'submits font-builder with the uploaded path and original glyph map', () => {
		const input = {
			uploadPath: '/var/lib/sweatpants/uploads/photo.webp',
			fontName: 'Sticker Sheet',
			glyphMap: 'A  a\tX X.alt\nampersand exclam question period comma\n',
		};
		const job = buildFontJob( input );
		expect( job ).toEqual( {
			module_id: 'font-builder',
			inputs: {
				image_path: input.uploadPath,
				font_name: input.fontName,
				glyph_map: input.glyphMap,
				output_dir: expect.stringMatching(
					/^\/var\/lib\/sweatpants\/output\/[\w-]+$/
				),
			},
		} );
		expect( buildFontJob( input ).inputs.output_dir ).not.toBe(
			job.inputs.output_dir
		);
	} );

	it.each( [ 'otf', 'ttf' ] )(
		'decodes the %s download into a named font Blob',
		async ( format ) => {
			const { blob, filename } = createFontDownload(
				'AE//gA==',
				'Sticker Sheet',
				format
			);
			expect( filename ).toBe( `Sticker Sheet-Regular.${ format }` );
			expect( blob.type ).toBe( `font/${ format }` );
			const buffer = await new Promise( ( resolve, reject ) => {
				const reader = new window.FileReader();
				reader.onload = () => resolve( reader.result );
				reader.onerror = () => reject( reader.error );
				reader.readAsArrayBuffer( blob );
			} );
			expect( Array.from( new Uint8Array( buffer ) ) ).toEqual( [
				0, 79, 255, 128,
			] );
		}
	);

	it( 'preserves the detected and expected row counts in a job failure', () => {
		const detail =
			'glyph_map mismatch: detected count per row [4, 5]; expected [5, 4]';
		expect( fontJobError( `ValueError: ${ detail }` ) ).toBe( detail );
		expect( fontJobError( detail ) ).toBe( detail );
		expect( fontJobError( null ) ).toBe(
			'The font build did not complete.'
		);
	} );

	it( 'shows the Fonts tab only when the user may build fonts', () => {
		expect(
			getStudioTabs( { canReviewSubmissions: true } ).map(
				( tab ) => tab.id
			)
		).not.toContain( 'fonts' );
		expect(
			getStudioTabs( { canBuildFonts: true } ).map( ( tab ) => tab.id )
		).toContain( 'fonts' );
	} );
} );
