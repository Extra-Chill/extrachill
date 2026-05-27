/**
 * StatsBar — Four-stat summary row.
 *
 * Wrapped in `<Section depth={1}>` for chrome consistency with the rest
 * of the platform. The individual stat tiles remain bespoke local
 * markup — `@extrachill/components` doesn't have a stat-tile primitive
 * yet. If/when one lands, swap the `.ec-concert-stats__stat` items.
 *
 * @package ExtraChillEvents
 */

import { Section } from '@extrachill/components';

const StatsBar = ( { stats } ) => {
	if ( ! stats ) {
		return null;
	}

	const items = [
		{ value: stats.total_shows, label: stats.total_shows === 1 ? 'Show' : 'Shows' },
		{ value: stats.unique_venues, label: stats.unique_venues === 1 ? 'Venue' : 'Venues' },
		{ value: stats.unique_artists, label: stats.unique_artists === 1 ? 'Artist' : 'Artists' },
		{ value: stats.unique_cities, label: stats.unique_cities === 1 ? 'City' : 'Cities' },
	];

	return (
		<Section depth={ 1 } className="ec-concert-stats__stats-bar">
			{ items.map( ( item ) => (
				<div key={ item.label } className="ec-concert-stats__stat">
					<span className="ec-concert-stats__stat-value">{ item.value }</span>
					<span className="ec-concert-stats__stat-label">{ item.label }</span>
				</div>
			) ) }
		</Section>
	);
};

export default StatsBar;
