/**
 * ShowList — Paginated list of show cards with load-more.
 *
 * @package
 */

/**
 * WordPress dependencies
 */
import { ActionRow, InlineStatus } from '@extrachill/components';

/**
 * Internal dependencies
 */
import ShowCard from './ShowCard';
import useShows from '../hooks/useShows';

const ShowList = ( { userId, period, year, eventsUrl, isOwn = true } ) => {
	const { shows, total, pages, page, loading, error, loadMore } = useShows(
		userId,
		{
			period,
			year,
			perPage: 20,
			queryScope: isOwn ? 'owner' : 'public',
		}
	);

	if ( error ) {
		return <InlineStatus tone="error">{ error }</InlineStatus>;
	}

	if ( ! loading && shows.length === 0 ) {
		if ( period === 'upcoming' ) {
			return (
				<InlineStatus tone="info">
					No upcoming shows you&rsquo;ve marked yet. Find a show on
					the{ ' ' }
					{ eventsUrl ? (
						<a href={ eventsUrl }>events calendar</a>
					) : (
						'events calendar'
					) }{ ' ' }
					and mark it &lsquo;Going&rsquo;.
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
			{ shows.map( ( show ) => (
				<ShowCard key={ show.event_id } show={ show } />
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
