/**
 * Concert Stats Block — Frontend View
 *
 * Hydrates the server-rendered container with a React app.
 * Uses @extrachill/components for layout primitives.
 *
 * @package ExtraChillEvents
 */

import { createRoot, useState, useEffect, useRef } from '@wordpress/element';
import { BlockShell, BlockShellInner, BlockShellHeader, InlineStatus, Tabs, Panel } from '@extrachill/components';
import StatsBar from './components/StatsBar';
import ShowList from './components/ShowList';
import Leaderboard from './components/Leaderboard';
import YearFilter from './components/YearFilter';
import AddPastShows from './components/AddPastShows';
import ImportTab from './components/ImportTab';
import useStats from './hooks/useStats';

/**
 * Whitelist of tab IDs that may appear in `?tab=` URL state. Anything
 * else falls back to the default ("upcoming"). Owner-only tabs are
 * still gated by `isOwn` inside the render — the whitelist just guards
 * against arbitrary strings becoming React state.
 */
const VALID_TAB_IDS = [ 'upcoming', 'past', 'calendar', 'add-past', 'stats', 'import' ];

/**
 * Read the initial active tab from `?tab=` on first render. SSR-safe
 * (no-op when `window` is absent). Falls back to `'upcoming'`.
 *
 * @return {string} Tab ID.
 */
function readInitialTab() {
	if ( typeof window === 'undefined' ) {
		return 'upcoming';
	}
	const params = new URLSearchParams( window.location.search );
	const requested = params.get( 'tab' );
	return VALID_TAB_IDS.includes( requested ) ? requested : 'upcoming';
}

/**
 * Main Concert Stats App component.
 */
function ConcertStatsApp( { userId, eventsUrl, isOwn, hasCalendar, containerRef } ) {
	const [ year, setYear ] = useState( 0 );
	const [ activeTab, setActiveTab ] = useState( readInitialTab );

	const { stats, loading: statsLoading } = useStats( userId, { year } );

	// Sync `?tab=` to URL whenever the active tab changes. Use
	// replaceState to keep the back button useful — switching tabs
	// shouldn't pollute browser history. Strip `tab=` when the user is
	// on the default tab so /my-shows/ stays a clean canonical URL.
	useEffect( () => {
		if ( typeof window === 'undefined' ) {
			return;
		}
		const url = new URL( window.location.href );
		if ( activeTab === 'upcoming' ) {
			url.searchParams.delete( 'tab' );
		} else {
			url.searchParams.set( 'tab', activeTab );
		}
		// Clear `?month=` whenever we leave the calendar tab — the
		// data-machine-events calendar block manages that param itself
		// when it's the active surface, but a stale `month` shouldn't
		// follow the user back to the list tabs.
		if ( activeTab !== 'calendar' ) {
			url.searchParams.delete( 'month' );
		}
		const next = url.pathname + ( url.search ? url.search : '' );
		window.history.replaceState( {}, '', next );
	}, [ activeTab ] );

	// Toggle the server-rendered calendar wrapper based on the active
	// tab. The wrapper is rendered once at server-render time (see
	// render.php) as a *sibling* of the React mount node (both live
	// inside the .ec-concert-stats-shell parent). Toggle rather than
	// re-mount so the calendar block's own JS keeps owning
	// prev/next/today + URL month state across tab switches. We scope
	// the lookup to the shell so multiple concert-stats instances on
	// the same page (unlikely, but possible) don't fight over each
	// other's calendars.
	useEffect( () => {
		if ( ! hasCalendar || ! containerRef.current ) {
			return;
		}
		const shell = containerRef.current.closest( '.ec-concert-stats-shell' )
			|| containerRef.current.parentElement;
		if ( ! shell ) {
			return;
		}
		const calendar = shell.querySelector(
			'.ec-concert-stats__embedded-calendar'
		);
		if ( calendar ) {
			calendar.hidden = activeTab !== 'calendar';
		}
	}, [ activeTab, hasCalendar, containerRef ] );

	const upcomingCount = stats ? ( stats.total_shows - Object.values( stats.shows_by_year || {} ).reduce( ( a, b ) => a + b, 0 ) + stats.total_shows ) : 0;

	// Tab order: Upcoming → Past → Calendar (owner, #110) → Add Past
	// Shows (owner, #109) → Stats → Import (owner, #112). Calendar
	// sits between the read-only history axes (Upcoming/Past) and the
	// owner-only growth tabs (Add Past Shows, Import) because it's a
	// read-only view of the same history but visualized differently.
	// Owner-only because the underlying tracking table is per-user.
	const tabs = [
		{
			id: 'upcoming',
			label: 'Upcoming',
		},
		{
			id: 'past',
			label: 'Past',
		},
		...( isOwn && hasCalendar
			? [
					{
						id: 'calendar',
						label: 'Calendar',
					},
			  ]
			: [] ),
		...( isOwn
			? [
					{
						id: 'add-past',
						label: 'Add Past Shows',
					},
			  ]
			: [] ),
		{
			id: 'stats',
			label: 'Stats',
		},
		...( isOwn
			? [
					{
						id: 'import',
						label: 'Import',
					},
			  ]
			: [] ),
	];

	// Empty state — no shows at all. Owners see Import alongside the empty CTA.
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
					{ isOwn && (
						<Panel compact>
							<ImportTab />
						</Panel>
					) }
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

					{ /*
					   Calendar tab content lives in a sibling DOM node
					   emitted by render.php (sibling to this React
					   root). We render nothing in the Panel here; the
					   useEffect above toggles the sibling's `hidden`
					   attribute. Keeps the server-rendered calendar
					   block's own JS (prev/next/today nav, month URL
					   sync) untouched.
					 */ }
					{ activeTab === 'calendar' && ! hasCalendar && (
						<InlineStatus tone="info">
							Calendar view is unavailable here.
						</InlineStatus>
					) }

					{ activeTab === 'add-past' && isOwn && (
						<AddPastShows />
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
						<InlineStatus tone="info">Loading stats…</InlineStatus>
					) }

					{ activeTab === 'import' && isOwn && (
						<ImportTab />
					) }
				</Panel>
			</BlockShellInner>
		</BlockShell>
	);
}

/**
 * Wrapper that gives the app a stable ref to the React mount container
 * so the Calendar-tab useEffect can walk up to the shared
 * .ec-concert-stats-shell parent and locate the sibling embedded
 * calendar wrapper emitted at server-render time.
 */
function ConcertStatsAppWithRef( props ) {
	const { mountNode, ...rest } = props;
	const containerRef = useRef( mountNode );
	return <ConcertStatsApp { ...rest } containerRef={ containerRef } />;
}

/**
 * Initialize all concert-stats blocks on the page.
 *
 * DOM contract (see render.php):
 *
 *   <div class="ec-concert-stats-shell">
 *     <div class="ec-concert-stats" data-…>     ← React root here
 *       <div class="ec-concert-stats__loading">…</div>
 *     </div>
 *     <div class="ec-concert-stats__embedded-calendar" hidden>
 *       (server-rendered data-machine-events/calendar block)
 *     </div>
 *   </div>
 *
 * React mounts on `.ec-concert-stats` (unchanged from before #110) and
 * replaces its loading-skeleton child during initial render. The
 * embedded calendar sibling is outside the React root and survives
 * hydration; the Calendar tab's useEffect toggles its `hidden`
 * attribute on tab change.
 */
function init() {
	document.querySelectorAll( '.ec-concert-stats' ).forEach( ( container ) => {
		const userId      = parseInt( container.dataset.userId, 10 );
		const eventsUrl   = container.dataset.eventsUrl || '';
		const isOwn       = container.dataset.isOwn === '1';
		const hasCalendar = container.dataset.hasCalendar === '1';

		const root = createRoot( container );
		root.render(
			<ConcertStatsAppWithRef
				userId={ userId }
				eventsUrl={ eventsUrl }
				isOwn={ isOwn }
				hasCalendar={ hasCalendar }
				mountNode={ container }
			/>
		);
	} );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
