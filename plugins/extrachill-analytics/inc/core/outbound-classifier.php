<?php
/**
 * Outbound-Click Destination Classifier
 *
 * Maps an external (off-network) destination host to a small, fixed set of
 * actionable categories so outbound-click data can be rolled up by *kind* of
 * destination rather than only by raw host. This is the server-side
 * counterpart consumed by the get-outbound-clicks read ability; the client
 * handler only sends the raw destination host + URL, and classification happens
 * once, canonically, at read time (and is also stamped at write time by the
 * capture route so a stored row carries its category).
 *
 * Categories (kept deliberately simple and documented — extend the substring
 * lists below, they are the whole contract):
 *
 *   - spotify    : Spotify (open.spotify.com, spotify.com, spoti.fi).
 *   - social     : the major social platforms a reader exits to.
 *   - ticketing  : ticketing / live-event commerce.
 *   - artist-site: bandcamp / linktree-style artist destinations (best-effort —
 *                  most independent artist sites are bespoke domains we cannot
 *                  enumerate, so this captures the common aggregators only).
 *   - merch      : merch / store destinations.
 *   - other      : everything else (the honest default — most of the long tail).
 *
 * The lists are filterable so the patterns are not behaviour-changing magic
 * literals buried in code: a host substring match (case-insensitive) wins in
 * the documented order above.
 *
 * @package ExtraChill\Analytics
 * @since 0.17.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * The ordered category => host-substring map used to classify a destination.
 *
 * First matching category (in array order) wins. Matching is a case-insensitive
 * substring test against the destination host. Filterable via
 * `extrachill_analytics_outbound_category_patterns`.
 *
 * @return array<string, string[]> Ordered map of category => host substrings.
 */
function extrachill_analytics_outbound_category_patterns() {
	$patterns = array(
		'spotify'     => array(
			'open.spotify.com',
			'spotify.com',
			'spoti.fi',
		),
		'social'      => array(
			'instagram.com',
			'facebook.com',
			'fb.com',
			'twitter.com',
			'x.com',
			'tiktok.com',
			'youtube.com',
			'youtu.be',
			'threads.net',
			'bsky.app',
			'reddit.com',
			'snapchat.com',
			'pinterest.com',
			'linkedin.com',
		),
		'ticketing'   => array(
			'ticketmaster.com',
			'livenation.com',
			'eventbrite.com',
			'dice.fm',
			'seetickets.us',
			'seetickets.com',
			'axs.com',
			'etix.com',
			'ticketweb.com',
			'showclix.com',
			'tixr.com',
			'stubhub.com',
			'seatgeek.com',
		),
		'artist-site' => array(
			'bandcamp.com',
			'linktr.ee',
			'soundcloud.com',
			'music.apple.com',
			'tidal.com',
			'audiomack.com',
			'bandsintown.com',
		),
		'merch'       => array(
			'shopify.com',
			'bigcartel.com',
			'merchbar.com',
			'teespring.com',
			'storenvy.com',
		),
	);

	/**
	 * Filter the ordered outbound destination category => host-substring map.
	 *
	 * First matching category (in array order) wins; matching is a
	 * case-insensitive substring test against the destination host.
	 *
	 * @param array<string, string[]> $patterns Ordered category => host substrings.
	 */
	return apply_filters( 'extrachill_analytics_outbound_category_patterns', $patterns );
}

/**
 * Classify an external destination host into an outbound category.
 *
 * @param string $dest_host The destination host (e.g. "open.spotify.com"). May
 *                          be a full URL; the host is extracted defensively.
 * @return string One of: spotify|social|ticketing|artist-site|merch|other.
 */
function extrachill_analytics_classify_outbound_host( $dest_host ) {
	$dest_host = strtolower( trim( (string) $dest_host ) );

	if ( '' === $dest_host ) {
		return 'other';
	}

	// Defensive: if a full URL slipped in, reduce to its host.
	if ( false !== strpos( $dest_host, '://' ) ) {
		$parsed    = wp_parse_url( $dest_host );
		$dest_host = isset( $parsed['host'] ) ? strtolower( $parsed['host'] ) : $dest_host;
	}

	foreach ( extrachill_analytics_outbound_category_patterns() as $category => $needles ) {
		foreach ( (array) $needles as $needle ) {
			if ( '' !== $needle && false !== strpos( $dest_host, strtolower( (string) $needle ) ) ) {
				return (string) $category;
			}
		}
	}

	return 'other';
}
