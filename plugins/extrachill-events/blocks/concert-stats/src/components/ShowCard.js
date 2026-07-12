/**
 * ShowCard — Single show entry in the list.
 *
 * Each card is a cluster of cross-site on-ramps rather than one big link:
 * the date links to the event permalink, and the artist / venue / city
 * names are individual links to their respective entity pages (artist
 * profile on the artist site, venue + location archives on the events site).
 * This serves the platform's network-density goal — every show becomes
 * several doorways into the network.
 *
 * Venue + city render as platform **taxonomy badges** (colored pills)
 * matching the events calendar / archives. The class contract mirrors the
 * theme's `extrachill_display_taxonomy_badges()` helper and the
 * `inc/core/data-machine-events/badge-styling.php` mapping:
 *   - base:  `taxonomy-badge`
 *   - venue: `venue-badge` + `venue-<slug>`
 *   - city:  `location-badge` + `location-<slug>`
 * Terms without a custom per-term color fall back to the base
 * `taxonomy-badge` + `venue-badge`/`location-badge` styling, so every term
 * still reads as a badge. Artists are intentionally left as plain links —
 * the artist taxonomy is excluded from the badge system by design (see
 * `extrachill_events_exclude_taxonomies`).
 *
 * Anchors cannot nest, so the card is intentionally NOT a single wrapping
 * <a>. The row is a plain container; the date carries the event-permalink
 * link and each term name is its own sibling link.
 *
 * @package
 */

/**
 * Internal dependencies
 */
import { formatShortDate } from '../utils/formatDate';

/**
 * Build the taxonomy-badge class list for a venue / city term.
 *
 * @param {string} taxonomy Badge taxonomy: `venue` | `location`.
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
 * Render a single term (artist / venue / city) as a link when it has a
 * resolvable URL, falling back to plain text otherwise.
 *
 * When `taxonomy` is `venue` or `location`, the taxonomy-badge classes are
 * appended so venue / city terms render as platform badges. Artists pass no
 * `taxonomy` and stay plain links.
 *
 * @param {Object}      props           Component props.
 * @param {Object}      props.term      Term object with `name`, `slug`, optional `url`.
 * @param {string}      props.className Base CSS class for the rendered element.
 * @param {string|null} props.taxonomy  Badge taxonomy: `venue` | `location`.
 * @return {Element|null} Rendered link/span, or null when nameless.
 */
const TermLink = ( { term, className, taxonomy = null } ) => {
	if ( ! term || ! term.name ) {
		return null;
	}

	const badge = badgeClasses( taxonomy, term.slug );
	// Badged terms drop the color-bearing local link class so its muted
	// color does not fight the badge's own background/color; the badge
	// classes fully own the visual. Unbadged terms (artists) keep their
	// original link class.
	const finalClass = badge ? badge : className;

	if ( term.url ) {
		return (
			<a className={ finalClass } href={ term.url }>
				{ term.name }
			</a>
		);
	}

	return <span className={ finalClass }>{ term.name }</span>;
};

const ShowCard = ( { show } ) => {
	const artists = show.artists && show.artists.length ? show.artists : null;
	const hasVenue = show.venue && show.venue.name;
	const hasCity = show.city && show.city.name;

	return (
		<div className="ec-concert-stats__show-card">
			<a
				className="ec-concert-stats__show-date"
				href={ show.permalink || '#' }
			>
				{ formatShortDate( show.event_date ) }
			</a>
			<span className="ec-concert-stats__show-details">
				<span className="ec-concert-stats__show-artist">
					{ artists ? (
						artists.map( ( artist, index ) => (
							<span key={ artist.slug || index }>
								{ index > 0 && (
									<span className="ec-concert-stats__show-sep">
										{ ', ' }
									</span>
								) }
								<TermLink
									term={ artist }
									className="ec-concert-stats__show-artist-link"
								/>
							</span>
						) )
					) : (
						<a
							className="ec-concert-stats__show-artist-link"
							href={ show.permalink || '#' }
						>
							{ show.title || '' }
						</a>
					) }
				</span>
				{ ( hasVenue || hasCity ) && (
					<span className="ec-concert-stats__show-venue">
						{ hasVenue && (
							<TermLink
								term={ show.venue }
								className="ec-concert-stats__show-venue-link"
								taxonomy="venue"
							/>
						) }
						{ hasVenue && hasCity && (
							<span className="ec-concert-stats__show-sep">
								{ ' \u00b7 ' }
							</span>
						) }
						{ hasCity && (
							<TermLink
								term={ show.city }
								className="ec-concert-stats__show-city-link"
								taxonomy="location"
							/>
						) }
					</span>
				) }
			</span>
		</div>
	);
};

export default ShowCard;
