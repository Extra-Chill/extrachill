/**
 * useEventSearch — Debounced REST search of events for marking.
 *
 * Drives the owner-only quick-add search affordances in the concert-stats
 * block (#159 folded "Add Past Shows" into the Past tab; #837 generalizes
 * the same surface to the Upcoming tab).
 *
 * Behavior:
 *   - Debounces query changes by 300ms before firing a request.
 *   - Cancels in-flight requests when the query changes (AbortController).
 *   - Accumulates results across pages on loadMore() (infinite-scroll style).
 *   - Resets accumulated results whenever the query or period changes.
 *   - Empty query is allowed — backend returns no results and the UI shows
 *     a prompt instead (see extrachill-events#130).
 *   - `options.period` scopes the search: 'past' (default), 'upcoming', or
 *     'all' (see extrachill-users#396). The existing `( query )` call
 *     signature keeps working and defaults to 'past'.
 *
 * @package
 */

/**
 * WordPress dependencies
 */
import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

const PER_PAGE = 20;
const DEBOUNCE_MS = 300;
const VALID_PERIODS = [ 'past', 'upcoming', 'all' ];

export default function useEventSearch( query, options = {} ) {
	const { period: rawPeriod = 'past' } = options;
	const period = VALID_PERIODS.includes( rawPeriod ) ? rawPeriod : 'past';

	const [ events, setEvents ] = useState( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ pages, setPages ] = useState( 0 );
	const [ page, setPage ] = useState( 1 );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( null );

	const abortRef = useRef( null );
	const debounceRef = useRef( null );

	const runFetch = useCallback( ( q, p, searchPeriod ) => {
		// Cancel any in-flight request.
		if ( abortRef.current ) {
			abortRef.current.abort();
		}
		const controller = new AbortController();
		abortRef.current = controller;

		setLoading( true );
		setError( null );

		const params = new URLSearchParams( {
			query: q || '',
			period: searchPeriod,
			page: String( p ),
			per_page: String( PER_PAGE ),
		} );

		apiFetch( {
			path: `/extrachill/v1/concert-tracking/search?${ params.toString() }`,
			signal: controller.signal,
		} )
			.then( ( response ) => {
				if ( controller.signal.aborted ) {
					return;
				}
				const incoming = response.events || [];
				setEvents( ( prev ) =>
					p === 1 ? incoming : [ ...prev, ...incoming ]
				);
				setTotal( response.total || 0 );
				setPages( response.pages || 0 );
				setLoading( false );
			} )
			.catch( ( err ) => {
				if ( err && err.name === 'AbortError' ) {
					return;
				}
				setError(
					( err && err.message ) || 'Failed to search events.'
				);
				setLoading( false );
			} );
	}, [] );

	// Debounced query/period effect: resets to page 1 and refetches.
	useEffect( () => {
		if ( debounceRef.current ) {
			clearTimeout( debounceRef.current );
		}
		debounceRef.current = setTimeout( () => {
			setPage( 1 );
			runFetch( query, 1, period );
		}, DEBOUNCE_MS );

		return () => {
			if ( debounceRef.current ) {
				clearTimeout( debounceRef.current );
			}
		};
	}, [ query, period, runFetch ] );

	// Clear accumulated rows the moment the period changes so stale
	// past rows never render under an upcoming search (or vice versa)
	// during the debounce window.
	const prevPeriodRef = useRef( period );
	useEffect( () => {
		if ( prevPeriodRef.current !== period ) {
			prevPeriodRef.current = period;
			setEvents( [] );
			setTotal( 0 );
			setPages( 0 );
			setPage( 1 );
		}
	}, [ period ] );

	const loadMore = useCallback( () => {
		if ( loading ) {
			return;
		}
		if ( page >= pages ) {
			return;
		}
		const next = page + 1;
		setPage( next );
		runFetch( query, next, period );
	}, [ loading, page, pages, query, period, runFetch ] );

	/**
	 * Mark a single event as locally-tracked without a refetch.
	 * Used for optimistic UI updates.
	 *
	 * @param {number}  postId Event post ID.
	 * @param {boolean} marked New is_marked state.
	 */
	const setMarked = useCallback( ( postId, marked ) => {
		setEvents( ( prev ) =>
			prev.map( ( ev ) =>
				ev.post_id === postId ? { ...ev, is_marked: marked } : ev
			)
		);
	}, [] );

	return {
		events,
		total,
		pages,
		page,
		loading,
		error,
		loadMore,
		setMarked,
	};
}
