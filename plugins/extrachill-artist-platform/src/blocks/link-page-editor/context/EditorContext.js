/**
 * EditorContext
 *
 * Provides combined state management for the link page editor.
 * Coordinates artist, links, and socials data with a unified save function.
 * Includes computed preview styles with font processing.
 */

import {
	createContext,
	useContext,
	useState,
	useCallback,
	useMemo,
} from '@wordpress/element';
import useArtist from '../hooks/useArtist';
import useLinks from '../hooks/useLinks';
import useSocials from '../hooks/useSocials';
import useMediaUpload from '../hooks/useMediaUpload';
import {
	getFontStack,
	getGoogleFontsUrl,
	DEFAULT_TITLE_FONT,
	DEFAULT_BODY_FONT,
} from '../utils/fonts';
import { readDirty, clearDirty } from '../utils/dirtyStorage';

/**
 * Resolve the sessionStorage dirty buffer for an artist on initial load /
 * artist switch. Prompts the user exactly once when a buffer exists so we
 * never silently restore stale work or silently discard real edits.
 *
 * Returns the buffer when kept, or null when missing / discarded / declined.
 */
const resolveRestoredBuffer = ( artistId ) => {
	const stored = readDirty( artistId );
	if ( ! stored ) {
		return null;
	}
	const keep = window.confirm(
		'You have unsaved changes from a previous session for this artist. Keep them? (Cancel to discard.)'
	);
	if ( keep ) {
		return stored;
	}
	clearDirty( artistId );
	return null;
};

const EditorContext = createContext( null );

const DEFAULT_CSS_VARS = {
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
	'--link-page-background-type': 'color',
	'--link-page-background-gradient-start': '#0b5394',
	'--link-page-background-gradient-end': '#53940b',
	'--link-page-background-gradient-direction': 'to right',
	'--link-page-background-image-url': '',
	'--link-page-image-size': 'cover',
	'--link-page-image-position': 'center center',
	'--link-page-image-repeat': 'no-repeat',
	overlay: '1',
	'--link-page-title-font-size': '2.1em',
	'--link-page-button-radius': '8px',
	'--link-page-button-border-width': '0px',
	'--link-page-profile-img-size': '30%',
};

export function EditorProvider( { artistId: initialArtistId, children } ) {
	const config = window.ecLinkPageEditorConfig || {};
	const fonts = config.fonts || [];
	const localFontsCss = config.localFontsCss || '';
	const userArtists = Array.isArray( config.userArtists )
		? config.userArtists
		: [];
	const initialEligibleArtistId = userArtists.some(
		( artist ) => Number( artist.id ) === Number( initialArtistId )
	)
		? initialArtistId
		: userArtists[ 0 ]?.id || 0;

	const [ artistId, setArtistId ] = useState( initialEligibleArtistId );
	const [ activeTab, setActiveTab ] = useState( 'info' );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ saveError, setSaveError ] = useState( null );

	// Resolve the dirty buffer for the initial artist exactly once at mount.
	// `useState` lazy initializer guarantees synchronous resolution and a
	// single prompt before any child hook runs.
	const [ restoredBuffer, setRestoredBuffer ] = useState( () =>
		resolveRestoredBuffer( initialEligibleArtistId )
	);

	const [ hasUnsavedChanges, setHasUnsavedChanges ] = useState(
		() => !! restoredBuffer
	);
	const [ dirtySections, setDirtySections ] = useState(
		() =>
			new Set(
				restoredBuffer && Array.isArray( restoredBuffer.dirtySections )
					? restoredBuffer.dirtySections
					: []
			)
	);

	const artistData = useArtist( artistId, restoredBuffer );
	const linksData = useLinks( artistId, restoredBuffer );
	const socialsData = useSocials( artistId, restoredBuffer );
	const mediaUpload = useMediaUpload();

	const isLoading =
		artistData.isLoading || linksData.isLoading || socialsData.isLoading;

	const error = artistData.error || linksData.error || socialsData.error;

	const markDirty = useCallback( ( section ) => {
		setHasUnsavedChanges( true );
		if ( section ) {
			setDirtySections( ( prev ) => {
				const newSet = new Set( prev );
				newSet.add( section );
				return newSet;
			} );
		}
	}, [] );

	const saveAll = useCallback( async () => {
		setIsSaving( true );
		setSaveError( null );

		try {
			const tasks = [];

			if ( dirtySections.has( 'artist' ) ) {
				tasks.push( {
					section: 'artist',
					promise: artistData.update( {
						name: artistData.artist?.name,
						profile_image_id: artistData.artist?.profile_image_id,
					} ),
				} );
			}

			if ( dirtySections.has( 'links' ) ) {
				tasks.push( {
					section: 'links',
					promise: linksData.update( {
						links: linksData.links,
						settings: { ...linksData.settings, bio: linksData.bio },
						css_vars: linksData.cssVars,
						background_image_id: linksData.backgroundImageId,
					} ),
				} );
			}

			if ( dirtySections.has( 'socials' ) ) {
				tasks.push( {
					section: 'socials',
					promise: socialsData.update( socialsData.socials ),
				} );
			}

			if ( tasks.length === 0 ) {
				setHasUnsavedChanges( false );
				setDirtySections( new Set() );
				clearDirty( artistId );
				setRestoredBuffer( null );
				return true;
			}

			const results = await Promise.allSettled(
				tasks.map( ( task ) => task.promise )
			);

			const failedSections = new Set();
			const failedMessages = [];
			results.forEach( ( result, idx ) => {
				if ( result.status === 'rejected' ) {
					const section = tasks[ idx ].section;
					failedSections.add( section );
					failedMessages.push(
						`${ section }: ${ result.reason?.message || 'unknown error' }`
					);
				}
			} );

			if ( failedSections.size > 0 ) {
				setSaveError( failedMessages.join( '; ' ) );
				setDirtySections( failedSections );
				setHasUnsavedChanges( true );
				return false;
			}

			setHasUnsavedChanges( false );
			setDirtySections( new Set() );
			clearDirty( artistId );
			setRestoredBuffer( null );
			return true;
		} catch ( err ) {
			setSaveError( err.message || 'Failed to save changes' );
			return false;
		} finally {
			setIsSaving( false );
		}
	}, [
		artistId,
		artistData,
		linksData,
		socialsData,
		dirtySections,
	] );

	const switchArtist = useCallback(
		( newArtistId ) => {
			if ( hasUnsavedChanges ) {
				const confirmed = window.confirm(
					'You have unsaved changes. Switch artist anyway?'
				);
				if ( ! confirmed ) {
					return;
				}
				// User confirmed discard of in-flight edits for the current artist.
				clearDirty( artistId );
			}

			// Resolve buffer for the artist we're switching to. If a buffer
			// exists, prompt the user once; on keep, hydrate state from it.
			const nextBuffer = resolveRestoredBuffer( newArtistId );
			setRestoredBuffer( nextBuffer );
			setArtistId( newArtistId );
			setHasUnsavedChanges( !! nextBuffer );
			setDirtySections(
				new Set(
					nextBuffer && Array.isArray( nextBuffer.dirtySections )
						? nextBuffer.dirtySections
						: []
				)
			);
			setSaveError( null );
		},
		[ hasUnsavedChanges, artistId ]
	);

	/**
	 * Update font value - updates both rawFontValues and cssVars.
	 * Stores raw value for save, which backend will process.
	 */
	const updateFontValue = useCallback(
		( type, value ) => {
			const cssKey = type === 'title'
				? '--link-page-title-font-family'
				: '--link-page-body-font-family';
			const rawKey = type === 'title' ? 'title_font' : 'body_font';

			linksData.updateLocalRawFontValues( { [ rawKey ]: value } );
			linksData.updateLocalCssVars( { [ cssKey ]: value } );
			markDirty( 'links' );
		},
		[ linksData, markDirty ]
	);

	/**
	 * Computed preview styles - merges defaults with cssVars and processes fonts.
	 */
	const computedStyles = useMemo( () => {
		const styles = { ...DEFAULT_CSS_VARS };
		const cssVars = linksData.cssVars || {};
		const rawFontValues = linksData.rawFontValues || {};

		// Merge non-font CSS vars
		Object.entries( cssVars ).forEach( ( [ key, value ] ) => {
			if ( value && ! key.includes( 'font-family' ) ) {
				const cssKey = key.startsWith( '--' ) ? key : `--link-page-${ key }`;
				styles[ cssKey ] = value;
			}
		} );

		// Process fonts using raw values for proper font stack resolution
		const titleFont = rawFontValues.title_font || DEFAULT_TITLE_FONT;
		const bodyFont = rawFontValues.body_font || DEFAULT_BODY_FONT;

		styles[ '--link-page-title-font-family' ] = getFontStack( titleFont, fonts );
		styles[ '--link-page-body-font-family' ] = getFontStack( bodyFont, fonts );

		// Background image handling
		if ( linksData.backgroundImageUrl ) {
			styles.backgroundImage = `url(${ linksData.backgroundImageUrl })`;
			styles.backgroundSize = 'cover';
			styles.backgroundPosition = 'center';
			styles.backgroundRepeat = 'no-repeat';
		}

		return styles;
	}, [ linksData.cssVars, linksData.rawFontValues, linksData.backgroundImageUrl, fonts ] );

	/**
	 * Google Fonts URL for currently selected fonts.
	 */
	const googleFontsUrl = useMemo( () => {
		const rawFontValues = linksData.rawFontValues || {};
		const fontValues = [
			rawFontValues.title_font || DEFAULT_TITLE_FONT,
			rawFontValues.body_font || DEFAULT_BODY_FONT,
		].filter( Boolean );

		return getGoogleFontsUrl( fontValues, fonts );
	}, [ linksData.rawFontValues, fonts ] );

	const editorArtist = useMemo(
		() => artistData.artist ? { ...artistData.artist, bio: linksData.bio || '' } : null,
		[ artistData.artist, linksData.bio ]
	);

	/**
	 * Preview data - structured data for the Preview component.
	 */
	const previewData = useMemo(
		() => ( {
			name: artistData.artist?.name || '',
			bio: linksData.bio || '',
			profileImageUrl: artistData.artist?.profile_image_url || '',
			profileShape: linksData.settings?.profile_image_shape || 'circle',
			links: linksData.links || [],
			socials: socialsData.socials || [],
			socialsPosition: linksData.settings?.social_icons_position || 'above',
			subscribeDisplayMode: linksData.settings?.subscribe_display_mode || 'icon_modal',
			overlayEnabled: linksData.cssVars?.overlay !== '0',
			backgroundType: linksData.cssVars?.[ '--link-page-background-type' ] || 'color',
		} ),
		[ artistData.artist, linksData.bio, linksData.links, linksData.settings, linksData.cssVars, socialsData.socials ]
	);

	const value = useMemo(
		() => ( {
			artistId,
			activeTab,
			setActiveTab,
			isLoading,
			isSaving,
			error,
			saveError,
			hasUnsavedChanges,

			artist: editorArtist,
			setName: ( name ) => {
				artistData.setName( name );
				markDirty( 'artist' );
			},
			setBio: ( bio ) => {
				linksData.updateLocalBio( bio );
				markDirty( 'links' );
			},
			setProfileImage: ( id, url ) => {
				artistData.setProfileImage( id, url );
				markDirty( 'artist' );
			},

			links: linksData.links,
			settings: linksData.settings,
			cssVars: linksData.cssVars,
			rawFontValues: linksData.rawFontValues,
			backgroundImageId: linksData.backgroundImageId,
			backgroundImageUrl: linksData.backgroundImageUrl,
			updateLinks: ( newLinks ) => {
				linksData.updateLocalLinks( newLinks );
				markDirty( 'links' );
			},
			updateSettings: ( newSettings ) => {
				linksData.updateLocalSettings( newSettings );
				markDirty( 'links' );
			},
			updateCssVars: ( newCssVars ) => {
				linksData.updateLocalCssVars( newCssVars );
				markDirty( 'links' );
			},
			updateFontValue,
			updateBackgroundImage: ( id, url ) => {
				linksData.updateBackgroundImage( id, url );
				markDirty( 'links' );
			},

			socials: socialsData.socials,
			updateSocials: ( newSocials ) => {
				socialsData.updateLocalSocials( newSocials );
				markDirty( 'socials' );
			},

			uploadMedia: mediaUpload.upload,
			removeMedia: mediaUpload.remove,
			isUploading: mediaUpload.isUploading,
			uploadError: mediaUpload.error,

			userArtists,
			fonts,
			socialTypes: config.socialTypes || [],
			linkPageCssUrl: config.linkPageCssUrl || '',
			socialIconsCssUrl: config.socialIconsCssUrl || '',
			shareModalCssUrl: config.shareModalCssUrl || '',
			fontAwesomeUrl: config.fontAwesomeUrl || '',
			localFontsCss,

			// Preview-related computed values
			computedStyles,
			previewData,
			googleFontsUrl,

			saveAll,
			switchArtist,
			refetch: () => {
				artistData.refetch();
				linksData.refetch();
				socialsData.refetch();
			},
		} ),
		[
			artistId,
			activeTab,
			isLoading,
			isSaving,
			error,
			saveError,
			hasUnsavedChanges,
			artistData,
			linksData,
			socialsData,
			mediaUpload,
			editorArtist,
			userArtists,
			fonts,
			config.socialTypes,
			config.linkPageCssUrl,
			config.socialIconsCssUrl,
			config.shareModalCssUrl,
			config.fontAwesomeUrl,
			localFontsCss,
			computedStyles,
			previewData,
			googleFontsUrl,
			updateFontValue,
			saveAll,
			switchArtist,
			markDirty,
		]
	);

	return (
		<EditorContext.Provider value={ value }>
			{ children }
		</EditorContext.Provider>
	);
}

export function useEditor() {
	const context = useContext( EditorContext );
	if ( ! context ) {
		throw new Error( 'useEditor must be used within EditorProvider' );
	}
	return context;
}

export default EditorContext;
