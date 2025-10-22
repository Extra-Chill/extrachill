<?php
/**
 * Render callback for the image voting block
 *
 * @param array $attributes Block attributes
 * @param string $content Block content
 * @param WP_Block $block Block instance
 * @return string Block HTML output
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

$title = isset($attributes['blockTitle']) ? $attributes['blockTitle'] : 'Vote for this image';
$vote_count = isset($attributes['voteCount']) ? (int) $attributes['voteCount'] : 0;
$media_id = isset($attributes['mediaID']) ? (int) $attributes['mediaID'] : 0;
$media_url = isset($attributes['mediaURL']) ? $attributes['mediaURL'] : '';

// Get image URL if we have a media ID but no URL
if ($media_id && empty($media_url)) {
    $media_url = wp_get_attachment_url($media_id);
}

// Get current post ID - this is the key fix
$post_id = get_the_ID();
if (!$post_id && is_admin()) {
    // In editor context, try to get from global
    global $post;
    $post_id = isset($post->ID) ? $post->ID : 0;
}

// Generate consistent block instance ID that matches the AJAX handler expectation
$block_instance_id = 'block-' . $post_id . '-' . substr(md5(serialize($attributes)), 0, 8);

// Get block wrapper attributes
$wrapper_attributes = get_block_wrapper_attributes([
    'class' => 'extrachill-blocks-image-voting-container',
    'data-block-instance-id' => esc_attr($block_instance_id),
    'data-post-id' => esc_attr($post_id)
]);

// Generate HTML markup
ob_start();
?>
<div <?php echo $wrapper_attributes; ?>>
    <h2 class="extrachill-blocks-image-voting-title"><?php echo esc_html($title); ?></h2>
    <?php if (!empty($media_url)): ?>
        <img class="extrachill-blocks-image-voting-image" src="<?php echo esc_url($media_url); ?>" alt="<?php echo esc_attr($title); ?>" />
    <?php endif; ?>
    <?php if (!is_admin()): ?>
        <button class="extrachill-blocks-image-voting-button" data-block-instance-id="<?php echo esc_attr($block_instance_id); ?>">
            Vote for <?php echo esc_html($title); ?>
        </button>
        <div class="extrachill-blocks-image-voting-form" style="display: none;">
            <input type="email" class="extrachill-blocks-email-input" placeholder="Enter your email to vote" required>
            <button class="extrachill-blocks-submit-vote">Submit Vote</button>
            <div class="extrachill-voting-message" style="display: none;"></div>
        </div>
    <?php endif; ?>
    <p class="extrachill-blocks-vote-count">Votes: <span class="vote-number"><?php echo esc_html($vote_count); ?></span></p>
</div>
<?php
return ob_get_clean();