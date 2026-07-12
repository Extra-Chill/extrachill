/**
 * ShowList — Paginated list of show cards with load-more.
 *
 * @package
 */

/**
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';

/**
 * External dependencies
 */
import { ActionRow, InlineStatus } from '@extrachill/components';

/**
 * Internal dependencies
 */
import ShowCard from './ShowCard';
import useShows from '../hooks/useShows';

const ShowList = ( { userId, period, year, eventsUrl } ) => {
	const [ page, setPage ] = useState( 1 );

	const { shows, total, pages, loading, error } = useShows( userId, {
		period,
		year,
		page,
		perPage: 20,
	} );

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
				No past shows tracked yet. Use the search above to find shows
				you&rsquo;ve attended, or Import from setlist.fm/phish.net.
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
						onClick={ () => setPage( ( p ) => p + 1 ) }
						type="button"
					>
						Load More ({ total - shows.length } remaining)
					</button>
				</ActionRow>
			) }
		</div>
	);
};

export default ShowList;
