<?php
/**
 * Link Section Template
 * 
 * Renders a complete section with title and links
 * Used by both live pages and preview system
 * 
 * @param array $args {
 *     @type string $section_title The section title (optional)
 *     @type array $links Array of link data with 'link_url' and 'link_text' keys
 *     @type bool $share_enabled Whether to include share buttons on links (default: true)
 *     @type int $link_page_id Link page ID for YouTube embed checking (optional)
 * }
 */

// Default arguments
$args = wp_parse_args($args, array(
    'section_title' => '',
    'links' => array(),
    'share_enabled' => true,
    'link_page_id' => 0
));

// Extract variables
$section_title = $args['section_title'];
$links = $args['links'];
$share_enabled = $args['share_enabled'];
$link_page_id = $args['link_page_id'];

// Ensure links is an array for processing
if (!is_array($links)) {
    $links = array();
}

// Filter links to render
$links_to_render = array();
$has_links_after_filter = false;

foreach ($links as $link_item) {
    if (empty($link_item['link_url']) || empty($link_item['link_text'])) {
        continue;
    }
    
    $has_links_after_filter = true;
    $links_to_render[] = $link_item;
}

// Continue rendering even if no links after filtering - section title should still show
?>

<?php if (!empty($section_title)): ?>
    <div class="extrch-link-page-section-title"><?php echo esc_html($section_title); ?></div>
<?php endif; ?>

<div class="extrch-link-page-links">
    <?php foreach ($links_to_render as $link_item): 
        
        // Determine link classes and YouTube embed status
        $link_classes = "extrch-link-page-link";
        $is_youtube_link = false;
        
        // Check for YouTube embed functionality
        if ($link_page_id && function_exists('extrch_is_youtube_embed_enabled') && extrch_is_youtube_embed_enabled($link_page_id)) {
            if (strpos($link_item['link_url'], 'youtube.com') !== false || strpos($link_item['link_url'], 'youtu.be') !== false) {
                $link_classes .= " extrch-youtube-embed-link";
                $is_youtube_link = true;
            }
        }
        
        // Render single link using unified template system
        $link_args = array(
            'link_url' => $link_item['link_url'],
            'link_text' => $link_item['link_text'],
            'share_enabled' => $share_enabled,
            'link_classes' => $link_classes,
            'youtube_embed' => $is_youtube_link
        );
        
        echo ec_render_template('single-link', $link_args);
        
    endforeach; ?>
</div>