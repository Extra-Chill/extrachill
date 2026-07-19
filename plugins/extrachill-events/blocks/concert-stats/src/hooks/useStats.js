/**
 * useStats — Fetch aggregate concert stats from REST API.
 *
 * @package
 */

/**
 * WordPress dependencies
 */
import { useState, useEffect, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

/**
 * @param {number} userId
 * @param {Object} filters - { year, dateTo }
 */
export default function useStats( userId, filters = {} ) {
	const [ stats, setStats ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );

	const { year = 0, dateTo = '' } = filters;

	const fetchStats = useCallback( () => {
		if ( ! userId ) {
			setLoading( false );
			return;
		}

		setLoading( true );
		setError( null );

		const params = new URLSearchParams();
		if ( year ) {
			params.set( 'year', year );
		}
		if ( dateTo ) {
			params.set( 'date_to', dateTo );
		}
		const query = params.toString();
		const path = `/extrachill/v1/concert-tracking/user/${ userId }/stats${
			query ? `?${ query }` : ''
		}`;

		apiFetch( { path } )
			.then( ( response ) => {
				setStats( response );
				setLoading( false );
			} )
			.catch( ( err ) => {
				setError( err.message || 'Failed to load stats.' );
				setLoading( false );
			} );
	}, [ userId, year, dateTo ] );

	useEffect( () => {
		fetchStats();
	}, [ fetchStats ] );

	return { stats, loading, error, refetch: fetchStats };
}
