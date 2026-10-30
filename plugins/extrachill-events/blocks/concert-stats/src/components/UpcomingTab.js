/**
 * UpcomingTab — combined "Upcoming" tab content for the concert-stats block.
 *
 * #837: mirrors PastTab (#159) for the Upcoming tab. Composes:
 *   - (owner only) the EventQuickAdd search-and-mark affordance scoped to
 *     period="upcoming", so a user can find an upcoming event and mark
 *     "I'm going" from the same surface where they review their itinerary
 *     instead of navigating to each event page.
 *   - the read-only ShowList of tracked upcoming shows.
 *
 * Owner-only gating: the Upcoming tab itself only renders for owners (the
 * tab strip omits it for public viewers — see view.js), and the quick-add
 * surface is additionally gated on isOwn the same way PastTab gates
 * AddPastShows.
 *
 * Newly-added shows: marking a show in EventQuickAdd triggers a refetch of
 * the Upcoming list via a bumped `refreshKey` (#159 pattern), so the new
 * show appears below without a full page reload. ShowList re-runs its
 * fetch whenever its `key` changes.
 *
 * @package
 */

/**
 * WordPress dependencies
 */
import { useState, useCallback } from '@wordpress/element';

/**
 * Internal dependencies
 */
import EventQuickAdd from './EventQuickAdd';
import ShowList from './ShowList';

const UpcomingTab = ( { userId, year, eventsUrl, isOwn, enabled = true } ) => {
	// Bumping this remounts ShowList, forcing a fresh fetch so a
	// just-marked show shows up in the tracked-upcoming list.
	const [ refreshKey, setRefreshKey ] = useState( 0 );

	const handleMarked = useCallback( () => {
		setRefreshKey( ( k ) => k + 1 );
	}, [] );

	return (
		<div className="ec-concert-stats__upcoming-tab">
			{ isOwn && (
				<EventQuickAdd
					period="upcoming"
					placeholder="Search Extra Chill events..."
					emptyPrompt="Search for a show you're going to."
					onMarked={ handleMarked }
				/>
			) }
			<ShowList
				key={ refreshKey }
				userId={ userId }
				period="upcoming"
				year={ year }
				eventsUrl={ eventsUrl }
				isOwn={ isOwn }
				enabled={ enabled }
			/>
		</div>
	);
};

export default UpcomingTab;
