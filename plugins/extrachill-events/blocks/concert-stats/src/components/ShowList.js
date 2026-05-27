/**
 * ShowList — Paginated list of show cards with load-more.
 *
 * @package ExtraChillEvents
 */

import { useState } from '@wordpress/element';
import { ActionRow, InlineStatus } from '@extrachill/components';
import ShowCard from './ShowCard';
import useShows from '../hooks/useShows';

const ShowList = ( { userId, period, year } ) => {
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
		return (
			<InlineStatus tone="info">
				{ period === 'upcoming'
					? 'No upcoming shows marked yet. Browse events and mark shows as "Going"!'
					: 'No past shows tracked yet.' }
			</InlineStatus>
		);
	}

	return (
		<div className="ec-concert-stats__show-list">
			{ shows.map( ( show ) => (
				<ShowCard key={ show.event_id } show={ show } />
			) ) }

			{ loading && (
				<InlineStatus tone="info">Loading...</InlineStatus>
			) }

			{ ! loading && page < pages && (
				<ActionRow align="center">
					<button
						className="ec-concert-stats__load-more-btn"
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
