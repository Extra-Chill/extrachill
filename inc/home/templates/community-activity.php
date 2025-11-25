<?php
/**
 * Legacy include for homepage community activity.
 *
 * @package ExtraChill
 * @since 1.0.0
 * @deprecated 1.0.0 Use extrachill_render_community_activity() directly.
 */

extrachill_render_community_activity(array(
    'render_wrapper' => false,
    'item_class'     => 'home-3x3-card home-3x3-community-card',
    'empty_class'    => 'home-3x3-card home-3x3-empty',
    'limit'          => 5,
));