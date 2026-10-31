/**
 * useArtist Hook
 *
 * Manages artist core data (name and profile image).
 *
 * Accepts an optional `restoredBuffer` (resolved by EditorProvider from
 * sessionStorage). When the buffer has an `artist` section for the current
 * artist, initial state is hydrated from the buffer and the server fetch
 * is skipped — the user's unsaved edits win over a fresh server snapshot.
 */

import { useState, useEffect, useCallback } from '@wordpress/element';
import { getArtist, updateArtist, getConfig } from '../../shared/api/client';
import { writeDirty } from '../utils/dirtyStorage';

const bufferArtist = ( restoredBuffer ) =>
	restoredBuffer && restoredBuffer.artist ? restoredBuffer.artist : null;

export default function useArtist( artistId, restoredBuffer = null ) {
	const initialBuffer = bufferArtist( restoredBuffer );

	const [ artist, setArtist ] = useState( () =>
		initialBuffer ? { ...initialBuffer } : null
	);
	const [ isLoading, setIsLoading ] = useState( ! initialBuffer );
	const [ error, setError ] = useState( null );

	const fetchArtist = useCallback( async () => {
		if ( ! artistId ) {
			setIsLoading( false );
			return;
		}

		const buffered = bufferArtist( restoredBuffer );
		if ( buffered ) {
			setArtist( { ...buffered } );
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
			const data = await getArtist( artistId );
			setArtist( data );
		} catch ( err ) {
			setError( err.message || 'Failed to load artist data' );
		} finally {
			setIsLoading( false );
		}
	}, [ artistId, restoredBuffer ] );

	useEffect( () => {
		fetchArtist();
	}, [ fetchArtist ] );

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
				const updated = await updateArtist( artistId, data );
				setArtist( updated );
				return updated;
			} catch ( err ) {
				setError( err.message || 'Failed to update artist' );
				throw err;
			}
		},
		[ artistId ]
	);

	const setName = useCallback(
		( name ) => {
			setArtist( ( prev ) => {
				const next = { ...prev, name };
				writeDirty( artistId, {
					section: 'artist',
					artist: { name },
				} );
				return next;
			} );
		},
		[ artistId ]
	);

	const setProfileImage = useCallback(
		( profileImageId, profileImageUrl ) => {
			setArtist( ( prev ) => {
				const next = {
					...prev,
					profile_image_id: profileImageId,
					profile_image_url: profileImageUrl,
				};
				writeDirty( artistId, {
					section: 'artist',
					artist: {
						profile_image_id: profileImageId,
						profile_image_url: profileImageUrl,
					},
				} );
				return next;
			} );
		},
		[ artistId ]
	);

	return {
		artist,
		isLoading,
		error,
		refetch: fetchArtist,
		update,
		setName,
		setProfileImage,
	};
}
