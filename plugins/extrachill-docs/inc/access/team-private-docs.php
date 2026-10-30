<?php
/**
 * Team-only documentation access.
 *
 * WordPress private Pages already provide the visibility boundary needed for
 * internal documentation. This maps the existing team access capability to
 * core's private-page read capability on the docs subsite only.
 *
 * @package ExtraChillDocs
 * @since   0.5.8
 */

defined( 'ABSPATH' ) || exit;

/**
 * Allow team members to read private documentation Pages.
 *
 * The team role owner enforces an exact capability set, so this integration
 * must not mutate that shared role. A runtime capability mapping also keeps
 * the grant scoped to the docs site where this plugin is active.
 *
 * @param array<string,bool> $allcaps User capabilities.
 * @return array<string,bool>
 */
function extrachill_docs_grant_team_private_page_access( array $allcaps ): array {
	if ( ! empty( $allcaps['access_studio'] ) ) {
		$allcaps['read_private_pages'] = true;
	}

	return $allcaps;
}
add_filter( 'user_has_cap', 'extrachill_docs_grant_team_private_page_access' );
