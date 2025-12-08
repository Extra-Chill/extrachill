<?php
/**
 * Social Icon Template (Live)
 *
 * Renders a single social icon link for public link pages.
 * Derived from pre-1.2.0 implementation (commit 7625e44...).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$args = wp_parse_args( $args ?? array(), array(
    'social_data'    => array(),
    'social_manager' => null,
) );

$social_data    = $args['social_data'];
$social_manager = $args['social_manager'];

if ( empty( $social_data['url'] ) || empty( $social_data['type'] ) ) {
    return;
}

if ( ! $social_manager && function_exists( 'extrachill_artist_platform_social_links' ) ) {
    $social_manager = extrachill_artist_platform_social_links();
}

if ( ! $social_manager ) {
    return;
}

$icon_class = $social_manager->get_icon_class( $social_data['type'], $social_data );
$label      = $social_manager->get_link_label( $social_data );

if ( empty( $icon_class ) ) {
    return;
}
?>
<a href="<?php echo esc_url( $social_data['url'] ); ?>"
   class="extrch-social-icon"
   target="_blank"
   rel="noopener noreferrer"
   title="<?php echo esc_attr( $label ); ?>"
   aria-label="<?php echo esc_attr( $label ); ?>">
    <i class="<?php echo esc_attr( $icon_class ); ?>"></i>
</a>
