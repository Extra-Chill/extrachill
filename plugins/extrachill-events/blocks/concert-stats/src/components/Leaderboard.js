/**
 * Leaderboard — Top artists/venues/cities.
 *
 * @package ExtraChillEvents
 */

const Leaderboard = ( { title, items, maxItems = 5 } ) => {
	if ( ! items || items.length === 0 ) {
		return null;
	}

	return (
		<div className="ec-concert-stats__leaderboard">
			<h3 className="ec-concert-stats__leaderboard-title">{ title }</h3>
			<ol className="ec-concert-stats__leaderboard-list">
				{ items.slice( 0, maxItems ).map( ( item ) => (
					<li key={ item.slug } className="ec-concert-stats__leaderboard-item">
						<span className="ec-concert-stats__leaderboard-name">
							{ item.name }
						</span>
						<span className="ec-concert-stats__leaderboard-count">
							{ item.count }
						</span>
					</li>
				) ) }
			</ol>
		</div>
	);
};

export default Leaderboard;
