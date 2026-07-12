/**
 * AddPastShows — owner-only "add a past show" affordance.
 *
 * Lets a logged-in user retroactively mark past events they attended.
 * As of #159 this is no longer a standalone tab — it renders inline at
 * the top of the "Past" tab (see PastTab.js), above the read-only
 * tracked-shows list.
 *
 * Behavior:
 *   - Search input (committed on Enter / Search button via SearchBox).
 *   - Empty query: renders an InlineStatus prompt asking the user to search.
 *     Backend short-circuits and returns no results. See extrachill-events#130.
 *   - Results list with "+ Mark Attended" per row, optimistic state flip.
 *   - "Load more" pagination, 20 per page (handled in useEventSearch).
 *   - `onMarked` (optional): invoked after a successful mark so the
 *     parent (PastTab) can refetch the Past list and surface the newly
 *     added show without a full page reload (#159).
 *
 * @package
 */

/**
 * WordPress dependencies
 */
import { useState, useCallback } from '@wordpress/element';

/**
 * External dependencies
 */
import { ActionRow, InlineStatus, Section } from '@extrachill/components';

/**
 * Internal dependencies
 */
import EventSearchInput from './EventSearchInput';
import EventSearchResult from './EventSearchResult';
import useEventSearch from '../hooks/useEventSearch';

const AddPastShows = ( { onMarked } ) => {
	const [ query, setQuery ] = useState( '' );

	const { events, total, pages, page, loading, error, loadMore, setMarked } =
		useEventSearch( query );

	// Wrap the search-result optimistic flip so a successful mark also
	// bubbles up to the parent Past list. The local `setMarked` keeps the
	// search row in its "✓ Tracked" state; `onMarked` lets PastTab refetch
	// the tracked-shows list so the new show appears below (#159).
	const handleMarked = useCallback(
		( postId, marked ) => {
			setMarked( postId, marked );
			if ( marked && typeof onMarked === 'function' ) {
				onMarked( postId );
			}
		},
		[ setMarked, onMarked ]
	);

	const isEmptyQuery = query.trim() === '';

	return (
		<Section className="ec-concert-stats__add-past">
			<EventSearchInput value={ query } onChange={ setQuery } />

			{ isEmptyQuery && (
				<InlineStatus tone="info">
					Start typing the name of an artist, venue, or show
					you&rsquo;ve attended.
				</InlineStatus>
			) }

			{ error && <InlineStatus tone="error">{ error }</InlineStatus> }

			{ ! loading && ! error && events.length === 0 && ! isEmptyQuery && (
				<InlineStatus tone="info">
					No matches for &ldquo;{ query }&rdquo;. Try a different
					artist, venue, or city.
				</InlineStatus>
			) }

			{ events.length > 0 && (
				<Section className="ec-concert-stats__search-results">
					{ events.map( ( ev ) => (
						<EventSearchResult
							key={ ev.post_id }
							event={ ev }
							onMarkedChange={ handleMarked }
						/>
					) ) }
				</Section>
			) }

			{ loading && <InlineStatus tone="info">Searching…</InlineStatus> }

			{ ! loading && page < pages && (
				<ActionRow align="center">
					<button
						type="button"
						className="button-2 button-medium"
						onClick={ loadMore }
					>
						Load more ({ total - events.length } remaining)
					</button>
				</ActionRow>
			) }
		</Section>
	);
};

export default AddPastShows;
