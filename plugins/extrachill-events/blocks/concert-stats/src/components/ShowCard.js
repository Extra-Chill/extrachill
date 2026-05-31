/**
 * ShowCard — Single show entry in the list.
 *
 * @package
 */

import { formatShortDate } from '../utils/formatDate';

const ShowCard = ( { show } ) => {
	const artistDisplay =
		show.artists && show.artists.length
			? show.artists.map( ( a ) => a.name ).join( ', ' )
			: show.title || '';

	const venueParts = [];
	if ( show.venue && show.venue.name ) {
		venueParts.push( show.venue.name );
	}
	if ( show.city && show.city.name ) {
		venueParts.push( show.city.name );
	}

	return (
		<a
			href={ show.permalink || '#' }
			className="ec-concert-stats__show-card"
		>
			<span className="ec-concert-stats__show-date">
				{ formatShortDate( show.event_date ) }
			</span>
			<span className="ec-concert-stats__show-details">
				<span className="ec-concert-stats__show-artist">
					{ artistDisplay }
				</span>
				{ venueParts.length > 0 && (
					<span className="ec-concert-stats__show-venue">
						{ venueParts.join( ' \u00b7 ' ) }
					</span>
				) }
			</span>
		</a>
	);
};

export default ShowCard;
