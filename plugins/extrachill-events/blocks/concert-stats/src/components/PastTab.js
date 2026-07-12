/**
 * PastTab — combined "Past" tab content for the concert-stats block.
 *
 * As of #159 the standalone "Add Past Shows" tab is folded into the
 * "Past" tab. This component composes:
 *   - (owner only) the AddPastShows search-and-mark affordance, so a
 *     user can find and mark past shows they attended from the same
 *     surface where they review their tracked history.
 *   - the read-only ShowList of tracked past shows.
 *
 * Owner-only gating: non-owners viewing someone's Past tab see only the
 * read-only list — the add affordance is omitted entirely.
 *
 * Newly-added shows: marking a show in AddPastShows triggers a refetch
 * of the Past list via a bumped `refreshKey`, so the new show appears
 * below without a full page reload (#159). ShowList re-runs its fetch
 * whenever its `key` changes.
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
import AddPastShows from './AddPastShows';
import ShowList from './ShowList';

const PastTab = ( { userId, year, isOwn } ) => {
	// Bumping this remounts ShowList, forcing a fresh fetch so a
	// just-marked show shows up in the tracked-past list.
	const [ refreshKey, setRefreshKey ] = useState( 0 );

	const handleMarked = useCallback( () => {
		setRefreshKey( ( k ) => k + 1 );
	}, [] );

	return (
		<div className="ec-concert-stats__past-tab">
			{ isOwn && <AddPastShows onMarked={ handleMarked } /> }
			<ShowList
				key={ refreshKey }
				userId={ userId }
				period="past"
				year={ year }
			/>
		</div>
	);
};

export default PastTab;
