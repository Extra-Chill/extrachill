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
import useImportRuns from './hooks/useImportRuns';

/**
 * Whitelist of tab IDs that may appear in `?tab=` URL state. Anything
 * else falls back to the default ("upcoming"). Owner-only tabs are
 * still gated by `isOwn` inside the render — the whitelist just guards
 * against arbitrary strings becoming React state.
 */
const VALID_TAB_IDS = [ 'upcoming', 'past', 'calendar', 'add-past', 'map', 'stats', 'import' ];

/**
 * Read the initial active tab from `?tab=` on first render. SSR-safe
 * (no-op when `window` is absent). Falls back to `'upcoming'`.
 *
 * #126: when `?tab=` is absent we default to `'upcoming'`. A
 * post-fetch effect inside the app swaps the default to `'add-past'`
 * for owners whose `stats.total_shows === 0` — that's the empty-state
 * UX where the search-for-past-shows surface is the most useful
 * landing tab. Implementation note: we picked JS-side post-fetch over
 * a server-rendered `data-has-any-shows` attribute for simplicity
 * (the latter would require either a duplicate DB query in render.php
 * or threading `total_shows` from the concert-tracking abilities at
 * server-render time, and the brief upcoming→add-past flip is
 * acceptable since the loading skeleton covers the very first paint
 * anyway).
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
 * Whether the current page load arrived with an explicit `?tab=` in
 * the URL. Used to gate the empty-state default-tab swap so we never
 * override an intentional deep link.
 *
 * @return {boolean}
 */
function hasExplicitTabParam() {
	if ( typeof window === 'undefined' ) {
		return false;
	}
	const params = new URLSearchParams( window.location.search );
	const requested = params.get( 'tab' );
	return VALID_TAB_IDS.includes( requested );
}

/**
 * Main Concert Stats App component.
 */
function ConcertStatsApp( { userId, eventsUrl, isOwn, hasCalendar, hasMap, containerRef } ) {
	const [ year, setYear ] = useState( 0 );
	const [ activeTab, setActiveTab ] = useState( readInitialTab );
	// #126: tracks whether the user (or a deep link) has chosen a tab.
	// Until then, the post-stats-fetch effect below is allowed to swap
	// the default from 'upcoming' → 'add-past' when the owner has zero
	// tracked shows. Once the user explicitly picks a tab via the tab
	// strip, this flips to true and the auto-swap stops fighting them.
	const [ userPickedTab, setUserPickedTab ] = useState( hasExplicitTabParam );

	const { stats, loading: statsLoading } = useStats( userId, { year } );

	// Pull the configured source list at the parent level so the Import tab
	// only renders when at least one source is actually available to this
	// user. Mirrors the hasMap / hasCalendar gating pattern — the difference
	// is that source availability is driven by server-side ability filtering
	// (admins provision API keys via Data Machine auth), so we read it
	// dynamically rather than from a server-emitted dataset attribute.
	//
	// We hoist the hook to the parent and pass the full bag down to ImportTab
	// so we don't double-fetch from two `useImportRuns()` sites on the same
	// page. ImportTab consumes the props directly.
	const importRunsBag = useImportRuns();
	const hasImports = isOwn && importRunsBag.sources && importRunsBag.sources.length > 0;

	// #126: empty-state default tab. When the owner has zero tracked
	// shows and hasn't explicitly picked a tab (no `?tab=` in URL,
	// hasn't clicked the strip yet), land them on Add Past Shows. The
	// search-for-past-shows surface is the most useful starting point
	// for users in this state; the old takeover hid the entire tab
	// strip behind a Browse-Shows CTA that pointed at the wrong place.
	useEffect( () => {
		if ( userPickedTab ) {
			return;
		}
		if ( statsLoading || ! stats ) {
			return;
		}
		if ( stats.total_shows === 0 && isOwn ) {
			setActiveTab( 'add-past' );
		}
	}, [ stats, statsLoading, isOwn, userPickedTab ] );

	const handleTabChange = ( tabId ) => {
		setUserPickedTab( true );
		setActiveTab( tabId );
	};

	const hasAnyShows = !! stats && stats.total_shows > 0;

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

	// Map tab (#111): identical sibling-toggle pattern to the calendar
	// tab. The embedded events-map block (chronologicalRouteMode) lives
	// inside .ec-concert-stats__embedded-map as a sibling of the React
	// root. Toggle rather than re-mount so Leaflet's internal map state
	// (zoom, fitted bounds, polyline, marker cluster) survives tab
	// switches. Leaflet needs a resize event when its container
	// transitions from hidden to visible so the tile layer paints
	// completely instead of leaving a partial render.
	useEffect( () => {
		if ( ! hasMap || ! containerRef.current ) {
			return;
		}
		const shell = containerRef.current.closest( '.ec-concert-stats-shell' )
			|| containerRef.current.parentElement;
		if ( ! shell ) {
			return;
		}
		const map = shell.querySelector( '.ec-concert-stats__embedded-map' );
		if ( ! map ) {
			return;
		}
		const wasHidden = map.hidden;
		map.hidden = activeTab !== 'map';
		if ( wasHidden && ! map.hidden && typeof window !== 'undefined' ) {
			// Leaflet listens for window resize via invalidateSize hooks.
			// Defer to next frame so the layout has settled before the
			// map recalculates its container size.
			window.requestAnimationFrame( () => {
				window.dispatchEvent( new Event( 'resize' ) );
			} );
		}
	}, [ activeTab, hasMap, containerRef ] );

	// Tab order: Upcoming → Past → Calendar (owner, #110) → Add Past
	// Shows (owner, #109) → Map (owner, #111) → Stats → Import
	// (owner, #112). Visualization tabs (Calendar, Map) sit alongside
	// the history axes (Upcoming, Past) and the growth tabs (Add Past
	// Shows, Import); Stats stays second-to-last as the summary
	// surface. Owner-only because the underlying tracking table is
	// per-user.
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
		...( isOwn && hasMap
			? [
					{
						id: 'map',
						label: 'Map',
					},
			  ]
			: [] ),
		{
			id: 'stats',
			label: 'Stats',
		},
		...( hasImports
			? [
					{
						id: 'import',
						label: 'Import',
					},
			  ]
			: [] ),
	];

	// #126: per-tab empty-state messages used when the user has zero
	// tracked shows overall. Rendered as a plain `<InlineStatus>` so
	// the surrounding Panel chrome still provides visual continuity
	// with the rest of the tab strip. ShowList owns its own empty
	// state for the Upcoming / Past tabs (it already returns
	// InlineStatus when the period-scoped fetch returns zero rows),
	// so Upcoming / Past don't need a duplicate empty branch here.
	const statsEmptyMessage = (
		<InlineStatus tone="info">
			Stats will appear here once you&rsquo;ve tracked at least one show.
		</InlineStatus>
	);
	const calendarEmptyMessage = (
		<InlineStatus tone="info">
			Your concert calendar will appear here once you&rsquo;ve tracked shows.
		</InlineStatus>
	);
	const mapEmptyMessage = (
		<InlineStatus tone="info">
			Your concert map will appear here once you&rsquo;ve tracked shows at venues with coordinates.
		</InlineStatus>
	);

	return (
		<BlockShell>
			<BlockShellInner maxWidth="narrow">
				<BlockShellHeader
					title={ isOwn ? 'My Shows' : 'Concert History' }
					actions={
						stats && stats.shows_by_year && hasAnyShows ? (
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
					onChange={ handleTabChange }
				/>

				<Panel compact>
					{ activeTab === 'upcoming' && (
						<ShowList
							userId={ userId }
							period="upcoming"
							year={ year }
							eventsUrl={ eventsUrl }
						/>
					) }

					{ activeTab === 'past' && (
						<ShowList userId={ userId } period="past" year={ year } />
					) }

					{ /*
					   Calendar tab content lives in a sibling DOM node
					   emitted by render.php (sibling to this React
					   root). We render nothing in the Panel here when
					   the sibling exists; the useEffect above toggles
					   its `hidden` attribute. When the user has no
					   tracked shows yet, the sibling wrapper is still
					   emitted (render.php only checks owner+is_page),
					   but the underlying calendar query returns zero
					   events. Show the per-tab InlineStatus message
					   instead of an empty grid in that case.
					 */ }
					{ activeTab === 'calendar' && ! hasCalendar && (
						<InlineStatus tone="info">
							Calendar view is unavailable here.
						</InlineStatus>
					) }
					{ activeTab === 'calendar' && hasCalendar && ! hasAnyShows && ! statsLoading && (
						calendarEmptyMessage
					) }

					{ /*
					   Map tab content lives in a sibling DOM node
					   emitted by render.php (sibling to this React
					   root). Same pattern as Calendar — the useEffect
					   above toggles the sibling's `hidden` attribute,
					   preserving Leaflet's internal state (zoom,
					   polyline, marker cluster) across tab switches.
					 */ }
					{ activeTab === 'map' && ! hasMap && (
						<InlineStatus tone="info">
							Map view is unavailable here.
						</InlineStatus>
					) }
					{ activeTab === 'map' && hasMap && ! hasAnyShows && ! statsLoading && (
						mapEmptyMessage
					) }

					{ activeTab === 'add-past' && isOwn && (
						<AddPastShows />
					) }

					{ activeTab === 'stats' && statsLoading && (
						<InlineStatus tone="info">Loading stats…</InlineStatus>
					) }

					{ activeTab === 'stats' && ! statsLoading && stats && ! hasAnyShows && (
						statsEmptyMessage
					) }

					{ activeTab === 'stats' && ! statsLoading && stats && hasAnyShows && (
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

					{ activeTab === 'import' && hasImports && (
						<ImportTab bag={ importRunsBag } />
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
 *     <div class="ec-concert-stats__embedded-map" hidden>
 *       (server-rendered data-machine-events/events-map block)
 *     </div>
 *   </div>
 *
 * React mounts on `.ec-concert-stats` (unchanged from before #110) and
 * replaces its loading-skeleton child during initial render. The
 * embedded calendar / map siblings are outside the React root and
 * survive hydration; the respective tab useEffects toggle their
 * `hidden` attributes on tab change.
 */
function init() {
	document.querySelectorAll( '.ec-concert-stats' ).forEach( ( container ) => {
		const userId      = parseInt( container.dataset.userId, 10 );
		const eventsUrl   = container.dataset.eventsUrl || '';
		const isOwn       = container.dataset.isOwn === '1';
		const hasCalendar = container.dataset.hasCalendar === '1';
		const hasMap      = container.dataset.hasMap === '1';

		const root = createRoot( container );
		root.render(
			<ConcertStatsAppWithRef
				userId={ userId }
				eventsUrl={ eventsUrl }
				isOwn={ isOwn }
				hasCalendar={ hasCalendar }
				hasMap={ hasMap }
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
