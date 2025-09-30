<?php
/**
 * Theme rewrite adjustments.
 *
 * Provides consistent permalink structures for the ExtraChill theme across
 * multisite environments.
 *
 * @package ExtraChill
 * @since 69.57
 */

/**
 * Force WordPress to use a blank category base so category archives remain
 * at the root level (e.g. /news/) regardless of network permalink resets.
 *
 * @return string
 */
function extrachill_force_category_base() {
    return '';
}
add_filter( 'pre_option_category_base', 'extrachill_force_category_base' );
add_filter( 'pre_update_option_category_base', 'extrachill_force_category_base' );
