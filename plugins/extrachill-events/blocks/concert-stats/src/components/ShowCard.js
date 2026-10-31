/**
 * ShowCard — Single show entry in the list.
 *
 * The whole row opens the show. The show name is the row's one event link,
 * stretched over the card with a CSS overlay (`::after`), so tapping
 * anywhere on the row lands on the event page, the way a list item behaves
 * on a phone. Anchors cannot nest, so the venue / city badges, the rating
 * stars, and the RSVP pass sit above that overlay as their own targets:
 * the badges are still on-ramps into the venue and location archives.
 *
 * Artist names are part of the show name, not separate links. A fan reading
 * their history taps the bold name to get back to the show they went to;
 * linking it to the artist sent them to the artist's tour page instead.
 * The event page links every artist.
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
import RsvpPassBadge from './RsvpPassBadge';
import ShowRating from './ShowRating';

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
 * Render a venue / city term as a taxonomy-badge link when it has a
 * resolvable URL, falling back to a plain badge otherwise.
 *
 * @param {Object} props           Component props.
 * @param {Object} props.term      Term object with `name`, `slug`, optional `url`.
 * @param {string} props.className Base CSS class for the rendered element.
 * @param {string} props.taxonomy  Badge taxonomy: `venue` | `location`.
 * @return {Element|null} Rendered link/span, or null when nameless.
 */
const TermLink = ( { term, className, taxonomy } ) => {
	if ( ! term || ! term.name ) {
		return null;
	}

	// The badge classes own the visual; the local link class would fight
	// the badge's own background/color.
	const finalClass = badgeClasses( taxonomy, term.slug ) || className;

	if ( term.url ) {
		return (
			<a className={ finalClass } href={ term.url }>
				{ term.name }
			</a>
		);
	}

	return <span className={ finalClass }>{ term.name }</span>;
};

/**
 * @param {Object}  props          Component props.
 * @param {Object}  props.show     Show data.
 * @param {boolean} [props.isOwn]  Whether the viewer owns this My Shows page.
 *                                 An RSVP pass is private to its holder —
 *                                 only rendered for the owner's own view.
 * @param {?Object} [props.rating] Owner-only rating controls for a past show:
 *                                 { value, error, onRate }.
 */
const ShowCard = ( { show, isOwn = false, rating = null } ) => {
	const artists = show.artists && show.artists.length ? show.artists : null;
	const hasVenue = show.venue && show.venue.name;
	const hasCity = show.city && show.city.name;

	const name = artists
		? artists.map( ( artist ) => artist.name ).join( ', ' )
		: show.title || '';
	const date = formatShortDate( show.event_date );

	return (
		<div
			className={
				'ec-concert-stats__show-card' +
				( show.permalink ? ' has-link' : '' )
			}
		>
			<span className="ec-concert-stats__show-date">{ date }</span>
			<span className="ec-concert-stats__show-details">
				<span className="ec-concert-stats__show-artist">
					{ show.permalink ? (
						<a
							className="ec-concert-stats__show-link"
							href={ show.permalink }
						>
							{ name }
						</a>
					) : (
						name
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
				{ rating && (
					<ShowRating
						eventId={ show.event_id }
						rating={ rating.value || 0 }
						error={ rating.error }
						onRate={ rating.onRate }
						title={ show.title }
					/>
				) }
			</span>
			{ isOwn && 'past' !== show.timing && (
				<RsvpPassBadge eventId={ show.event_id } />
			) }
		</div>
	);
};

export default ShowCard;
