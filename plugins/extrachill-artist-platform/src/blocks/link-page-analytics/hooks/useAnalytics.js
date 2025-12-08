/**
 * useAnalytics Hook
 *
 * Manages analytics data fetching, date range state, and loading/error states.
 */

import { useState, useEffect, useCallback } from '@wordpress/element';
import { getAnalytics } from '../../shared/api/client';

export default function useAnalytics( artistId ) {
	const [ dateRange, setDateRange ] = useState( 30 );
	const [ analytics, setAnalytics ] = useState( null );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState( null );

	const fetchAnalytics = useCallback( async () => {
		if ( ! artistId ) {
			return;
		}

		setIsLoading( true );
		setError( null );

		try {
			const data = await getAnalytics( artistId, dateRange );
			setAnalytics( data );
		} catch ( err ) {
			setError( err.message || 'Failed to load analytics' );
		} finally {
			setIsLoading( false );
		}
	}, [ artistId, dateRange ] );

	useEffect( () => {
		fetchAnalytics();
	}, [ fetchAnalytics ] );

	return {
		analytics,
		dateRange,
		setDateRange,
		isLoading,
		error,
		refetch: fetchAnalytics,
	};
}
