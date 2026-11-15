<?php
/**
 * Single public link and independent share control.
 *
 * @package ExtraChillLinkPages
 */

defined( 'ABSPATH' ) || exit;

/**
 * Variables provided by ec_render_link_page_section_with_featured().
 *
 * @var array<string, mixed> $link          Link with non-empty `link_url` and `link_text`.
 * @var bool                 $featured      Whether this is the page's featured link.
 * @var string               $thumbnail_url Featured thumbnail URL, or ''.
 * @var bool                 $youtube       Whether the link is an inline YouTube embed.
 */
?>
<div class="extrch-link-button-wrapper<?php echo $featured ? ' is-featured' : ''; ?>">
	<a href="<?php echo esc_url( $link['link_url'] ); ?>" class="extrch-link-page-link<?php echo $youtube ? ' extrch-youtube-embed-link' : ''; ?>" rel="ugc noopener"><?php echo $thumbnail_url ? '<img class="extrch-link-thumbnail" src="' . esc_url( $thumbnail_url ) . '" alt="" loading="lazy">' : ''; ?><span class="extrch-link-page-link-text"><?php echo esc_html( $link['link_text'] ); ?></span></a>
	<button type="button" class="extrch-share-trigger extrch-share-item-trigger" aria-label="Share this link" data-share-type="link" data-share-url="<?php echo esc_url( $link['link_url'] ); ?>" data-share-title="<?php echo esc_attr( $link['link_text'] ); ?>"><i class="fas fa-ellipsis-v"></i></button>
</div>
