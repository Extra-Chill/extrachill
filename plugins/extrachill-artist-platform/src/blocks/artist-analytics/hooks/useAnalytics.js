/**
 * useAnalytics Hook
 *
 * Manages analytics data fetching, date range state, and loading/error states.
 */

import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import { getAnalytics } from '../../shared/api/client';

const DEFAULT_SELECTION = { mode: 'preset', days: 30 };

export default function useAnalytics( artistId ) {
	const [ selection, setSelection ] = useState( DEFAULT_SELECTION );
	const [ analytics, setAnalytics ] = useState( null );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ refreshKey, setRefreshKey ] = useState( 0 );
	const requestId = useRef( 0 );

	useEffect( () => {
		let range = null;
		if ( selection.mode === 'preset' ) {
			range = selection.days;
		} else if ( selection.startDate && selection.endDate ) {
			range = {
				start_date: selection.startDate,
				end_date: selection.endDate,
			};
		}
		const currentRequest = ++requestId.current;

		if ( ! artistId || ! range ) {
			setAnalytics( null );
			setIsLoading( false );
			setError( null );
			return undefined;
		}

		let active = true;

		setIsLoading( true );
		setError( null );

		getAnalytics( artistId, range )
			.then( ( data ) => {
				if ( active && currentRequest === requestId.current ) {
					setAnalytics( data );
				}
			} )
			.catch( ( err ) => {
				if ( active && currentRequest === requestId.current ) {
					setError( err.message || 'Failed to load analytics' );
				}
			} )
			.finally( () => {
				if ( active && currentRequest === requestId.current ) {
					setIsLoading( false );
				}
			} );

		return () => {
			active = false;
		};
	}, [ artistId, selection, refreshKey ] );

	const selectPreset = useCallback( ( days ) => {
		setSelection( { mode: 'preset', days } );
	}, [] );

	const selectCustom = useCallback( () => {
		setSelection( { mode: 'exact', startDate: null, endDate: null } );
	}, [] );

	const selectExact = useCallback( ( range ) => {
		setSelection( {
			mode: 'exact',
			startDate: range?.startDate || null,
			endDate: range?.endDate || null,
		} );
	}, [] );

	const reset = useCallback( () => {
		setSelection( DEFAULT_SELECTION );
	}, [] );

	const refetch = useCallback( () => {
		setRefreshKey( ( current ) => current + 1 );
	}, [] );

	return {
		analytics,
		selection,
		selectPreset,
		selectCustom,
		selectExact,
		reset,
		isLoading,
		error,
		refetch,
	};
}
