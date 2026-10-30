/**
 * ShowList — Paginated list of show cards with load-more.
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
import ShowCard from './ShowCard';
import useShows from '../hooks/useShows';
import useMyRatings from '../hooks/useMyRatings';

const ShowList = ( {
	userId,
	period,
	year,
	eventsUrl,
	isOwn = true,
	enabled = true,
} ) => {
	const { shows, total, pages, page, loading, error, loadMore } = useShows(
		userId,
		{
			period,
			year,
			perPage: 20,
			enabled,
			queryScope: isOwn ? 'owner' : 'public',
		}
	);

	// Owners rate their past shows inline (data-machine-events ratings).
	const canRate = isOwn && 'past' === period;
	const pastIds = canRate
		? shows
				.filter( ( show ) => 'past' === show.timing )
				.map( ( show ) => show.event_id )
		: [];
	const { ratings, errors, rate } = useMyRatings( pastIds, canRate );
	const unrated = pastIds.filter(
		( id ) => ! ratings[ String( id ) ]
	).length;

	if ( error ) {
		return (
			<InlineStatus tone="error">
				{ error.message || 'Failed to load shows.' }
			</InlineStatus>
		);
	}

	if ( ! loading && shows.length === 0 ) {
		if ( period === 'upcoming' ) {
			// #837: the owner view now has the quick-add search directly
			// above this list, so the empty state points there first and
			// keeps the events calendar as the browse fallback.
			return (
				<InlineStatus tone="info">
					No upcoming shows you&rsquo;re going to yet. Search above to
					find one, or browse the{ ' ' }
					{ eventsUrl ? (
						<a href={ eventsUrl }>events calendar</a>
					) : (
						'events calendar'
					) }
					.
				</InlineStatus>
			);
		}
		return (
			<InlineStatus tone="info">
				{ isOwn
					? "No past shows tracked yet. Use the search above to find shows you've attended, or Import from setlist.fm/phish.net."
					: 'No past shows tracked yet.' }
			</InlineStatus>
		);
	}

	return (
		<div className="ec-concert-stats__show-list">
			{ canRate && unrated > 0 && (
				<InlineStatus tone="info">
					{ unrated === pastIds.length
						? 'How were they? Tap the stars to rate the shows you were at.'
						: `${ unrated } show${
								unrated === 1 ? '' : 's'
						  } below still need${
								unrated === 1 ? 's' : ''
						  } your rating.` }
				</InlineStatus>
			) }
			{ shows.map( ( show ) => (
				<ShowCard
					key={ show.event_id }
					show={ show }
					isOwn={ isOwn }
					rating={
						canRate && pastIds.includes( show.event_id )
							? {
									value: ratings[ String( show.event_id ) ],
									error: errors[ String( show.event_id ) ],
									onRate: rate,
							  }
							: null
					}
				/>
			) ) }

			{ loading && <InlineStatus tone="info">Loading...</InlineStatus> }

			{ ! loading && page < pages && (
				<ActionRow align="center">
					<button
						className="button-2 button-medium"
						onClick={ loadMore }
						type="button"
					>
						Load More ({ Math.max( 0, total - shows.length ) }{ ' ' }
						remaining)
					</button>
				</ActionRow>
			) }
		</div>
	);
};

export default ShowList;
