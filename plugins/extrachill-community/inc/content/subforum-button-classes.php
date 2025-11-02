<?php
/**
 * Subforum Button Styling
 *
 * @package ExtraChill\Community
 */

/**
 * Add button-3 button-small classes to subforum links.
 *
 * @param array $classes Existing classes
 * @param int   $forum_id Forum ID
 * @return array Modified classes
 */
add_filter( 'bbp_list_forums_subforum_classes', 'extrachill_subforum_button_classes', 10, 2 );
function extrachill_subforum_button_classes( $classes, $forum_id ) {
    $classes[] = 'button-3';
    $classes[] = 'button-small';
    return $classes;
}
