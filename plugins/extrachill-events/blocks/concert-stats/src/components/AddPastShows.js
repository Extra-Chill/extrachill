/**
 * AddPastShows — "Add Past Shows" tab content for the concert-stats block.
 *
 * Lets a logged-in user retroactively mark past events they attended.
 *
 * Behavior:
 *   - Search input (committed on Enter / Search button via SearchBox).
 *   - Empty query: backend returns the ~most-recent past events as suggestions.
 *   - Results list with "+ Mark Attended" per row, optimistic state flip.
 *   - "Load more" pagination, 20 per page (handled in useEventSearch).
 *
 * @package ExtraChillEvents
 */

import { useState } from '@wordpress/element';
import { ActionRow, InlineStatus, Section } from '@extrachill/components';
import EventSearchInput from './EventSearchInput';
import EventSearchResult from './EventSearchResult';
import useEventSearch from '../hooks/useEventSearch';

const AddPastShows = () => {
	const [ query, setQuery ] = useState( '' );

	const { events, total, pages, page, loading, error, loadMore, setMarked } =
		useEventSearch( query );

	const isEmptyQuery = query.trim() === '';

	return (
		<Section className="ec-concert-stats__add-past">
			<EventSearchInput value={ query } onChange={ setQuery } />

			{ isEmptyQuery && (
				<p className="ec-concert-stats__search-hint">
					Search for shows you&rsquo;ve attended to add them to your history.
				</p>
			) }

			{ error && (
				<InlineStatus tone="error">{ error }</InlineStatus>
			) }

			{ ! loading && ! error && events.length === 0 && ! isEmptyQuery && (
				<InlineStatus tone="info">
					No past shows match &ldquo;{ query }&rdquo;. Try a different artist or venue name.
				</InlineStatus>
			) }

			{ events.length > 0 && (
				<Section className="ec-concert-stats__search-results">
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
				</Section>
			) }

			{ loading && (
				<InlineStatus tone="info">Searching…</InlineStatus>
			) }

			{ ! loading && page < pages && (
				<ActionRow align="center">
					<button
						type="button"
						className="ec-concert-stats__load-more-btn"
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
