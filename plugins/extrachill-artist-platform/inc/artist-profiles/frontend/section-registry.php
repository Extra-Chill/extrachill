<?php
/**
 * Artist Profile Section/Tab Registry ("the hub")
 *
 * The single-artist_profile template no longer hardcodes its rendered blocks.
 * Instead each block is a registered section, and the template loops over the
 * registered sections ordered by priority. This is the seam every future
 * profile surface (Shows, Community, Instagram, ...) plugs into: a section
 * self-registers from its OWNING plugin (layer purity — AP core never names a
 * foreign plugin), declaring how it renders.
 *
 * Filter contract (long-lived; treat as a public API):
 *
 *   apply_filters( 'ec_artist_profile_sections', array $sections, int $artist_id, int $artist_term_id )
 *
 * Each section is an associative array:
 *
 *   [
 *     'id'       => (string)   unique section id, e.g. 'overview', 'coverage'
 *     'label'    => (string)   human label (used as the tab title when tabbed)
 *     'priority' => (int)      sort order (lower renders first); default 10
 *     'render'   => (callable) receives ( int $artist_id, int $artist_term_id )
 *                              and ECHOES its markup server-side (no AJAX)
 *     'visible'  => (callable) optional; receives ( int $artist_id, int $artist_term_id )
 *                              and returns bool — falsey hides the section
 *     'as_tab'   => (bool)     optional; reserved for tabbed layout. The
 *                              template decides layout from priority/as_tab;
 *                              the registry never imposes tabs-vs-stacked.
 *   ]
 *
 * The third filter arg, $artist_term_id, is the bound main-blog `artist` term
 * (Primitive 1). Term-scoped sections (Shows/Coverage/Community) use it to query
 * other network surfaces via ec_cross_site_rest_request().
 *
 * @package ExtraChillArtistPlatform
 */

defined( 'ABSPATH' ) || exit;

/**
 * Get the registered, ordered, visible sections for an artist profile.
 *
 * @param int      $artist_id      Artist profile post ID.
 * @param int|null $artist_term_id Bound main-blog `artist` term_id. Resolved
 *                                 from the binding when null.
 * @return array[] Ordered list of normalized section arrays.
 */
function ec_get_artist_profile_sections( $artist_id, $artist_term_id = null ) {
	$artist_id = (int) $artist_id;
	if ( $artist_id <= 0 ) {
		return array();
	}

	if ( null === $artist_term_id ) {
		$artist_term_id = function_exists( 'ec_get_artist_term_id' ) ? ec_get_artist_term_id( $artist_id ) : 0;
	}
	$artist_term_id = (int) $artist_term_id;

	/**
	 * Filter the artist profile sections.
	 *
	 * @param array[] $sections       Registered sections.
	 * @param int     $artist_id      Artist profile post ID.
	 * @param int     $artist_term_id Bound main-blog `artist` term_id (0 if unbound).
	 */
	$sections = apply_filters( 'ec_artist_profile_sections', array(), $artist_id, $artist_term_id );

	if ( ! is_array( $sections ) ) {
		return array();
	}

	// Normalize, drop invalid entries, apply visibility gating.
	$normalized = array();
	foreach ( $sections as $section ) {
		if ( ! is_array( $section ) || empty( $section['id'] ) || empty( $section['render'] ) || ! is_callable( $section['render'] ) ) {
			continue;
		}

		$section['id']       = (string) $section['id'];
		$section['label']    = isset( $section['label'] ) ? (string) $section['label'] : '';
		$section['priority'] = isset( $section['priority'] ) ? (int) $section['priority'] : 10;
		$section['as_tab']   = ! empty( $section['as_tab'] );

		if ( isset( $section['visible'] ) && is_callable( $section['visible'] ) ) {
			if ( ! call_user_func( $section['visible'], $artist_id, $artist_term_id ) ) {
				continue;
			}
		}

		$normalized[] = $section;
	}

	// Stable sort by priority.
	usort( $normalized, function ( $a, $b ) {
		if ( $a['priority'] === $b['priority'] ) {
			return 0;
		}
		return ( $a['priority'] < $b['priority'] ) ? -1 : 1;
	} );

	return $normalized;
}

/**
 * Render all registered sections for an artist profile, in priority order.
 *
 * Each section's render callable echoes server-side. For THIS foundation the
 * template keeps the existing stacked layout (no tabs), so every section is
 * rendered in sequence. Tabbed rendering (using the theme shared-tabs primitive
 * or @extrachill/components Tabs) is a later layer that consumes `as_tab`.
 *
 * @param int      $artist_id      Artist profile post ID.
 * @param int|null $artist_term_id Bound main-blog `artist` term_id.
 * @return void
 */
function ec_render_artist_profile_sections( $artist_id, $artist_term_id = null ) {
	$artist_id = (int) $artist_id;
	if ( $artist_id <= 0 ) {
		return;
	}

	if ( null === $artist_term_id ) {
		$artist_term_id = function_exists( 'ec_get_artist_term_id' ) ? ec_get_artist_term_id( $artist_id ) : 0;
	}
	$artist_term_id = (int) $artist_term_id;

	$sections = ec_get_artist_profile_sections( $artist_id, $artist_term_id );
	foreach ( $sections as $section ) {
		call_user_func( $section['render'], $artist_id, $artist_term_id );
	}
}
