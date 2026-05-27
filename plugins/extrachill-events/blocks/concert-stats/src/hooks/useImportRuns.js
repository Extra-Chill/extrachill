/**
 * useImportRuns — Manage concert import sources, runs, and actions.
 *
 * Fetches the registered sources and current user's runs, polls while any
 * run is active, and exposes preview + start actions.
 *
 * @package ExtraChillEvents
 */

import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

const POLL_INTERVAL_MS = 5000;
const ACTIVE_STATUSES = [ 'pending', 'running', 'paused' ];

/**
 * Hook returning import sources + runs + actions.
 */
export default function useImportRuns() {
	const [ sources, setSources ] = useState( [] );
	const [ runs, setRuns ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );

	const pollTimerRef = useRef( null );

	const fetchSources = useCallback( () => {
		return apiFetch( { path: '/extrachill/v1/concert-import/sources' } )
			.then( ( response ) => {
				setSources( response.sources || [] );
			} )
			.catch( ( err ) => {
				setError( err.message || 'Failed to load sources.' );
			} );
	}, [] );

	const fetchRuns = useCallback( () => {
		return apiFetch( { path: '/extrachill/v1/concert-import/status' } )
			.then( ( response ) => {
				setRuns( response.runs || [] );
			} )
			.catch( ( err ) => {
				setError( err.message || 'Failed to load runs.' );
			} );
	}, [] );

	const refresh = useCallback( () => {
		setLoading( true );
		return Promise.all( [ fetchSources(), fetchRuns() ] ).finally( () =>
			setLoading( false )
		);
	}, [ fetchSources, fetchRuns ] );

	// Initial load.
	useEffect( () => {
		refresh();
	}, [ refresh ] );

	// Poll while any run is active.
	useEffect( () => {
		const anyActive = runs.some( ( r ) => ACTIVE_STATUSES.includes( r.status ) );

		if ( pollTimerRef.current ) {
			clearInterval( pollTimerRef.current );
			pollTimerRef.current = null;
		}

		if ( anyActive ) {
			pollTimerRef.current = setInterval( () => {
				fetchRuns();
			}, POLL_INTERVAL_MS );
		}

		return () => {
			if ( pollTimerRef.current ) {
				clearInterval( pollTimerRef.current );
				pollTimerRef.current = null;
			}
		};
	}, [ runs, fetchRuns ] );

	const preview = useCallback( ( source, username ) => {
		return apiFetch( {
			path: '/extrachill/v1/concert-import/preview',
			method: 'POST',
			data: { source, username },
		} );
	}, [] );

	const start = useCallback(
		( source, username ) => {
			return apiFetch( {
				path: '/extrachill/v1/concert-import/start',
				method: 'POST',
				data: { source, username },
			} ).then( ( response ) => {
				fetchRuns();
				return response;
			} );
		},
		[ fetchRuns ]
	);

	return {
		sources,
		runs,
		loading,
		error,
		refresh,
		preview,
		start,
	};
}
