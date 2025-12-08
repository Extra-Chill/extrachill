<?php
/**
 * Social Icons Container Template (Live)
 *
 * Renders a container of social icons for public link pages.
 * Derived from pre-1.2.0 implementation (commit 7625e44...).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$args = wp_parse_args( $args ?? array(), array(
    'social_links'   => array(),
    'position'       => 'above',
    'social_manager' => null,
) );

$social_links   = $args['social_links'];
$position       = $args['position'];
$social_manager = $args['social_manager'];

if ( empty( $social_links ) || ! is_array( $social_links ) ) {
    return;
}

if ( ! $social_manager && function_exists( 'extrachill_artist_platform_social_links' ) ) {
    $social_manager = extrachill_artist_platform_social_links();
}

if ( ! $social_manager ) {
    return;
}

$container_classes = 'extrch-link-page-socials';
if ( 'below' === $position ) {
    $container_classes .= ' extrch-socials-below';
}

$valid_social_links = array_filter( $social_links, function( $link ) {
    return ! empty( $link['url'] ) && ! empty( $link['type'] );
} );

if ( empty( $valid_social_links ) ) {
    return;
}
?>
<div class="<?php echo esc_attr( $container_classes ); ?>">
    <?php foreach ( $valid_social_links as $social_link ) :
        $icon_args = array(
            'social_data'    => $social_link,
            'social_manager' => $social_manager,
        );
        echo ec_render_template( 'social-icon', $icon_args );
    endforeach; ?>
</div>
