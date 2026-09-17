/**
 * EventQuickAdd — reusable owner search-and-mark surface.
 *
 * Generalization of the #159 "Add Past Shows" affordance (#837): a search
 * input plus results list that lets the owner find events and mark them
 * inline, scoped to a `period` (see extrachill-users#396):
 *   - period="past"     → powers the Past tab (via the AddPastShows wrapper)
 *   - period="upcoming" → powers the Upcoming tab (see UpcomingTab.js)
 *
 * Behavior:
 *   - Search input (committed on Enter / Search button via SearchBox).
 *   - Empty query: renders an InlineStatus prompt (`emptyPrompt`) asking the
 *     user to search. Backend short-circuits and returns no results. See
 *     extrachill-events#130.
 *   - Results list with a per-row mark button (labels derive from the
 *     event's timing — see EventSearchResult).
 *   - "Load more" pagination, 20 per page (handled in useEventSearch).
 *   - `onMarked` (optional): invoked after a successful mark so the parent
 *     can refetch the tracked list and surface the newly added show without
 *     a full page reload (#159).
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

const DEFAULT_PLACEHOLDERS = {
	past: 'Search past shows by artist, venue, or title…',
	upcoming: 'Search Extra Chill events...',
};

const DEFAULT_EMPTY_PROMPTS = {
	past: "Start typing the name of an artist, venue, or show you've attended.",
	upcoming: "Search for a show you're going to.",
};

const EventQuickAdd = ( {
	period = 'past',
	placeholder,
	emptyPrompt,
	onMarked,
	className = 'ec-concert-stats__event-quick-add',
} ) => {
	const [ query, setQuery ] = useState( '' );

	const { events, total, pages, page, loading, error, loadMore, setMarked } =
		useEventSearch( query, { period } );

	const resolvedPlaceholder =
		placeholder ||
		DEFAULT_PLACEHOLDERS[ period ] ||
		DEFAULT_PLACEHOLDERS.past;
	const resolvedEmptyPrompt =
		emptyPrompt ||
		DEFAULT_EMPTY_PROMPTS[ period ] ||
		DEFAULT_EMPTY_PROMPTS.past;

	// Wrap the search-result optimistic flip so a successful mark also
	// bubbles up to the parent tracked list. The local `setMarked` keeps
	// the search row in its marked state; `onMarked` lets the parent tab
	// refetch the tracked-shows list so the new show appears below (#159).
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
		<Section className={ className }>
			<EventSearchInput
				value={ query }
				onChange={ setQuery }
				placeholder={ resolvedPlaceholder }
			/>

			{ isEmptyQuery && (
				<InlineStatus tone="info">{ resolvedEmptyPrompt }</InlineStatus>
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

export default EventQuickAdd;
