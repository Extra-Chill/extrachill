/**
 * EventSearchResult — Single past-event row in the Add Past Shows tab.
 *
 * Renders date / venue / primary artist / city and a "+ Mark Attended" button
 * (or a disabled "✓ Tracked" label for events already in the user's history).
 *
 * Marking is optimistic: the row flips immediately and reverts on REST error.
 *
 * @package ExtraChillEvents
 */

import { useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { ActionRow } from '@extrachill/components';

const MONTHS = [ 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec' ];

function formatDate( dateString ) {
	if ( ! dateString ) {
		return '';
	}
	const d = new Date( dateString + 'T00:00:00' );
	if ( Number.isNaN( d.getTime() ) ) {
		return '';
	}
	return `${ MONTHS[ d.getMonth() ] } ${ d.getDate() }, ${ d.getFullYear() }`;
}

const EventSearchResult = ( { event, onMarkedChange } ) => {
	const [ submitting, setSubmitting ] = useState( false );
	const [ error, setError ] = useState( null );

	const isMarked = !! event.is_marked;

	const artistDisplay =
		event.artists && event.artists.length
			? event.artists.map( ( a ) => a.name ).join( ', ' )
			: event.title || '';

	const venueName = event.venue && event.venue.name ? event.venue.name : '';
	const cityName = event.city && event.city.name ? event.city.name : '';

	const venueParts = [];
	if ( venueName ) {
		venueParts.push( venueName );
	}
	if ( cityName ) {
		venueParts.push( cityName );
	}

	const handleMark = () => {
		if ( isMarked || submitting ) {
			return;
		}

		setSubmitting( true );
		setError( null );

		// Optimistic flip.
		onMarkedChange( event.post_id, true );

		apiFetch( {
			path: '/extrachill/v1/concert-tracking/toggle',
			method: 'POST',
			data: { event_id: event.post_id },
		} )
			.then( ( response ) => {
				setSubmitting( false );
				// If somehow the server reports unmarked (e.g. server-side toggle
				// of a previously-marked event), reconcile state.
				if ( response && response.marked === false ) {
					onMarkedChange( event.post_id, false );
					setError( 'Could not mark event.' );
				}
			} )
			.catch( ( err ) => {
				setSubmitting( false );
				onMarkedChange( event.post_id, false );
				setError( ( err && err.message ) || 'Failed to mark event.' );
			} );
	};

	return (
		<ActionRow align="between" className="ec-concert-stats__search-result">
			<a
				href={ event.permalink || '#' }
				className="ec-concert-stats__search-result-link"
				target="_blank"
				rel="noopener noreferrer"
			>
				<span className="ec-concert-stats__search-result-date">
					{ formatDate( event.event_date ) }
				</span>
				<span className="ec-concert-stats__search-result-details">
					<span className="ec-concert-stats__search-result-artist">
						{ artistDisplay }
					</span>
					{ venueParts.length > 0 && (
						<span className="ec-concert-stats__search-result-venue">
							{ venueParts.join( ' \u00b7 ' ) }
						</span>
					) }
				</span>
			</a>

			<div className="ec-concert-stats__search-result-action">
				{ isMarked ? (
					<span
						className="ec-concert-stats__mark-btn ec-concert-stats__mark-btn--tracked"
						aria-label="Already tracked"
					>
						✓ Tracked
					</span>
				) : (
					<button
						type="button"
						className="ec-concert-stats__mark-btn ec-concert-stats__mark-btn--add"
						onClick={ handleMark }
						disabled={ submitting }
					>
						+ Mark Attended
					</button>
				) }
				{ error && (
					<span className="ec-concert-stats__search-result-error" role="alert">
						{ error }
					</span>
				) }
			</div>
		</ActionRow>
	);
};

export default EventSearchResult;
