/**
 * StatsBar — Four-stat summary row.
 *
 * Consumes the canonical `StatTile` / `StatGroup` primitives from
 * `@extrachill/components`. Each tile is interactive: it renders as a
 * link to the most relevant tab's canonical `?tab=` URL (Shows → past
 * list, the rest → Stats leaderboards), so the summary row doubles as
 * navigation rather than a dead readout. The link uses the same
 * `?tab=` contract view.js reads on load, so it deep-links correctly.
 *
 * MERGE-ORDER DEPENDENCY: `StatTile` / `StatGroup` are added to
 * `@extrachill/components` in a parallel PR (Minion A). Until that PR
 * merges and this plugin's dependency is bumped, this import will not
 * resolve at build time. See the PR description for the required merge
 * order.
 *
 * @package
 */

/**
 * External dependencies
 */
import { StatTile, StatGroup } from '@extrachill/components';

/**
 * Build a canonical `?tab=` URL for a given tab id, preserving the
 * current path. Mirrors the URL contract maintained by view.js
 * (`upcoming` is the default tab and carries no `tab` param).
 *
 * @param {string} tabId
 * @return {string} Relative URL.
 */
function tabHref( tabId ) {
	if ( typeof window === 'undefined' ) {
		return `?tab=${ tabId }`;
	}
	const url = new URL( window.location.href );
	if ( tabId === 'upcoming' ) {
		url.searchParams.delete( 'tab' );
	} else {
		url.searchParams.set( 'tab', tabId );
	}
	return url.pathname + ( url.search ? url.search : '' );
}

const StatsBar = ( { stats } ) => {
	if ( ! stats ) {
		return null;
	}

	// Each tile maps to the tab that best explains the number. Shows
	// jumps to the Past list (the tracked-history surface); the
	// venue/artist/city aggregates jump to the Stats leaderboards
	// where those breakdowns live.
	const items = [
		{
			value: stats.total_shows,
			label: stats.total_shows === 1 ? 'Show' : 'Shows',
			tab: 'past',
		},
		{
			value: stats.unique_venues,
			label: stats.unique_venues === 1 ? 'Venue' : 'Venues',
			tab: 'stats',
		},
		{
			value: stats.unique_artists,
			label: stats.unique_artists === 1 ? 'Artist' : 'Artists',
			tab: 'stats',
		},
		{
			value: stats.unique_cities,
			label: stats.unique_cities === 1 ? 'City' : 'Cities',
			tab: 'stats',
		},
	];

	return (
		<StatGroup className="ec-concert-stats__stats-bar">
			{ items.map( ( item ) => (
				<StatTile
					key={ item.label }
					value={ item.value }
					label={ item.label }
					href={ tabHref( item.tab ) }
					className="ec-concert-stats__stat"
				/>
			) ) }
		</StatGroup>
	);
};

export default StatsBar;
