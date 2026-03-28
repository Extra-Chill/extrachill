/**
 * useStats — Fetch aggregate concert stats from REST API.
 *
 * @package ExtraChillEvents
 */

import { useState, useEffect, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

/**
 * @param {number} userId
 * @param {Object} filters - { year }
 */
export default function useStats( userId, filters = {} ) {
	const [ stats, setStats ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );

	const { year = 0 } = filters;

	const fetchStats = useCallback( () => {
		if ( ! userId ) {
			setLoading( false );
			return;
		}

		setLoading( true );
		setError( null );

		let path = `/extrachill/v1/concert-tracking/user/${ userId }/stats`;
		if ( year ) {
			path += `?year=${ year }`;
		}

		apiFetch( { path } )
			.then( ( response ) => {
				setStats( response );
				setLoading( false );
			} )
			.catch( ( err ) => {
				setError( err.message || 'Failed to load stats.' );
				setLoading( false );
			} );
	}, [ userId, year ] );

	useEffect( () => {
		fetchStats();
	}, [ fetchStats ] );

	return { stats, loading, error, refetch: fetchStats };
}
