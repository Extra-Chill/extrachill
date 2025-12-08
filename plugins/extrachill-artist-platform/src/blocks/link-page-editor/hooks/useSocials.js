/**
 * useSocials Hook
 *
 * Manages social links data.
 */

import { useState, useEffect, useCallback } from '@wordpress/element';
import { getSocials, updateSocials, getConfig } from '../../shared/api/client';

const normalizeSocials = ( socialLinks ) =>
	( socialLinks || [] )
		.filter( ( social ) => social && social.id && social.type && social.icon_class )
		.map( ( social ) => ( {
			id: String( social.id ),
			type: social.type,
			url: social.url || '',
			icon_class: social.icon_class,
		} ) );

export default function useSocials( artistId ) {
	const [ socials, setSocials ] = useState( [] );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState( null );

	const fetchSocials = useCallback( async () => {
		if ( ! artistId ) {
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
			const data = await getSocials( artistId );
			setSocials( normalizeSocials( data.social_links ) );
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

			const config = getConfig();
			const currentUser = config.currentUser || {};
			if ( currentUser.artist_id && currentUser.artist_id !== artistId ) {
				return;
			}

			try {
				const updated = await updateSocials( artistId, {
					social_links: socialLinks,
				} );
				setSocials( normalizeSocials( updated.social_links ) );
				return updated;
			} catch ( err ) {
				setError( err.message || 'Failed to update social links' );
				throw err;
			}
		},
		[ artistId ]
	);

	const updateLocalSocials = useCallback( ( newSocials ) => {
		setSocials( normalizeSocials( newSocials ) );
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
