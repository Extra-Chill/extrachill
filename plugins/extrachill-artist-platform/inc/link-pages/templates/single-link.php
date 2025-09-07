<?php
/**
 * Single Link Template
 * 
 * Renders a single link element with consistent HTML structure
 * Used by both live pages and preview system
 * 
 * @param array $args {
 *     @type string $link_url The URL for the link
 *     @type string $link_text The display text for the link
 *     @type string $link_classes Additional CSS classes (default: 'extrch-link-page-link')
 *     @type bool $youtube_embed Whether link has YouTube embed styling (default: false)
 * }
 */

// Default arguments
$args = wp_parse_args($args, array(
    'link_url' => '',
    'link_text' => '',
    'link_classes' => 'extrch-link-page-link',
    'youtube_embed' => false
));

// Extract variables
$link_url = $args['link_url'];
$link_text = $args['link_text'];
$link_classes = $args['link_classes'];
$youtube_embed = $args['youtube_embed'];

// Provide sensible defaults for empty links during editing
$display_url = !empty($link_url) ? $link_url : '#';
$display_text = !empty($link_text) ? $link_text : '';
$share_url = !empty($link_url) ? $link_url : '#';
$share_title = !empty($link_text) ? $link_text : 'Untitled Link';

// Add YouTube embed class if needed
if ($youtube_embed) {
    $link_classes .= ' extrch-youtube-embed-link';
}
?>

<a href="<?php echo esc_url($display_url); ?>" class="<?php echo esc_attr($link_classes); ?>" rel="noopener">
    <span class="extrch-link-page-link-text"><?php echo esc_html($display_text); ?></span>
    <span class="extrch-link-page-link-icon">
        <button class="extrch-share-trigger extrch-share-item-trigger" 
                aria-label="Share this link" 
                data-share-type="link"
                data-share-url="<?php echo esc_url($share_url); ?>" 
                data-share-title="<?php echo esc_attr($share_title); ?>">
            <i class="fas fa-ellipsis-v"></i>
        </button>
    </span>
</a>