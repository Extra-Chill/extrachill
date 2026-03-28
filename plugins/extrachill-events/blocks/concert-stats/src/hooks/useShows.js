/**
 * useShows — Fetch paginated concert history from REST API.
 *
 * @package ExtraChillEvents
 */

import { useState, useEffect, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

/**
 * @param {number} userId
 * @param {Object} filters - { period, year, page, perPage }
 */
export default function useShows( userId, filters = {} ) {
	const [ shows, setShows ] = useState( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ pages, setPages ] = useState( 0 );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );

	const { period = 'all', year = 0, page = 1, perPage = 20 } = filters;

	const fetchShows = useCallback( () => {
		if ( ! userId ) {
			setLoading( false );
			return;
		}

		setLoading( true );
		setError( null );

		let path = `/extrachill/v1/concert-tracking/user/${ userId }/shows?period=${ period }&page=${ page }&per_page=${ perPage }`;
		if ( year ) {
			path += `&year=${ year }`;
		}

		apiFetch( { path } )
			.then( ( response ) => {
				if ( page > 1 && period === 'past' ) {
					// Append for load-more behavior on past shows.
					setShows( ( prev ) => [ ...prev, ...( response.shows || [] ) ] );
				} else {
					setShows( response.shows || [] );
				}
				setTotal( response.total || 0 );
				setPages( response.pages || 0 );
				setLoading( false );
			} )
			.catch( ( err ) => {
				setError( err.message || 'Failed to load shows.' );
				setLoading( false );
			} );
	}, [ userId, period, year, page, perPage ] );

	useEffect( () => {
		fetchShows();
	}, [ fetchShows ] );

	return { shows, total, pages, loading, error, refetch: fetchShows };
}
