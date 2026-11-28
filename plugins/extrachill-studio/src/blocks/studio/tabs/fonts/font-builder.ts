/**
 * Font-builder job contract and browser download helpers.
 *
 * @package
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
/**
 * Internal dependencies
 */
import { createOutputDir } from '../../sweatpants/client';

export type FontFormat = 'otf' | 'ttf';

export interface FontResult {
	status: 'complete';
	files: Record< string, string >;
	content: Record< FontFormat | 'preview', string >;
	stats: { glyphs: number; rows: number[] };
}

export const STARTER_GLYPH_MAP = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'
	.split( '' )
	.join( ' ' );
export const FONT_NAME_PATTERN = '[A-Za-z0-9 _\\-]+';

export const buildFontJob = ( input: {
	uploadPath: string;
	fontName: string;
	glyphMap: string;
} ) => ( {
	module_id: 'font-builder',
	inputs: {
		image_path: input.uploadPath,
		output_dir: createOutputDir(),
		font_name: input.fontName,
		glyph_map: input.glyphMap,
	},
} );

export const fontJobError = ( detail: string | null ): string => {
	// Keep both counts from the worker so users can correct the map.
	return (
		detail?.replace( /^ValueError: /, '' ) ||
		__( 'The font build did not complete.', 'extrachill-studio' )
	);
};

export const createFontDownload = (
	base64: string,
	fontName: string,
	format: FontFormat
) => ( {
	blob: new Blob(
		[ Uint8Array.from( atob( base64 ), ( byte ) => byte.charCodeAt( 0 ) ) ],
		{
			type: `font/${ format }`,
		}
	),
	filename: `${ fontName }-Regular.${ format }`,
} );

export const downloadFont = (
	base64: string,
	fontName: string,
	format: FontFormat
): void => {
	const { blob, filename } = createFontDownload( base64, fontName, format );
	const url = URL.createObjectURL( blob );
	const link = document.createElement( 'a' );
	link.href = url;
	link.download = filename;
	document.body.appendChild( link );
	link.click();
	link.remove();
	setTimeout( () => URL.revokeObjectURL( url ), 1000 );
};
