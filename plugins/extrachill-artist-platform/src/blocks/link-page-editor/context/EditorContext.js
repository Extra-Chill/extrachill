/**
 * EditorContext
 *
 * Provides combined state management for the link page editor.
 * Coordinates artist, links, and socials data with a unified save function.
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

const EditorContext = createContext( null );

export function EditorProvider( { artistId: initialArtistId, children } ) {
	const config = window.ecLinkPageEditorConfig || {};
	const [ artistId, setArtistId ] = useState( initialArtistId );
	const [ activeTab, setActiveTab ] = useState( 'info' );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ saveError, setSaveError ] = useState( null );
	const [ hasUnsavedChanges, setHasUnsavedChanges ] = useState( false );

	const artistData = useArtist( artistId );
	const linksData = useLinks( artistId );
	const socialsData = useSocials( artistId );
	const mediaUpload = useMediaUpload();

	const isLoading =
		artistData.isLoading || linksData.isLoading || socialsData.isLoading;

	const error = artistData.error || linksData.error || socialsData.error;

	const markDirty = useCallback( () => {
		setHasUnsavedChanges( true );
	}, [] );

	const saveAll = useCallback( async () => {
		setIsSaving( true );
		setSaveError( null );

		try {
			await Promise.all( [
				artistData.update( {
					name: artistData.artist?.name,
					bio: artistData.artist?.bio,
					profile_image_id: artistData.artist?.profile_image_id,
				} ),
				linksData.update( {
					links: linksData.links,
					settings: linksData.settings,
					css_vars: linksData.cssVars,
					background_image_id: linksData.backgroundImageId,
				} ),
				socialsData.update( socialsData.socials ),
			] );

			setHasUnsavedChanges( false );
			return true;
		} catch ( err ) {
			setSaveError( err.message || 'Failed to save changes' );
			return false;
		} finally {
			setIsSaving( false );
		}
	}, [
		artistData,
		linksData,
		socialsData,
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
			}
			setArtistId( newArtistId );
			setHasUnsavedChanges( false );
		},
		[ hasUnsavedChanges ]
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

			artist: artistData.artist,
			setName: ( name ) => {
				artistData.setName( name );
				markDirty();
			},
			setBio: ( bio ) => {
				artistData.setBio( bio );
				markDirty();
			},
			setProfileImage: ( id, url ) => {
				artistData.setProfileImage( id, url );
				markDirty();
			},

			links: linksData.links,
			settings: linksData.settings,
			cssVars: linksData.cssVars,
			backgroundImageId: linksData.backgroundImageId,
			backgroundImageUrl: linksData.backgroundImageUrl,
			updateLinks: ( newLinks ) => {
				linksData.updateLocalLinks( newLinks );
				markDirty();
			},
			updateSettings: ( newSettings ) => {
				linksData.updateLocalSettings( newSettings );
				markDirty();
			},
			updateCssVars: ( newCssVars ) => {
				linksData.updateLocalCssVars( newCssVars );
				markDirty();
			},
			updateBackgroundImage: ( id, url ) => {
				linksData.updateBackgroundImage( id, url );
				markDirty();
			},

			socials: socialsData.socials,
			updateSocials: ( newSocials ) => {
				socialsData.updateLocalSocials( newSocials );
				markDirty();
			},

			uploadMedia: mediaUpload.upload,
			removeMedia: mediaUpload.remove,
			isUploading: mediaUpload.isUploading,
			uploadError: mediaUpload.error,

			userArtists: config.userArtists || [],
			fonts: config.fonts || [],
			socialTypes: config.socialTypes || [],

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
			config.userArtists,
			config.fonts,
			config.socialTypes,
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
