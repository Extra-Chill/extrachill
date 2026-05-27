/**
 * useEventSearch — Debounced REST search of past events for marking.
 *
 * Drives the "Add Past Shows" tab in the concert-stats block.
 *
 * Behavior:
 *   - Debounces query changes by 300ms before firing a request.
 *   - Cancels in-flight requests when the query changes (AbortController).
 *   - Accumulates results across pages on loadMore() (infinite-scroll style).
 *   - Resets accumulated results whenever the query changes.
 *   - Empty query is allowed — backend returns recent past events as suggestions.
 *
 * @package ExtraChillEvents
 */

import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

const PER_PAGE = 20;
const DEBOUNCE_MS = 300;

export default function useEventSearch( query ) {
	const [ events, setEvents ] = useState( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ pages, setPages ] = useState( 0 );
	const [ page, setPage ] = useState( 1 );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( null );

	const abortRef = useRef( null );
	const debounceRef = useRef( null );

	const runFetch = useCallback( ( q, p ) => {
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
				setEvents( ( prev ) => ( p === 1 ? incoming : [ ...prev, ...incoming ] ) );
				setTotal( response.total || 0 );
				setPages( response.pages || 0 );
				setLoading( false );
			} )
			.catch( ( err ) => {
				if ( err && err.name === 'AbortError' ) {
					return;
				}
				setError( ( err && err.message ) || 'Failed to search events.' );
				setLoading( false );
			} );
	}, [] );

	// Debounced query effect: resets to page 1 and refetches.
	useEffect( () => {
		if ( debounceRef.current ) {
			clearTimeout( debounceRef.current );
		}
		debounceRef.current = setTimeout( () => {
			setPage( 1 );
			runFetch( query, 1 );
		}, DEBOUNCE_MS );

		return () => {
			if ( debounceRef.current ) {
				clearTimeout( debounceRef.current );
			}
		};
	}, [ query, runFetch ] );

	const loadMore = useCallback( () => {
		if ( loading ) {
			return;
		}
		if ( page >= pages ) {
			return;
		}
		const next = page + 1;
		setPage( next );
		runFetch( query, next );
	}, [ loading, page, pages, query, runFetch ] );

	/**
	 * Mark a single event as locally-tracked without a refetch.
	 * Used for optimistic UI updates.
	 *
	 * @param {number} postId   Event post ID.
	 * @param {boolean} marked  New is_marked state.
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
