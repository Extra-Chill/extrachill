/**
 * useSocials Hook
 *
 * Manages social links data.
 *
 * Accepts an optional `restoredBuffer` (resolved by EditorProvider from
 * sessionStorage). When the buffer has a `socials` section for the current
 * artist, initial state is hydrated from the buffer and the server fetch
 * is skipped — the user's unsaved edits win over a fresh server snapshot.
 */

import { useState, useEffect, useCallback } from '@wordpress/element';
import { getSocials, updateSocials, getConfig } from '../../shared/api/client';
import { writeDirty } from '../utils/dirtyStorage';

const normalizeSocials = ( socialLinks ) =>
	( socialLinks || [] )
		.filter( ( social ) => social && social.id && social.type && social.icon_class )
		.map( ( social ) => ( {
			id: String( social.id ),
			type: social.type,
			url: social.url || '',
			icon_class: social.icon_class,
		} ) );

const bufferSocials = ( restoredBuffer ) =>
	restoredBuffer && Array.isArray( restoredBuffer.socials )
		? restoredBuffer.socials
		: null;

export default function useSocials( artistId, restoredBuffer = null ) {
	const initialBuffer = bufferSocials( restoredBuffer );

	const [ socials, setSocials ] = useState( () =>
		initialBuffer ? normalizeSocials( initialBuffer ) : []
	);
	const [ isLoading, setIsLoading ] = useState( ! initialBuffer );
	const [ error, setError ] = useState( null );

	const fetchSocials = useCallback( async () => {
		if ( ! artistId ) {
			setIsLoading( false );
			return;
		}

		const buffered = bufferSocials( restoredBuffer );
		if ( buffered ) {
			setSocials( normalizeSocials( buffered ) );
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
	}, [ artistId, restoredBuffer ] );

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

	const updateLocalSocials = useCallback(
		( newSocials ) => {
			const normalized = normalizeSocials( newSocials );
			setSocials( normalized );
			writeDirty( artistId, {
				section: 'socials',
				socials: normalized,
			} );
		},
		[ artistId ]
	);

	return {
		socials,
		isLoading,
		error,
		refetch: fetchSocials,
		update,
		updateLocalSocials,
	};
}
