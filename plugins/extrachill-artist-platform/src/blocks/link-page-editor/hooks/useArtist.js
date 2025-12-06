/**
 * useArtist Hook
 *
 * Manages artist core data (name, bio, profile image).
 */

import { useState, useEffect, useCallback } from '@wordpress/element';
import { getArtist, updateArtist } from '../api/client';

export default function useArtist( artistId ) {
	const [ artist, setArtist ] = useState( null );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState( null );

	const fetchArtist = useCallback( async () => {
		if ( ! artistId ) {
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
	}, [ artistId ] );

	useEffect( () => {
		fetchArtist();
	}, [ fetchArtist ] );

	const update = useCallback(
		async ( data ) => {
			if ( ! artistId ) {
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
			setArtist( ( prev ) => ( { ...prev, name } ) );
		},
		[]
	);

	const setBio = useCallback(
		( bio ) => {
			setArtist( ( prev ) => ( { ...prev, bio } ) );
		},
		[]
	);

	const setProfileImage = useCallback(
		( profileImageId, profileImageUrl ) => {
			setArtist( ( prev ) => ( {
				...prev,
				profile_image_id: profileImageId,
				profile_image_url: profileImageUrl,
			} ) );
		},
		[]
	);

	return {
		artist,
		isLoading,
		error,
		refetch: fetchArtist,
		update,
		setName,
		setBio,
		setProfileImage,
	};
}
