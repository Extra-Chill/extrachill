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
 *     @type bool $share_enabled Whether to include share button (default: true)
 *     @type string $link_classes Additional CSS classes (default: 'extrch-link-page-link')
 *     @type bool $youtube_embed Whether link has YouTube embed styling (default: false)
 * }
 */

// Default arguments
$args = wp_parse_args($args, array(
    'link_url' => '',
    'link_text' => '',
    'share_enabled' => true,
    'link_classes' => 'extrch-link-page-link',
    'youtube_embed' => false
));

// Extract variables
$link_url = $args['link_url'];
$link_text = $args['link_text'];
$share_enabled = $args['share_enabled'];
$link_classes = $args['link_classes'];
$youtube_embed = $args['youtube_embed'];

// Don't render if missing required data
if (empty($link_url) || empty($link_text)) {
    return;
}

// Add YouTube embed class if needed
if ($youtube_embed) {
    $link_classes .= ' extrch-youtube-embed-link';
}
?>

<a href="<?php echo esc_url($link_url); ?>" class="<?php echo esc_attr($link_classes); ?>" rel="noopener">
    <span class="extrch-link-page-link-text"><?php echo esc_html($link_text); ?></span>
    <?php if ($share_enabled): ?>
        <span class="extrch-link-page-link-icon">
            <button class="extrch-share-trigger extrch-share-item-trigger" 
                    aria-label="Share this link" 
                    data-share-type="link"
                    data-share-url="<?php echo esc_url($link_url); ?>" 
                    data-share-title="<?php echo esc_attr($link_text); ?>">
                <i class="fas fa-ellipsis-v"></i>
            </button>
        </span>
    <?php endif; ?>
</a>