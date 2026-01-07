/**
 * Analytics Context
 *
 * Provides shared state for the analytics dashboard.
 */

import { createContext, useContext, useState, useCallback } from '@wordpress/element';

const AnalyticsContext = createContext();

export function AnalyticsProvider( { children } ) {
	const [ notices, setNotices ] = useState( [] );

	const addNotice = useCallback( ( type, message ) => {
		const id = Date.now();
		setNotices( ( prev ) => [ ...prev, { id, type, message } ] );
		
		// Auto-dismiss after 5 seconds
		setTimeout( () => {
			setNotices( ( prev ) => prev.filter( ( n ) => n.id !== id ) );
		}, 5000 );
	}, [] );

	const removeNotice = useCallback( ( id ) => {
		setNotices( ( prev ) => prev.filter( ( n ) => n.id !== id ) );
	}, [] );

	const value = {
		notices,
		addNotice,
		removeNotice,
	};

	return (
		<AnalyticsContext.Provider value={ value }>
			{ children }
		</AnalyticsContext.Provider>
	);
}

export function useAnalytics() {
	const context = useContext( AnalyticsContext );
	if ( ! context ) {
		throw new Error( 'useAnalytics must be used within an AnalyticsProvider' );
	}
	return context;
}
