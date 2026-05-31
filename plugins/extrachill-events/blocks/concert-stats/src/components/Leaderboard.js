/**
 * Leaderboard — Top artists/venues/cities.
 *
 * Wrapped in a compact `<Panel>` for chrome consistency with the rest
 * of the platform. The inner list markup remains local.
 *
 * @package ExtraChillEvents
 */

import { Panel } from '@extrachill/components';

const Leaderboard = ( { title, items, maxItems = 5 } ) => {
	if ( ! items || items.length === 0 ) {
		return null;
	}

	return (
		<Panel compact className="ec-concert-stats__leaderboard">
			<h3 className="ec-concert-stats__leaderboard-title">{ title }</h3>
			<ol className="ec-concert-stats__leaderboard-list">
				{ items.slice( 0, maxItems ).map( ( item ) => (
					<li key={ item.slug } className="ec-concert-stats__leaderboard-item">
						{ item.url ? (
							<a
								className="ec-concert-stats__leaderboard-name ec-concert-stats__leaderboard-link"
								href={ item.url }
							>
								{ item.name }
							</a>
						) : (
							<span className="ec-concert-stats__leaderboard-name">
								{ item.name }
							</span>
						) }
						<span className="ec-concert-stats__leaderboard-count">
							{ item.count }
						</span>
					</li>
				) ) }
			</ol>
		</Panel>
	);
};

export default Leaderboard;
