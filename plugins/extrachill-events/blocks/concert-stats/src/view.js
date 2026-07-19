/**
 * Concert Stats Block — Frontend View
 *
 * Hydrates the server-rendered container with a React app.
 * Uses @extrachill/components for layout primitives.
 *
 * @package
 */

/**
 * WordPress dependencies
 */
import { createRoot, useState, useEffect, useRef } from '@wordpress/element';

/**
 * External dependencies
 */
import {
	BlockShell,
	BlockShellInner,
	BlockShellHeader,
	Grid,
	InlineStatus,
	ResponsiveTabs,
} from '@extrachill/components';

/**
 * Internal dependencies
 */
import StatsBar from './components/StatsBar';
import ShowList from './components/ShowList';
import PastTab from './components/PastTab';
import Leaderboard from './components/Leaderboard';
import YearFilter from './components/YearFilter';
import ImportTab from './components/ImportTab';
import useStats from './hooks/useStats';
import useShows from './hooks/useShows';
import useImportRuns from './hooks/useImportRuns';

/**
 * Whitelist of tab IDs that may appear in `?tab=` URL state. Owners default
 * to Upcoming; public viewers default to Past and can also open Stats.
 */
const VALID_TAB_IDS = [
	'upcoming',
	'past',
	'calendar',
	'map',
	'stats',
	'import',
];

/**
 * Back-compat alias map for retired tab IDs.
 *
 * #159: the standalone `add-past` tab was folded into the `past` tab.
 * Any shared link or bookmark that still carries `?tab=add-past` should
 * resolve to `past` instead of silently falling back to the default
 * `upcoming` tab. `resolveTabId()` maps a raw `?tab=` value through this
 * table before whitelist validation.
 */
const TAB_ALIASES = {
	'add-past': 'past',
};

/**
 * Resolve a raw `?tab=` value to a valid, current tab ID.
 *
 * Applies the {@link TAB_ALIASES} back-compat mapping first (so retired
 * IDs like `add-past` resolve to their replacement), then validates
 * against {@link VALID_TAB_IDS}. Returns `null` when the value isn't a
 * recognized (or aliased) tab so callers can apply their own default.
 *
 * @param {?string} requested Raw `tab` query param value.
 * @param {boolean} isOwn     Whether owner-only tabs are available.
 * @return {?string} A valid tab ID, or null when unrecognized.
 */
function resolveTabId( requested, isOwn = true ) {
	if ( ! requested ) {
		return null;
	}
	const aliased = TAB_ALIASES[ requested ] || requested;
	if ( ! VALID_TAB_IDS.includes( aliased ) ) {
		return null;
	}
	return ! isOwn && ! [ 'past', 'stats' ].includes( aliased )
		? 'past'
		: aliased;
}

/**
 * Read the initial active tab from `?tab=` on first render. SSR-safe
 * (no-op when `window` is absent). Public viewers always land on a
 * public tab, with unavailable owner-only links normalized to Past.
 *
 * #126: when `?tab=` is absent we default to `'upcoming'`. A
 * post-fetch effect inside the app swaps the default to `'past'`
 * for owners whose `stats.total_shows === 0` — that's the empty-state
 * UX where the search-for-past-shows affordance (folded into the Past
 * tab as of #159) is the most useful landing tab. Implementation note:
 * we picked JS-side post-fetch over a server-rendered
 * `data-has-any-shows` attribute for simplicity (the latter would
 * require either a duplicate DB query in render.php or threading
 * `total_shows` from the concert-tracking abilities at server-render
 * time, and the brief upcoming→past flip is acceptable since the
 * loading skeleton covers the very first paint anyway).
 *
 * #159: a legacy `?tab=add-past` deep link resolves to `'past'` via
 * resolveTabId()'s alias table.
 *
 * @param {boolean} isOwn Whether owner-only tabs are available.
 * @return {string} Tab ID.
 */
function readInitialTab( isOwn ) {
	if ( typeof window === 'undefined' ) {
		return isOwn ? 'upcoming' : 'past';
	}
	const params = new URLSearchParams( window.location.search );
	return (
		resolveTabId( params.get( 'tab' ), isOwn ) ||
		( isOwn ? 'upcoming' : 'past' )
	);
}

/**
 * Whether the current page load arrived with an explicit (recognized
 * or aliased) `?tab=` in the URL. Used to gate the empty-state
 * default-tab swap so we never override an intentional deep link —
 * including a legacy `?tab=add-past` link, which resolveTabId() maps to
 * `past` (#159).
 *
 * @param {boolean} isOwn Whether owner-only tabs are available.
 * @return {boolean} True when the URL carries a recognized `?tab=` value.
 */
function hasExplicitTabParam( isOwn ) {
	if ( typeof window === 'undefined' ) {
		return false;
	}
	const params = new URLSearchParams( window.location.search );
	return resolveTabId( params.get( 'tab' ), isOwn ) !== null;
}

/**
 * Main Concert Stats App component.
 * @param {Object}  root0              Component props.
 * @param {number}  root0.userId       Profile owner's user ID.
 * @param {string}  root0.eventsUrl    Base URL for the events site.
 * @param {boolean} root0.isOwn        Whether the viewer owns this profile.
 * @param {string}  root0.publicDateTo Last date included in public stats.
 * @param {boolean} root0.hasCalendar  Whether an embedded calendar sibling is present.
 * @param {boolean} root0.hasMap       Whether an embedded map sibling is present.
 * @param {Object}  root0.containerRef Ref object pointing at the React mount container.
 */
export function ConcertStatsApp( {
	userId,
	eventsUrl,
	isOwn,
	publicDateTo,
	hasCalendar,
	hasMap,
	containerRef,
} ) {
	const [ year, setYear ] = useState( 0 );
	const [ activeTab, setActiveTab ] = useState( () =>
		readInitialTab( isOwn )
	);
	// #126: tracks whether the user (or a deep link) has chosen a tab.
	// Until then, the post-stats-fetch effect below is allowed to swap
	// the default from 'upcoming' → 'past' when the owner has zero
	// tracked shows. Once the user explicitly picks a tab via the tab
	// strip, this flips to true and the auto-swap stops fighting them.
	const [ userPickedTab, setUserPickedTab ] = useState( () =>
		hasExplicitTabParam( isOwn )
	);

	const { stats, loading: statsLoading } = useStats( userId, {
		year,
		dateTo: isOwn ? '' : publicDateTo,
	} );

	// Badge counts for the Upcoming / Past tabs. Public views disable the
	// upcoming request entirely so the browser never receives itinerary data.
	// The aggregate stats
	// payload doesn't split tracked shows by period, so we read the
	// accurate per-period `total` from the same paginated shows
	// endpoint ShowList uses, but with `perPage: 1` so it's a cheap
	// count probe rather than a full list fetch. Year-scoped so the
	// badges track the active YearFilter selection.
	const { total: upcomingCount } = useShows( userId, {
		period: 'upcoming',
		year,
		page: 1,
		perPage: 1,
		enabled: isOwn,
	} );
	const { total: pastCount } = useShows( userId, {
		period: 'past',
		year,
		page: 1,
		perPage: 1,
	} );

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
	const importRunsBag = useImportRuns( isOwn );
	const hasImports =
		isOwn && importRunsBag.sources && importRunsBag.sources.length > 0;

	// #126/#159: empty-state default tab. When the owner has zero
	// tracked shows and hasn't explicitly picked a tab (no `?tab=` in
	// URL, hasn't clicked the strip yet), land them on the Past tab —
	// which now hosts the search-for-past-shows affordance inline above
	// the (empty) tracked-shows list (#159 folded the old standalone
	// "Add Past Shows" tab into Past). That search surface is the most
	// useful starting point for users in this state; the old takeover
	// hid the entire tab strip behind a Browse-Shows CTA that pointed
	// at the wrong place.
	useEffect( () => {
		if ( userPickedTab ) {
			return;
		}
		if ( statsLoading || ! stats ) {
			return;
		}
		if ( stats.total_shows === 0 && isOwn ) {
			setActiveTab( 'past' );
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
		const shell =
			containerRef.current.closest( '.ec-concert-stats-shell' ) ||
			containerRef.current.parentElement;
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
		const shell =
			containerRef.current.closest( '.ec-concert-stats-shell' ) ||
			containerRef.current.parentElement;
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

	// Owner tab order: Upcoming → Past → Calendar (#110) → Map
	// (owner, #111) → Stats → Import (owner, #112). The Past tab now
	// also hosts the owner-only "add a past show" search affordance
	// (#159 folded the old standalone "Add Past Shows" tab into Past),
	// so there's no separate growth tab in the strip anymore.
	// Visualization tabs (Calendar, Map) sit alongside the history axes
	// (Upcoming, Past); Stats stays second-to-last as the summary
	// surface; Import is the remaining growth tab. Public viewers receive only
	// Past and past-derived Stats, never a centralized upcoming itinerary.
	const tabs = [
		...( isOwn
			? [
					{
						id: 'upcoming',
						label: 'Upcoming',
						badge: upcomingCount,
					},
			  ]
			: [] ),
		{
			id: 'past',
			label: 'Past',
			badge: pastCount,
		},
		...( isOwn && hasCalendar
			? [
					{
						id: 'calendar',
						label: 'Calendar',
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
	// the surrounding ResponsiveTabs panel chrome still provides
	// visual continuity with the tab strip. ShowList owns its own empty
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
			Your concert calendar will appear here once you&rsquo;ve tracked
			shows.
		</InlineStatus>
	);
	const mapEmptyMessage = (
		<InlineStatus tone="info">
			Your concert map will appear here once you&rsquo;ve tracked shows at
			venues with coordinates.
		</InlineStatus>
	);

	// Panel renderer for ResponsiveTabs. On desktop this is called for
	// the active tab; on mobile (accordion) it's called for whichever
	// item is expanded. The calendar/map cases render only the
	// fallback / empty-state messaging — their live content lives in
	// sibling DOM nodes toggled by the useEffects above, so the Panel
	// body stays empty when the embedded surface is present and
	// populated.
	const renderPanel = ( tabId ) => {
		switch ( tabId ) {
			case 'upcoming':
				return (
					<ShowList
						userId={ userId }
						period="upcoming"
						year={ year }
						eventsUrl={ eventsUrl }
					/>
				);

			case 'past':
				// #159: the Past tab hosts both the read-only tracked
				// past-shows list and (for the owner) the inline
				// search-and-mark affordance that used to live in the
				// removed standalone "Add Past Shows" tab. PastTab
				// composes the two and refetches the list when a show
				// is marked so additions appear without a reload.
				return (
					<PastTab userId={ userId } year={ year } isOwn={ isOwn } />
				);

			case 'calendar':
				/*
				 * Calendar tab content lives in a sibling DOM node
				 * emitted by render.php (sibling to this React root);
				 * the useEffect above toggles its `hidden` attribute.
				 * When the user has no tracked shows yet, the sibling
				 * wrapper is still emitted but the calendar query is
				 * empty, so show the per-tab InlineStatus message
				 * instead of an empty grid.
				 */
				if ( ! hasCalendar ) {
					return (
						<InlineStatus tone="info">
							Calendar view is unavailable here.
						</InlineStatus>
					);
				}
				if ( ! hasAnyShows && ! statsLoading ) {
					return calendarEmptyMessage;
				}
				return null;

			case 'map':
				/*
				 * Map tab content lives in a sibling DOM node emitted
				 * by render.php. Same pattern as Calendar — the
				 * useEffect above toggles the sibling's `hidden`
				 * attribute, preserving Leaflet's internal state
				 * across tab switches.
				 */
				if ( ! hasMap ) {
					return (
						<InlineStatus tone="info">
							Map view is unavailable here.
						</InlineStatus>
					);
				}
				if ( ! hasAnyShows && ! statsLoading ) {
					return mapEmptyMessage;
				}
				return null;

			case 'stats':
				if ( statsLoading ) {
					return (
						<InlineStatus tone="info">Loading stats…</InlineStatus>
					);
				}
				if ( stats && ! hasAnyShows ) {
					return statsEmptyMessage;
				}
				if ( stats && hasAnyShows ) {
					return (
						<Grid
							className="ec-concert-stats__leaderboards"
							minColumnWidth="200px"
							gap="var(--spacing-lg, 1.5rem)"
						>
							<Leaderboard
								title="Top Artists"
								items={ stats.top_artists }
							/>
							<Leaderboard
								title="Top Venues"
								items={ stats.top_venues }
								taxonomy="venue"
							/>
							<Leaderboard
								title="Top Cities"
								items={ stats.top_cities }
								taxonomy="location"
							/>
						</Grid>
					);
				}
				return null;

			case 'import':
				return hasImports ? <ImportTab bag={ importRunsBag } /> : null;

			default:
				return null;
		}
	};

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

				{ /*
				   #126 zero-state softening: don't greet a brand-new
				   owner with four "0" stat tiles — that reads as a
				   dead app. When the owner has zero tracked shows the
				   inviting Add-Past-Shows tab is the active landing
				   (see the default-tab effect above), and the
				   StatsBar is suppressed so the first impression is
				   the prompt, not the zeros. Once they have any show,
				   the bar appears. Non-owners and still-loading states
				   keep prior behavior.
				 */ }
				{ ( ! isOwn || statsLoading || hasAnyShows ) && (
					<StatsBar stats={ stats } />
				) }

				<ResponsiveTabs
					tabs={ tabs }
					active={ activeTab }
					onChange={ handleTabChange }
					renderPanel={ renderPanel }
					tabsClassPrefix="ec-tabs"
					mobileBreakpoint={ 480 }
				/>
			</BlockShellInner>
		</BlockShell>
	);
}

/**
 * Wrapper that gives the app a stable ref to the React mount container
 * so the Calendar-tab useEffect can walk up to the shared
 * .ec-concert-stats-shell parent and locate the sibling embedded
 * calendar wrapper emitted at server-render time.
 * @param {Object} props Component props, forwarded to ConcertStatsApp plus `mountNode`.
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
		const userId = parseInt( container.dataset.userId, 10 );
		const eventsUrl = container.dataset.eventsUrl || '';
		const isOwn = container.dataset.isOwn === '1';
		const publicDateTo = container.dataset.publicDateTo || '';
		const hasCalendar = container.dataset.hasCalendar === '1';
		const hasMap = container.dataset.hasMap === '1';

		const root = createRoot( container );
		root.render(
			<ConcertStatsAppWithRef
				userId={ userId }
				eventsUrl={ eventsUrl }
				isOwn={ isOwn }
				publicDateTo={ publicDateTo }
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
