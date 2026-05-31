/**
 * Leaderboard — Top artists/venues/cities.
 *
 * Wrapped in a compact `<Panel>` for chrome consistency with the rest
 * of the platform. The inner list markup remains local.
 *
 * Venue and city names render as platform **taxonomy badges** so they
 * match the colored pill badges users see across the events calendar and
 * archives. The class contract mirrors the theme's
 * `extrachill_display_taxonomy_badges()` helper and the
 * `inc/core/data-machine-events/badge-styling.php` mapping:
 *   - base:  `taxonomy-badge`
 *   - venue: `venue-badge` + `venue-<slug>`
 *   - city:  `location-badge` + `location-<slug>`
 * Per-term color classes (`venue-<slug>` / `location-<slug>`) resolve
 * against the theme's `taxonomy-badges.css`; terms without a custom color
 * fall back to the base `taxonomy-badge` + `venue-badge`/`location-badge`
 * styling, so every term still reads as a badge. Artists are intentionally
 * NOT badged — the artist taxonomy is excluded from the badge system by
 * design (see `extrachill_events_exclude_taxonomies`).
 *
 * @package
 */

import { Badge, Panel } from '@extrachill/components';

/**
 * Build the taxonomy-badge class list for a leaderboard term.
 *
 * @param {string} taxonomy Badge taxonomy: `venue` | `location` | undefined.
 * @param {string} slug     Term slug for the per-term color class.
 * @return {string|null} Space-joined badge classes, or null when unbadged.
 */
const badgeClasses = ( taxonomy, slug ) => {
	if ( 'venue' === taxonomy ) {
		return `taxonomy-badge venue-badge venue-${ slug }`;
	}
	if ( 'location' === taxonomy ) {
		return `taxonomy-badge location-badge location-${ slug }`;
	}
	return null;
};

/**
 * @param {Object}      props          Component props.
 * @param {string}      props.title    Leaderboard heading.
 * @param {Array}       props.items    Term rows ({ name, slug, count, url }).
 * @param {number}      props.maxItems Max rows to render.
 * @param {string|null} props.taxonomy Badge taxonomy: `venue` | `location`.
 *                                     Omit for unbadged lists (artists).
 */
const Leaderboard = ( { title, items, maxItems = 5, taxonomy = null } ) => {
	if ( ! items || items.length === 0 ) {
		return null;
	}

	return (
		<Panel compact className="ec-concert-stats__leaderboard">
			<h3 className="ec-concert-stats__leaderboard-title">{ title }</h3>
			<ol className="ec-concert-stats__leaderboard-list">
				{ items.slice( 0, maxItems ).map( ( item ) => {
					const badge = badgeClasses( taxonomy, item.slug );
					// Badged terms drop the color-bearing `__leaderboard-link`
					// class so the local link color does not fight the badge's
					// own background/color; `__leaderboard-name` (flex sizing
					// only) is kept for layout. Unbadged terms (artists) keep
					// the link class for the original plain-link styling.
					const nameClass = badge
						? `ec-concert-stats__leaderboard-name ec-concert-stats__leaderboard-badge ${ badge }`
						: 'ec-concert-stats__leaderboard-name ec-concert-stats__leaderboard-link';

					return (
						<li
							key={ item.slug }
							className="ec-concert-stats__leaderboard-item"
						>
							{ item.url ? (
								<a className={ nameClass } href={ item.url }>
									{ item.name }
								</a>
							) : (
								<span className={ nameClass }>
									{ item.name }
								</span>
							) }
							<Badge
								tone="muted"
								variant="subtle"
								size="sm"
								className="ec-concert-stats__leaderboard-count"
							>
								{ item.count }
							</Badge>
						</li>
					);
				} ) }
			</ol>
		</Panel>
	);
};

export default Leaderboard;
