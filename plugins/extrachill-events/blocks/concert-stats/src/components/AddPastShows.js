/**
 * AddPastShows — "Add Past Shows" tab content for the concert-stats block.
 *
 * Lets a logged-in user retroactively mark past events they attended.
 *
 * Behavior:
 *   - Search input (debounced in the hook).
 *   - Empty query: backend returns the ~most-recent past events as suggestions.
 *   - Results list with "+ Mark Attended" per row, optimistic state flip.
 *   - "Load more" pagination, 20 per page (handled in useEventSearch).
 *
 * @package ExtraChillEvents
 */

import { useState } from '@wordpress/element';
import EventSearchInput from './EventSearchInput';
import EventSearchResult from './EventSearchResult';
import useEventSearch from '../hooks/useEventSearch';

const AddPastShows = () => {
	const [ query, setQuery ] = useState( '' );

	const { events, total, pages, page, loading, error, loadMore, setMarked } =
		useEventSearch( query );

	const isEmptyQuery = query.trim() === '';

	return (
		<div className="ec-concert-stats__add-past">
			<EventSearchInput value={ query } onChange={ setQuery } />

			{ isEmptyQuery && (
				<p className="ec-concert-stats__search-hint">
					Search for shows you&rsquo;ve attended to add them to your history.
				</p>
			) }

			{ error && (
				<div className="ec-concert-stats__error">{ error }</div>
			) }

			{ ! loading && ! error && events.length === 0 && ! isEmptyQuery && (
				<div className="ec-concert-stats__empty-tab">
					No past shows match &ldquo;{ query }&rdquo;. Try a different artist or venue name.
				</div>
			) }

			{ events.length > 0 && (
				<div className="ec-concert-stats__search-results">
					{ isEmptyQuery && (
						<p className="ec-concert-stats__search-results-label">
							Recent past shows
						</p>
					) }
					{ events.map( ( ev ) => (
						<EventSearchResult
							key={ ev.post_id }
							event={ ev }
							onMarkedChange={ setMarked }
						/>
					) ) }
				</div>
			) }

			{ loading && (
				<div className="ec-concert-stats__loading-more">Searching…</div>
			) }

			{ ! loading && page < pages && (
				<div className="ec-concert-stats__load-more">
					<button
						type="button"
						className="ec-concert-stats__load-more-btn"
						onClick={ loadMore }
					>
						Load more ({ total - events.length } remaining)
					</button>
				</div>
			) }
		</div>
	);
};

export default AddPastShows;
