/**
 * Concert Stats Block — Frontend View
 *
 * Hydrates the server-rendered container with a React app.
 * Uses @extrachill/components for layout primitives.
 *
 * @package ExtraChillEvents
 */

import { createRoot, useState } from '@wordpress/element';
import { BlockShell, BlockShellInner, BlockShellHeader, Tabs, Panel } from '@extrachill/components';
import StatsBar from './components/StatsBar';
import ShowList from './components/ShowList';
import Leaderboard from './components/Leaderboard';
import YearFilter from './components/YearFilter';
import useStats from './hooks/useStats';

/**
 * Main Concert Stats App component.
 */
function ConcertStatsApp( { userId, eventsUrl, isOwn } ) {
	const [ year, setYear ] = useState( 0 );
	const [ activeTab, setActiveTab ] = useState( 'upcoming' );

	const { stats, loading: statsLoading } = useStats( userId, { year } );

	const upcomingCount = stats ? ( stats.total_shows - Object.values( stats.shows_by_year || {} ).reduce( ( a, b ) => a + b, 0 ) + stats.total_shows ) : 0;

	const tabs = [
		{
			id: 'upcoming',
			label: 'Upcoming',
		},
		{
			id: 'past',
			label: 'Past',
		},
		{
			id: 'stats',
			label: 'Stats',
		},
	];

	// Empty state — no shows at all.
	if ( ! statsLoading && stats && stats.total_shows === 0 && ! year ) {
		return (
			<BlockShell>
				<BlockShellInner maxWidth="narrow">
					<BlockShellHeader title={ isOwn ? 'My Shows' : 'Concert History' } />
					<Panel>
						<div className="ec-concert-stats__empty">
							<p className="ec-concert-stats__empty-heading">
								Start Tracking Your Shows!
							</p>
							<p className="ec-concert-stats__empty-text">
								Mark events as &ldquo;Going&rdquo; to build your personal concert history.
							</p>
							{ eventsUrl && (
								<a href={ eventsUrl } className="ec-concert-stats__empty-cta">
									Browse Shows
								</a>
							) }
						</div>
					</Panel>
				</BlockShellInner>
			</BlockShell>
		);
	}

	return (
		<BlockShell>
			<BlockShellInner maxWidth="narrow">
				<BlockShellHeader
					title={ isOwn ? 'My Shows' : 'Concert History' }
					actions={
						stats && stats.shows_by_year ? (
							<YearFilter
								showsByYear={ stats.shows_by_year }
								activeYear={ year }
								onChange={ setYear }
							/>
						) : null
					}
				/>

				<StatsBar stats={ stats } />

				<Tabs
					tabs={ tabs }
					active={ activeTab }
					onChange={ setActiveTab }
				/>

				<Panel compact>
					{ activeTab === 'upcoming' && (
						<ShowList userId={ userId } period="upcoming" year={ year } />
					) }

					{ activeTab === 'past' && (
						<ShowList userId={ userId } period="past" year={ year } />
					) }

					{ activeTab === 'stats' && stats && (
						<div className="ec-concert-stats__leaderboards">
							<Leaderboard
								title="Top Artists"
								items={ stats.top_artists }
							/>
							<Leaderboard
								title="Top Venues"
								items={ stats.top_venues }
							/>
							<Leaderboard
								title="Top Cities"
								items={ stats.top_cities }
							/>
						</div>
					) }

					{ activeTab === 'stats' && statsLoading && (
						<div className="ec-concert-stats__loading-more">
							Loading stats...
						</div>
					) }
				</Panel>
			</BlockShellInner>
		</BlockShell>
	);
}

/**
 * Initialize all concert-stats blocks on the page.
 */
function init() {
	document.querySelectorAll( '.ec-concert-stats' ).forEach( ( container ) => {
		const userId = parseInt( container.dataset.userId, 10 );
		const eventsUrl = container.dataset.eventsUrl || '';
		const isOwn = container.dataset.isOwn === '1';

		const root = createRoot( container );
		root.render(
			<ConcertStatsApp
				userId={ userId }
				eventsUrl={ eventsUrl }
				isOwn={ isOwn }
			/>
		);
	} );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
