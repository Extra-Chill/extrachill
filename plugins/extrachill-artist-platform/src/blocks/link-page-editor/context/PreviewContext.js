/**
 * PreviewContext
 *
 * Computes CSS custom properties and preview styles from editor state.
 * Provides a reactive style object for the Preview component.
 * Uses canonical --link-page-* CSS variable prefix to match backend.
 */

import { createContext, useContext, useMemo } from '@wordpress/element';
import { useEditor } from './EditorContext';

const PreviewContext = createContext( null );

const DEFAULT_CSS_VARS = {
	// Colors - Dark theme inspired
	'--link-page-background-color': '#121212',
	'--link-page-card-bg-color': 'rgba(0, 0, 0, 0.4)',
	'--link-page-text-color': '#e5e5e5',
	'--link-page-link-text-color': '#ffffff',
	'--link-page-button-bg-color': '#0b5394',
	'--link-page-button-border-color': '#0b5394',
	'--link-page-button-hover-bg-color': '#53940b',
	'--link-page-button-hover-text-color': '#ffffff',
	'--link-page-muted-text-color': '#aaa',
	'--link-page-overlay-color': 'rgba(0, 0, 0, 0.5)',
	'--link-page-input-bg': '#181818',
	'--link-page-accent': '#888',
	'--link-page-accent-hover': '#222',

	// Background settings
	'--link-page-background-type': 'color',
	'--link-page-background-gradient-start': '#0b5394',
	'--link-page-background-gradient-end': '#53940b',
	'--link-page-background-gradient-direction': 'to right',
	'--link-page-background-image-url': '',
	'--link-page-image-size': 'cover',
	'--link-page-image-position': 'center center',
	'--link-page-image-repeat': 'no-repeat',
	overlay: '1',

	// Typography
	'--link-page-title-font-family': 'WilcoLoftSans',
	'--link-page-title-font-size': '2.1em',
	'--link-page-body-font-family': 'Helvetica',

	// Button styling
	'--link-page-button-radius': '8px',
	'--link-page-button-border-width': '0px',

	// Profile image settings
	'--link-page-profile-img-size': '30%',
};

export function PreviewProvider( { children } ) {
	const {
		cssVars,
		backgroundImageUrl,
		artist,
		links,
		socials,
		settings,
	} = useEditor();

	const computedStyles = useMemo( () => {
		const styles = { ...DEFAULT_CSS_VARS };

		if ( cssVars ) {
			Object.entries( cssVars ).forEach( ( [ key, value ] ) => {
				if ( value ) {
					// CSS vars from backend already have --link-page-* prefix
					const cssKey = key.startsWith( '--' ) ? key : `--link-page-${ key }`;
					styles[ cssKey ] = value;
				}
			} );
		}

		if ( backgroundImageUrl ) {
			styles.backgroundImage = `url(${ backgroundImageUrl })`;
			styles.backgroundSize = 'cover';
			styles.backgroundPosition = 'center';
			styles.backgroundRepeat = 'no-repeat';
		}

		return styles;
	}, [ cssVars, backgroundImageUrl ] );

	const previewData = useMemo(
		() => ( {
			name: artist?.name || '',
			bio: artist?.bio || '',
			profileImageUrl: artist?.profile_image_url || '',
			links: links || [],
			socials: socials || [],
			socialsPosition: settings?.social_icons_position || 'above',
			subscribeDisplayMode: settings?.subscribe_display_mode || 'icon_modal',
		} ),
		[ artist, links, socials, settings ]
	);

	const value = useMemo(
		() => ( {
			computedStyles,
			previewData,
		} ),
		[ computedStyles, previewData ]
	);

	return (
		<PreviewContext.Provider value={ value }>
			{ children }
		</PreviewContext.Provider>
	);
}

export function usePreview() {
	const context = useContext( PreviewContext );
	if ( ! context ) {
		throw new Error( 'usePreview must be used within PreviewProvider' );
	}
	return context;
}

export default PreviewContext;
