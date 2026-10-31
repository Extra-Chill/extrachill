/**
 * EventSearchResult — Single event row in the quick-add search results.
 *
 * #159: originally the Past tab's "add a past show" row. #837 generalizes
 * the same row to the Upcoming tab's quick-add search, so button labels
 * derive from the event's canonical `timing` (matching the single-event
 * attendance button in extrachill-users):
 *   - upcoming → "I'm going" / "✓ Going"
 *   - ongoing  → "Check In" / "✓ Checked In"
 *   - past     → "+ Mark Attended" / "✓ Tracked"
 *
 * Renders date / venue / primary artist / city and a mark button (or a
 * disabled check-marked label for events already tracked).
 *
 * Marking is optimistic: the row flips immediately and reverts on REST error.
 *
 * @package
 */

/**
 * External dependencies
 */
import { ActionRow, InlineStatus } from '@extrachill/components';

/**
 * Internal dependencies
 */
import { formatLongDate } from '../utils/formatDate';
import useMarkAttendance from '../hooks/useMarkAttendance';

const LABEL_SETS = {
	upcoming: {
		action: "I'm going",
		marked: '✓ Going',
		markedAria: 'Already going',
	},
	ongoing: {
		action: 'Check In',
		marked: '✓ Checked In',
		markedAria: 'Already checked in',
	},
	past: {
		action: '+ Mark Attended',
		marked: '✓ Tracked',
		markedAria: 'Already tracked',
	},
};

const EventSearchResult = ( { event, onMarkedChange } ) => {
	const { mark, isMarking, error } = useMarkAttendance();

	const isMarked = !! event.is_marked;
	const labels = LABEL_SETS[ event.timing ] || LABEL_SETS.past;

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
						aria-label={ labels.markedAria }
					>
						{ labels.marked }
					</button>
				) : (
					<button
						type="button"
						className="button-1 button-medium"
						onClick={ handleMark }
						disabled={ isMarking }
					>
						{ labels.action }
					</button>
				) }
				{ error && <InlineStatus tone="error">{ error }</InlineStatus> }
			</div>
		</ActionRow>
	);
};

export default EventSearchResult;
