/**
 * AnalyticsContext
 *
 * React context providing artist state and configuration for the analytics dashboard.
 */

import { createContext, useContext, useState, useCallback } from '@wordpress/element';

const AnalyticsContext = createContext( null );

export function AnalyticsProvider( { initialArtistId, children } ) {
	const config = window.ecArtistAnalyticsConfig || {};
	const userArtists = Array.isArray( config.userArtists )
		? config.userArtists
		: [];
	const configuredArtistId = initialArtistId || config.artistId;
	const initialEligibleArtistId = userArtists.some(
		( artist ) => Number( artist.id ) === Number( configuredArtistId )
	)
		? configuredArtistId
		: userArtists[ 0 ]?.id || 0;
	const [ artistId, setArtistId ] = useState( initialEligibleArtistId );

	const switchArtist = useCallback(
		( newArtistId ) => {
			if ( newArtistId && newArtistId !== artistId ) {
				setArtistId( newArtistId );
			}
		},
		[ artistId ]
	);

	const value = {
		artistId,
		userArtists,
		switchArtist,
		restUrl: config.restUrl || '',
		nonce: config.nonce || '',
		linkPageBaseUrl: config.linkPageBaseUrl || '',
	};

	return (
		<AnalyticsContext.Provider value={ value }>
			{ children }
		</AnalyticsContext.Provider>
	);
}

export function useAnalyticsContext() {
	const context = useContext( AnalyticsContext );
	if ( ! context ) {
		throw new Error( 'useAnalyticsContext must be used within an AnalyticsProvider' );
	}
	return context;
}
