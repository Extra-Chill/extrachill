/**
 * useShows — Fetch paginated concert history from REST API.
 *
 * @package
 */

/**
 * WordPress dependencies
 */
import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

const emptyResult = ( queryKey ) => ( {
	queryKey,
	shows: [],
	total: 0,
	pages: 0,
	page: 1,
	loading: true,
	error: null,
} );

const dedupeShows = ( shows ) => [
	...new Map( shows.map( ( show ) => [ show.event_id, show ] ) ).values(),
];

/**
 * @param {number} userId
 * @param {Object} filters - { period, year, perPage, enabled, queryScope }
 */
export default function useShows( userId, filters = {} ) {
	const {
		period = 'all',
		year = 0,
		perPage = 20,
		enabled = true,
		queryScope = 'default',
	} = filters;
	const queryKey = [
		userId,
		queryScope,
		period,
		year,
		perPage,
		enabled,
	].join( ':' );
	const activeQueryKey = useRef( queryKey );
	activeQueryKey.current = queryKey;
	const abortRef = useRef( null );
	const [ result, setResult ] = useState( () => emptyResult( queryKey ) );

	const fetchShows = useCallback(
		( requestedPage = 1 ) => {
			if ( abortRef.current ) {
				abortRef.current.abort();
			}

			if ( ! userId || ! enabled ) {
				setResult( {
					...emptyResult( queryKey ),
					loading: false,
				} );
				return;
			}

			const controller = new AbortController();
			abortRef.current = controller;
			setResult( ( current ) => ( {
				...( current.queryKey === queryKey
					? current
					: emptyResult( queryKey ) ),
				loading: true,
				error: null,
			} ) );

			const requestPage = ( page ) => {
				let path = `/extrachill/v1/concert-tracking/user/${ userId }/shows?period=${ period }&page=${ page }&per_page=${ perPage }`;
				if ( year ) {
					path += `&year=${ year }`;
				}
				return apiFetch( { path, signal: controller.signal } );
			};

			requestPage( requestedPage )
				.then( async ( response ) => {
					const responsePage =
						Number( response.page ) || requestedPage;
					if ( requestedPage > 1 && responsePage !== requestedPage ) {
						return requestPage( 1 );
					}
					return response;
				} )
				.then( ( response ) => {
					if (
						controller.signal.aborted ||
						activeQueryKey.current !== queryKey
					) {
						return;
					}

					const responsePage = Number( response.page ) || 1;
					const incoming = response.shows || [];
					setResult( ( current ) => {
						if ( current.queryKey !== queryKey ) {
							return current;
						}
						return {
							queryKey,
							shows: dedupeShows(
								responsePage > 1
									? [ ...current.shows, ...incoming ]
									: incoming
							),
							total: response.total || 0,
							pages: response.pages || 0,
							page: responsePage,
							loading: false,
							error: null,
						};
					} );
				} )
				.catch( ( err ) => {
					if (
						( err && err.name === 'AbortError' ) ||
						controller.signal.aborted ||
						activeQueryKey.current !== queryKey
					) {
						return;
					}
					setResult( ( current ) => ( {
						...current,
						loading: false,
						error: err,
					} ) );
				} );
		},
		[ userId, period, year, perPage, enabled, queryKey ]
	);

	useEffect( () => {
		setResult( emptyResult( queryKey ) );
		fetchShows( 1 );
		return () => {
			if ( abortRef.current ) {
				abortRef.current.abort();
			}
		};
	}, [ fetchShows, queryKey ] );

	const activeResult =
		result.queryKey === queryKey ? result : emptyResult( queryKey );
	const loadMore = useCallback( () => {
		if ( activeResult.loading || activeResult.page >= activeResult.pages ) {
			return;
		}
		fetchShows( activeResult.page + 1 );
	}, [
		activeResult.loading,
		activeResult.page,
		activeResult.pages,
		fetchShows,
	] );

	return {
		...activeResult,
		loadMore,
		refetch: () => fetchShows( 1 ),
	};
}
