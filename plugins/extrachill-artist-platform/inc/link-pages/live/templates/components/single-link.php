<?php
/**
 * Single Link Template (Live)
 *
 * Renders a single link with share trigger for public link pages.
 * Derived from pre-1.2.0 implementation (commit 7625e44...).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$args = wp_parse_args( $args ?? array(), array(
    'link_url'     => '',
    'link_text'    => '',
    'link_classes' => 'extrch-link-page-link',
    'youtube_embed'=> false,
) );

$link_url      = $args['link_url'];
$link_text     = $args['link_text'];
$link_classes  = $args['link_classes'];
$youtube_embed = (bool) $args['youtube_embed'];

$display_url   = ! empty( $link_url ) ? $link_url : '#';
$display_text  = ! empty( $link_text ) ? $link_text : '';
$share_url     = ! empty( $link_url ) ? $link_url : '#';
$share_title   = ! empty( $link_text ) ? $link_text : 'Untitled Link';

if ( $youtube_embed ) {
    $link_classes .= ' extrch-youtube-embed-link';
}
?>
<a href="<?php echo esc_url( $display_url ); ?>" class="<?php echo esc_attr( $link_classes ); ?>" rel="ugc noopener">
    <span class="extrch-link-page-link-text"><?php echo esc_html( $display_text ); ?></span>
    <span class="extrch-link-page-link-icon">
        <button class="extrch-share-trigger extrch-share-item-trigger"
                aria-label="Share this link"
                data-share-type="link"
                data-share-url="<?php echo esc_url( $share_url ); ?>"
                data-share-title="<?php echo esc_attr( $share_title ); ?>">
            <i class="fas fa-ellipsis-v"></i>
        </button>
    </span>
</a>
