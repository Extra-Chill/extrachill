<?php
/**
 * Rewrite Rules
 *
 * Forces blank category base to keep category archives at root level (e.g., /news/).
 *
 * @package ExtraChill
 * @since 69.58
 */

function extrachill_force_category_base() {
    return '';
}
add_filter( 'pre_option_category_base', 'extrachill_force_category_base' );
add_filter( 'pre_update_option_category_base', 'extrachill_force_category_base' );
