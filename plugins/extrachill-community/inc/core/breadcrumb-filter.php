<?php
/**
 * bbPress Breadcrumb Customization
 *
 * Removes redundant "Forums" root link from bbPress breadcrumbs since
 * community.extrachill.com homepage IS the forums.
 *
 * @package ExtraChillCommunity
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Filter bbPress breadcrumbs to remove redundant "Forums" root link
 *
 * Since community.extrachill.com homepage is the forum archive, the "Forums"
 * breadcrumb link is redundant. This filter removes it from the crumbs array.
 *
 * Before: Home › Forums › Carolina Reefer
 * After:  Home › Carolina Reefer
 *
 * @param array $crumbs Array of breadcrumb HTML strings
 * @return array Modified breadcrumb array with Forums root link removed
 * @since 1.0.0
 */
function extrachill_community_filter_breadcrumbs( $crumbs ) {
	// Remove the "Forums" root link - homepage IS the forums
	// The root link has the class "bbp-breadcrumb-root"
	return array_values( array_filter( $crumbs, function( $crumb ) {
		return strpos( $crumb, 'bbp-breadcrumb-root' ) === false;
	} ) );
}
add_filter( 'bbp_breadcrumbs', 'extrachill_community_filter_breadcrumbs' );
