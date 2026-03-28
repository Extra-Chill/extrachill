/**
 * ShowList — Paginated list of show cards with load-more.
 *
 * @package ExtraChillEvents
 */

import { useState } from '@wordpress/element';
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
		return (
			<div className="ec-concert-stats__error">
				{ error }
			</div>
		);
	}

	if ( ! loading && shows.length === 0 ) {
		return (
			<div className="ec-concert-stats__empty-tab">
				{ period === 'upcoming'
					? 'No upcoming shows marked yet. Browse events and mark shows as "Going"!'
					: 'No past shows tracked yet.' }
			</div>
		);
	}

	return (
		<div className="ec-concert-stats__show-list">
			{ shows.map( ( show ) => (
				<ShowCard key={ show.event_id } show={ show } />
			) ) }

			{ loading && (
				<div className="ec-concert-stats__loading-more">Loading...</div>
			) }

			{ ! loading && page < pages && (
				<div className="ec-concert-stats__load-more">
					<button
						className="ec-concert-stats__load-more-btn"
						onClick={ () => setPage( ( p ) => p + 1 ) }
						type="button"
					>
						Load More ({ total - shows.length } remaining)
					</button>
				</div>
			) }
		</div>
	);
};

export default ShowList;
