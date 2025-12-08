/**
 * AnalyticsContext
 *
 * React context providing artist state and configuration for the analytics dashboard.
 */

import { createContext, useContext, useState, useCallback } from '@wordpress/element';

const AnalyticsContext = createContext( null );

export function AnalyticsProvider( { initialArtistId, initialLinkPageId, children } ) {
	const config = window.ecLinkPageAnalyticsConfig || {};
	const [ artistId, setArtistId ] = useState( initialArtistId || config.artistId );
	const [ linkPageId, setLinkPageId ] = useState( initialLinkPageId || config.linkPageId || 0 );

	const userArtists = config.userArtists || [];

	const switchArtist = useCallback(
		( newArtistId ) => {
			if ( newArtistId && newArtistId !== artistId ) {
				setArtistId( newArtistId );
				// Link page will be resolved server-side on reload; keep local id zeroed to avoid stale calls
				setLinkPageId( 0 );
			}
		},
		[ artistId ]
	);

	const value = {
		artistId,
		linkPageId,
		userArtists,
		switchArtist,
		restUrl: config.restUrl || '',
		nonce: config.nonce || '',
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
