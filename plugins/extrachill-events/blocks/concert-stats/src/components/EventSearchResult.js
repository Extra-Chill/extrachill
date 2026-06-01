/**
 * EventSearchResult — Single past-event row in the Add Past Shows tab.
 *
 * Renders date / venue / primary artist / city and a "+ Mark Attended" button
 * (or a disabled "✓ Tracked" label for events already in the user's history).
 *
 * Marking is optimistic: the row flips immediately and reverts on REST error.
 *
 * @package
 */

import { ActionRow, InlineStatus } from '@extrachill/components';

import { formatLongDate } from '../utils/formatDate';
import useMarkAttendance from '../hooks/useMarkAttendance';

const EventSearchResult = ( { event, onMarkedChange } ) => {
	const { mark, isMarking, error } = useMarkAttendance();

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
		if ( isMarked || isMarking ) {
			return;
		}

		// Optimistic flip.
		onMarkedChange( event.post_id, true );

		mark( { eventId: event.post_id } )
			.then( ( response ) => {
				// If somehow the server reports unmarked (e.g. server-side toggle
				// of a previously-marked event), reconcile state.
				if ( response && response.marked === false ) {
					onMarkedChange( event.post_id, false );
				}
			} )
			.catch( () => {
				// Revert optimistic flip; the hook surfaces the error message.
				onMarkedChange( event.post_id, false );
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
					{ formatLongDate( event.event_date ) }
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
					<button
						type="button"
						className="button-2 button-medium"
						disabled
						aria-label="Already tracked"
					>
						✓ Tracked
					</button>
				) : (
					<button
						type="button"
						className="button-1 button-medium"
						onClick={ handleMark }
						disabled={ isMarking }
					>
						+ Mark Attended
					</button>
				) }
				{ error && <InlineStatus tone="error">{ error }</InlineStatus> }
			</div>
		</ActionRow>
	);
};

export default EventSearchResult;
