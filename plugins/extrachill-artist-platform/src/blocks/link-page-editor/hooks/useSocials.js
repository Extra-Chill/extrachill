/**
 * useSocials Hook
 *
 * Manages social links data.
 */

import { useState, useEffect, useCallback } from '@wordpress/element';
import { getSocials, updateSocials } from '../api/client';

export default function useSocials( artistId ) {
	const [ socials, setSocials ] = useState( [] );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState( null );

	const fetchSocials = useCallback( async () => {
		if ( ! artistId ) {
			setIsLoading( false );
			return;
		}

		setIsLoading( true );
		setError( null );

		try {
			const data = await getSocials( artistId );
			setSocials( data.social_links || [] );
		} catch ( err ) {
			setError( err.message || 'Failed to load social links' );
		} finally {
			setIsLoading( false );
		}
	}, [ artistId ] );

	useEffect( () => {
		fetchSocials();
	}, [ fetchSocials ] );

	const update = useCallback(
		async ( socialLinks ) => {
			if ( ! artistId ) {
				return;
			}

			try {
				const updated = await updateSocials( artistId, {
					social_links: socialLinks,
				} );
				setSocials( updated.social_links || [] );
				return updated;
			} catch ( err ) {
				setError( err.message || 'Failed to update social links' );
				throw err;
			}
		},
		[ artistId ]
	);

	const updateLocalSocials = useCallback( ( newSocials ) => {
		setSocials( newSocials );
	}, [] );

	return {
		socials,
		isLoading,
		error,
		refetch: fetchSocials,
		update,
		updateLocalSocials,
	};
}
