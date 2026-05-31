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
 * Anchors cannot nest, so the card is intentionally NOT a single wrapping
 * <a>. The row is a plain container; the date carries the event-permalink
 * link and each term name is its own sibling link.
 *
 * @package
 */

import { formatShortDate } from '../utils/formatDate';

/**
 * Render a single term (artist / venue / city) as a link when it has a
 * resolvable URL, falling back to plain text otherwise.
 *
 * @param {Object} props           Component props.
 * @param {Object} props.term      Term object with `name` and optional `url`.
 * @param {string} props.className CSS class for the rendered element.
 * @return {JSX.Element|null} Rendered link/span, or null when nameless.
 */
const TermLink = ( { term, className } ) => {
	if ( ! term || ! term.name ) {
		return null;
	}

	if ( term.url ) {
		return (
			<a className={ className } href={ term.url }>
				{ term.name }
			</a>
		);
	}

	return <span className={ className }>{ term.name }</span>;
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
							/>
						) }
					</span>
				) }
			</span>
		</div>
	);
};

export default ShowCard;
