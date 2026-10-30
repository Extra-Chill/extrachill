/**
 * useMyRatings — the viewer's own private show ratings for a list of events.
 *
 * Reads extrachill/get-my-show-ratings (batched, max 100 IDs per request)
 * and exposes `rate( eventId, rating )`, which writes through
 * extrachill/rate-show (0 clears) and updates local state optimistically.
 *
 * @package
 */

/**
 * WordPress dependencies
 */
import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

const ABILITY_BASE = '/wp-abilities/v1/abilities/extrachill';
const BATCH = 100;

const readPath = ( ids ) =>
	`${ ABILITY_BASE }/get-my-show-ratings/run?` +
	ids.map( ( id ) => `input[event_ids][]=${ id }` ).join( '&' );

/**
 * @param {number[]} eventIds Event IDs on screen.
 * @param {boolean}  enabled  Only the owner of a My Shows page rates shows.
 */
export default function useMyRatings( eventIds, enabled ) {
	const [ ratings, setRatings ] = useState( {} );
	const [ errors, setErrors ] = useState( {} );
	const requested = useRef( new Set() );
	const idsKey = eventIds.join( ',' );

	useEffect( () => {
		if ( ! enabled ) {
			return;
		}
		const pending = eventIds.filter(
			( id ) => id && ! requested.current.has( id )
		);
		for ( let i = 0; i < pending.length; i += BATCH ) {
			const ids = pending.slice( i, i + BATCH );
			ids.forEach( ( id ) => requested.current.add( id ) );
			Promise.resolve( apiFetch( { path: readPath( ids ) } ) )
				.then( ( response ) => {
					const found = ( response && response.ratings ) || {};
					setRatings( ( prev ) => {
						const next = { ...prev };
						Object.keys( found ).forEach( ( key ) => {
							if ( undefined === next[ key ] ) {
								next[ key ] = Number( found[ key ] );
							}
						} );
						return next;
					} );
				} )
				.catch( () => {
					// Unknown ratings render as unrated; rating still works.
				} );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ idsKey, enabled ] );

	const rate = useCallback( ( eventId, rating ) => {
		const key = String( eventId );
		let previous;
		setRatings( ( prev ) => {
			previous = prev[ key ];
			return { ...prev, [ key ]: rating };
		} );
		setErrors( ( prev ) => ( { ...prev, [ key ]: null } ) );

		return Promise.resolve(
			apiFetch( {
				path: `${ ABILITY_BASE }/rate-show/run`,
				method: 'POST',
				data: { input: { event_id: eventId, rating } },
			} )
		).catch( ( error ) => {
			setRatings( ( prev ) => ( { ...prev, [ key ]: previous } ) );
			setErrors( ( prev ) => ( {
				...prev,
				[ key ]:
					( error && error.message ) || 'Could not save your rating.',
			} ) );
		} );
	}, [] );

	return { ratings, errors, rate };
}
