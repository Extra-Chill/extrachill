/**
 * useLinks Hook
 *
 * Manages link page data (bio, links, settings, cssVars, rawFontValues).
 *
 * Accepts an optional `restoredBuffer` (resolved by EditorProvider from
 * sessionStorage). When the buffer has a `links` section for the current
 * artist, initial state is hydrated from the buffer and the server fetch
 * is skipped — the user's unsaved edits win over a fresh server snapshot.
 */

import { useState, useEffect, useCallback } from '@wordpress/element';
import { getLinks, updateLinks, getConfig } from '../../shared/api/client';
import { writeDirty } from '../utils/dirtyStorage';

const DEFAULT_RAW_FONTS = { title_font: '', body_font: '' };

const bufferLinks = ( restoredBuffer ) =>
	restoredBuffer && restoredBuffer.links ? restoredBuffer.links : null;

export default function useLinks( artistId, restoredBuffer = null ) {
	const initialBuffer = bufferLinks( restoredBuffer );

	const [ links, setLinks ] = useState( () =>
		initialBuffer?.links ? initialBuffer.links : []
	);
	const [ bio, setBio ] = useState( () =>
		typeof initialBuffer?.bio === 'string' ? initialBuffer.bio : ''
	);
	const [ settings, setSettings ] = useState( () =>
		initialBuffer?.settings ? initialBuffer.settings : {}
	);
	const [ cssVars, setCssVars ] = useState( () =>
		initialBuffer?.css_vars ? initialBuffer.css_vars : {}
	);
	const [ rawFontValues, setRawFontValues ] = useState( () =>
		initialBuffer?.raw_font_values
			? initialBuffer.raw_font_values
			: { ...DEFAULT_RAW_FONTS }
	);
	const [ backgroundImageId, setBackgroundImageId ] = useState(
		() => initialBuffer?.background_image_id ?? null
	);
	const [ backgroundImageUrl, setBackgroundImageUrl ] = useState(
		() => initialBuffer?.background_image_url ?? null
	);
	const [ isLoading, setIsLoading ] = useState( ! initialBuffer );
	const [ error, setError ] = useState( null );

	const fetchLinks = useCallback( async () => {
		if ( ! artistId ) {
			setIsLoading( false );
			return;
		}

		const buffered = bufferLinks( restoredBuffer );
		if ( buffered ) {
			// Rehydrate from buffer — user's unsaved edits win for this artist.
			setLinks( buffered.links || [] );
			setBio( typeof buffered.bio === 'string' ? buffered.bio : '' );
			setSettings( buffered.settings || {} );
			setCssVars( buffered.css_vars || {} );
			setRawFontValues(
				buffered.raw_font_values || { ...DEFAULT_RAW_FONTS }
			);
			setBackgroundImageId( buffered.background_image_id ?? null );
			setBackgroundImageUrl( buffered.background_image_url ?? null );
			setIsLoading( false );
			return;
		}

		const config = getConfig();
		const currentUser = config.currentUser || {};
		if ( currentUser.artist_id && currentUser.artist_id !== artistId ) {
			setIsLoading( false );
			return;
		}

		setIsLoading( true );
		setError( null );

		try {
			const data = await getLinks( artistId );
			setLinks( data.links || [] );
			setBio( data.bio || '' );
			setSettings( data.settings || {} );
			setCssVars( data.css_vars || {} );
			setRawFontValues(
				data.raw_font_values || { ...DEFAULT_RAW_FONTS }
			);
			setBackgroundImageId( data.background_image_id || null );
			setBackgroundImageUrl( data.background_image_url || null );
		} catch ( err ) {
			setError( err.message || 'Failed to load link page data' );
		} finally {
			setIsLoading( false );
		}
	}, [ artistId, restoredBuffer ] );

	useEffect( () => {
		fetchLinks();
	}, [ fetchLinks ] );

	const update = useCallback(
		async ( data ) => {
			if ( ! artistId ) {
				return;
			}

			const config = getConfig();
			const currentUser = config.currentUser || {};
			if ( currentUser.artist_id && currentUser.artist_id !== artistId ) {
				return;
			}

			try {
				const updated = await updateLinks( artistId, data );
				setLinks( updated.links || [] );
				setBio( updated.bio || '' );
				setSettings( updated.settings || {} );
				setCssVars( updated.css_vars || {} );
				setRawFontValues(
					updated.raw_font_values || { ...DEFAULT_RAW_FONTS }
				);
				setBackgroundImageId( updated.background_image_id || null );
				setBackgroundImageUrl( updated.background_image_url || null );
				return updated;
			} catch ( err ) {
				setError( err.message || 'Failed to update link page' );
				throw err;
			}
		},
		[ artistId ]
	);

	const updateLocalLinks = useCallback(
		( newLinks ) => {
			setLinks( newLinks );
			writeDirty( artistId, { section: 'links', links: { links: newLinks } } );
		},
		[ artistId ]
	);

	const updateLocalBio = useCallback(
		( newBio ) => {
			setBio( newBio );
			writeDirty( artistId, { section: 'links', links: { bio: newBio } } );
		},
		[ artistId ]
	);

	const updateLocalSettings = useCallback(
		( newSettings ) => {
			setSettings( ( prev ) => {
				const merged = { ...prev, ...newSettings };
				writeDirty( artistId, {
					section: 'links',
					links: { settings: merged },
				} );
				return merged;
			} );
		},
		[ artistId ]
	);

	const updateLocalCssVars = useCallback(
		( newCssVars ) => {
			setCssVars( ( prev ) => {
				const merged = { ...prev, ...newCssVars };
				writeDirty( artistId, {
					section: 'links',
					links: { css_vars: merged },
				} );
				return merged;
			} );
		},
		[ artistId ]
	);

	const updateLocalRawFontValues = useCallback(
		( newRawFontValues ) => {
			setRawFontValues( ( prev ) => {
				const merged = { ...prev, ...newRawFontValues };
				writeDirty( artistId, {
					section: 'links',
					links: { raw_font_values: merged },
				} );
				return merged;
			} );
		},
		[ artistId ]
	);

	const updateBackgroundImage = useCallback(
		( id, url ) => {
			setBackgroundImageId( id );
			setBackgroundImageUrl( url );
			writeDirty( artistId, {
				section: 'links',
				links: { background_image_id: id, background_image_url: url },
			} );
		},
		[ artistId ]
	);

	return {
		links,
		bio,
		settings,
		cssVars,
		rawFontValues,
		backgroundImageId,
		backgroundImageUrl,
		isLoading,
		error,
		refetch: fetchLinks,
		update,
		updateLocalLinks,
		updateLocalBio,
		updateLocalSettings,
		updateLocalCssVars,
		updateLocalRawFontValues,
		updateBackgroundImage,
	};
}
