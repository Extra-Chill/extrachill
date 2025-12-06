/**
 * useLinks Hook
 *
 * Manages link page data (links, settings, cssVars).
 */

import { useState, useEffect, useCallback } from '@wordpress/element';
import { getLinks, updateLinks } from '../api/client';

export default function useLinks( artistId ) {
	const [ links, setLinks ] = useState( [] );
	const [ settings, setSettings ] = useState( {} );
	const [ cssVars, setCssVars ] = useState( {} );
	const [ backgroundImageId, setBackgroundImageId ] = useState( null );
	const [ backgroundImageUrl, setBackgroundImageUrl ] = useState( null );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState( null );

	const fetchLinks = useCallback( async () => {
		if ( ! artistId ) {
			setIsLoading( false );
			return;
		}

		setIsLoading( true );
		setError( null );

		try {
			const data = await getLinks( artistId );
			setLinks( data.links || [] );
			setSettings( data.settings || {} );
			setCssVars( data.css_vars || {} );
			setBackgroundImageId( data.background_image_id || null );
			setBackgroundImageUrl( data.background_image_url || null );
		} catch ( err ) {
			setError( err.message || 'Failed to load link page data' );
		} finally {
			setIsLoading( false );
		}
	}, [ artistId ] );

	useEffect( () => {
		fetchLinks();
	}, [ fetchLinks ] );

	const update = useCallback(
		async ( data ) => {
			if ( ! artistId ) {
				return;
			}

			try {
				const updated = await updateLinks( artistId, data );
				setLinks( updated.links || [] );
				setSettings( updated.settings || {} );
				setCssVars( updated.css_vars || {} );
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

	const updateLocalLinks = useCallback( ( newLinks ) => {
		setLinks( newLinks );
	}, [] );

	const updateLocalSettings = useCallback( ( newSettings ) => {
		setSettings( ( prev ) => ( { ...prev, ...newSettings } ) );
	}, [] );

	const updateLocalCssVars = useCallback( ( newCssVars ) => {
		setCssVars( ( prev ) => ( { ...prev, ...newCssVars } ) );
	}, [] );

	const updateBackgroundImage = useCallback( ( id, url ) => {
		setBackgroundImageId( id );
		setBackgroundImageUrl( url );
	}, [] );

	return {
		links,
		settings,
		cssVars,
		backgroundImageId,
		backgroundImageUrl,
		isLoading,
		error,
		refetch: fetchLinks,
		update,
		updateLocalLinks,
		updateLocalSettings,
		updateLocalCssVars,
		updateBackgroundImage,
	};
}
