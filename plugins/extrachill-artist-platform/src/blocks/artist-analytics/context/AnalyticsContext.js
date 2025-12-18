/**
 * AnalyticsContext
 *
 * React context providing artist state and configuration for the analytics dashboard.
 */

import { createContext, useContext, useState, useCallback } from '@wordpress/element';

const AnalyticsContext = createContext( null );

export function AnalyticsProvider( { initialArtistId, children } ) {
	const config = window.ecArtistAnalyticsConfig || {};
	const [ artistId, setArtistId ] = useState( initialArtistId || config.artistId );

	const userArtists = config.userArtists || [];

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
