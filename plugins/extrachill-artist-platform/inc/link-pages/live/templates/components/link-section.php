<?php
/**
 * Link Section Template (Live)
 *
 * Renders a section title and its links for public link pages.
 * Derived from pre-1.2.0 implementation (commit 7625e44...).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Expected args: section_title (string), links (array), link_page_id (int, optional)
$args = wp_parse_args( $args ?? array(), array(
    'section_title' => '',
    'links'        => array(),
    'link_page_id' => 0,
) );

$section_title = $args['section_title'];
$links         = is_array( $args['links'] ) ? $args['links'] : array();
$link_page_id  = (int) $args['link_page_id'];

// Filter links with required fields
$links_to_render = array();
foreach ( $links as $link_item ) {
    if ( empty( $link_item['link_url'] ) || empty( $link_item['link_text'] ) ) {
        continue;
    }
    $links_to_render[] = $link_item;
}
?>

<?php if ( $section_title !== '' ) : ?>
<div class="extrch-link-page-section-title"><?php echo esc_html( $section_title ); ?></div>
<?php endif; ?>

<div class="extrch-link-page-links">
    <?php foreach ( $links_to_render as $link_item ) :
        $link_classes    = 'extrch-link-page-link';
        $is_youtube_link = false;

        if ( $link_page_id && function_exists( 'extrachill_artist_is_youtube_embed_enabled' ) && extrachill_artist_is_youtube_embed_enabled( $link_page_id ) ) {
            $url = $link_item['link_url'];
            if ( strpos( $url, 'youtube.com' ) !== false || strpos( $url, 'youtu.be' ) !== false ) {
                $link_classes    .= ' extrch-youtube-embed-link';
                $is_youtube_link = true;
            }
        }

        $link_args = array(
            'link_url'     => $link_item['link_url'],
            'link_text'    => $link_item['link_text'],
            'link_classes' => $link_classes,
            'youtube_embed'=> $is_youtube_link,
        );

        echo ec_render_template( 'single-link', $link_args );
    endforeach; ?>
</div>
