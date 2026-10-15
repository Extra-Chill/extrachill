<?php
/**
 * Campaign tagging for newsletter email links.
 *
 * Email clients send no referrer, so a click from a newsletter lands on the
 * site as direct traffic. Tagging every link to an Extra Chill property with
 * utm_source/utm_medium/utm_campaign gives the analytics beacon the only
 * provenance those visits can carry. External links (Automattic, Meetup, ...)
 * are left untouched: their analytics are not ours to annotate.
 *
 * @package ExtraChillNewsletter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether a host belongs to the Extra Chill network.
 *
 * @param string $host URL host.
 * @return bool
 */
function extrachill_newsletter_is_network_host( $host ) {
	$host = strtolower( (string) $host );
	foreach ( array( 'extrachill.com', 'extrachill.link' ) as $root ) {
		if ( $host === $root || substr( $host, -strlen( '.' . $root ) ) === '.' . $root ) {
			return true;
		}
	}

	return false;
}

/**
 * Campaign parameters for one newsletter post.
 *
 * @param WP_Post $post Newsletter post.
 * @return array<string,string> utm_* parameters.
 */
function extrachill_newsletter_campaign_utm_params( $post ) {
	$slug   = ! empty( $post->post_name ) ? (string) $post->post_name : 'newsletter-' . (int) $post->ID;
	$params = array(
		'utm_source'   => 'newsletter',
		'utm_medium'   => 'email',
		'utm_campaign' => $slug,
	);

	/**
	 * Filter the campaign parameters appended to network links in a newsletter.
	 *
	 * Return an empty array to disable tagging.
	 *
	 * @param array   $params utm_* parameters.
	 * @param WP_Post $post   Newsletter post.
	 */
	return (array) apply_filters( 'extrachill_newsletter_campaign_utm_params', $params, $post );
}

/**
 * Add campaign parameters to one URL when it points at the network.
 *
 * Links that already carry utm_source are left alone, so an author can tag a
 * link by hand. The fragment stays at the end.
 *
 * @param string $url    URL (raw, not HTML-encoded).
 * @param array  $params utm_* parameters.
 * @return string
 */
function extrachill_newsletter_tag_url( $url, array $params ) {
	if ( empty( $params ) ) {
		return $url;
	}

	$parts = parse_url( $url );
	if ( empty( $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ?? '' ), array( 'http', 'https' ), true ) ) {
		return $url;
	}
	if ( ! extrachill_newsletter_is_network_host( $parts['host'] ) ) {
		return $url;
	}

	$query = array();
	if ( isset( $parts['query'] ) ) {
		parse_str( $parts['query'], $query );
	}
	if ( isset( $query['utm_source'] ) ) {
		return $url;
	}

	$fragment = '';
	$hash_pos = strpos( $url, '#' );
	if ( false !== $hash_pos ) {
		$fragment = substr( $url, $hash_pos );
		$url      = substr( $url, 0, $hash_pos );
	}

	if ( empty( $parts['path'] ) && empty( $parts['query'] ) ) {
		$url .= '/';
	}

	$separator = false === strpos( $url, '?' ) ? '?' : ( '?' === substr( $url, -1 ) || '&' === substr( $url, -1 ) ? '' : '&' );

	return $url . $separator . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 ) . $fragment;
}

/**
 * Tag every network href in a rendered newsletter email.
 *
 * @param string $html   Email HTML.
 * @param array  $params utm_* parameters.
 * @return string
 */
function extrachill_newsletter_tag_email_links( $html, array $params ) {
	if ( empty( $params ) ) {
		return $html;
	}

	return (string) preg_replace_callback(
		'/(<a\b[^>]*?\bhref=)(["\'])(.*?)\2/i',
		function ( $matches ) use ( $params ) {
			$raw    = html_entity_decode( $matches[3], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$tagged = extrachill_newsletter_tag_url( $raw, $params );
			if ( $tagged === $raw ) {
				return $matches[0];
			}

			return $matches[1] . $matches[2] . htmlspecialchars( $tagged, ENT_QUOTES, 'UTF-8', false ) . $matches[2];
		},
		$html
	);
}
