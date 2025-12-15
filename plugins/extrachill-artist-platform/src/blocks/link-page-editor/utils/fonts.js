/**
 * Font Utilities
 *
 * JavaScript mirror of ExtraChillArtistPlatform_Fonts PHP class.
 * Provides font stack resolution and Google Font URL generation.
 * Uses fonts array from ecLinkPageEditorConfig.fonts.
 */

export const DEFAULT_TITLE_FONT = 'Loft Sans';
export const DEFAULT_BODY_FONT = 'Helvetica';
export const DEFAULT_FONT_STACK = "'Helvetica', Arial, sans-serif";

/**
 * Get the full CSS font stack for a font value.
 *
 * @param {string} fontValue - Raw font value (e.g., 'Roboto')
 * @param {Array}  fonts     - Fonts configuration array
 * @return {string} CSS font-family value with fallbacks
 */
export function getFontStack( fontValue, fonts ) {
	if ( ! fontValue || ! Array.isArray( fonts ) ) {
		return DEFAULT_FONT_STACK;
	}

	const font = fonts.find( ( f ) => f.value === fontValue );

	if ( font?.stack ) {
		return font.stack;
	}

	// Fallback: wrap unknown font name with quotes and add default stack
	if ( ! fontValue.includes( ',' ) && ! fontValue.includes( "'" ) && ! fontValue.includes( '"' ) ) {
		return `'${ fontValue }', ${ DEFAULT_FONT_STACK }`;
	}

	return fontValue || DEFAULT_FONT_STACK;
}

/**
 * Check if a font value is a Google Font (requires loading).
 *
 * @param {string} fontValue - Raw font value
 * @param {Array}  fonts     - Fonts configuration array
 * @return {boolean} True if font requires Google Fonts loading
 */
export function isGoogleFont( fontValue, fonts ) {
	if ( ! fontValue || ! Array.isArray( fonts ) ) {
		return false;
	}

	const font = fonts.find( ( f ) => f.value === fontValue );

	return font?.google_font_param && font.google_font_param !== 'local_default';
}

/**
 * Get the Google Font parameter for a font value.
 *
 * @param {string} fontValue - Raw font value
 * @param {Array}  fonts     - Fonts configuration array
 * @return {string|null} Google Font parameter or null if local font
 */
export function getGoogleFontParam( fontValue, fonts ) {
	if ( ! fontValue || ! Array.isArray( fonts ) ) {
		return null;
	}

	const font = fonts.find( ( f ) => f.value === fontValue );

	if ( font?.google_font_param && font.google_font_param !== 'local_default' ) {
		return font.google_font_param;
	}

	return null;
}

/**
 * Generate Google Fonts URL for multiple font values.
 *
 * @param {Array} fontValues - Array of raw font values
 * @param {Array} fonts      - Fonts configuration array
 * @return {string|null} Google Fonts URL or null if no Google Fonts needed
 */
export function getGoogleFontsUrl( fontValues, fonts ) {
	if ( ! Array.isArray( fontValues ) || ! Array.isArray( fonts ) ) {
		return null;
	}

	const googleFontParams = fontValues
		.map( ( value ) => getGoogleFontParam( value, fonts ) )
		.filter( Boolean );

	if ( googleFontParams.length === 0 ) {
		return null;
	}

	const uniqueParams = [ ...new Set( googleFontParams ) ];

	return 'https://fonts.googleapis.com/css2?family=' + uniqueParams.join( '&family=' ) + '&display=swap';
}

/**
 * Process CSS variables object, converting font values to font stacks.
 *
 * @param {Object} cssVars - CSS variables object with raw font values
 * @param {Array}  fonts   - Fonts configuration array
 * @return {Object} CSS variables with processed font stacks
 */
export function processFontCssVars( cssVars, fonts ) {
	if ( ! cssVars || typeof cssVars !== 'object' ) {
		return cssVars;
	}

	const processed = { ...cssVars };

	if ( processed[ '--link-page-title-font-family' ] ) {
		processed[ '--link-page-title-font-family' ] = getFontStack(
			processed[ '--link-page-title-font-family' ],
			fonts
		);
	}

	if ( processed[ '--link-page-body-font-family' ] ) {
		processed[ '--link-page-body-font-family' ] = getFontStack(
			processed[ '--link-page-body-font-family' ],
			fonts
		);
	}

	return processed;
}
