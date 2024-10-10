<?php
/**
 * Remove duplicate images from Yoast sitemap.
 *
 * @param array  $images       Array of images to include in sitemap.
 * @param string $post_id      Post ID.
 *
 * @return array Modified array of images.
 */
function filter_yoast_sitemap_images($images, $post_id) {
    // Get the URL of the featured image.
    $featured_image_url = get_the_post_thumbnail_url($post_id);

    // If there's no featured image, return the original images array.
    if (!$featured_image_url) {
        return $images;
    }

    // Get the post content.
    $post_content = get_post_field('post_content', $post_id);

    // Check if the featured image is already in the post content.
    if (strpos($post_content, $featured_image_url) !== false) {
        // If so, remove the featured image from the images array.
        foreach ($images as $key => $image) {
            if ($image['src'] === $featured_image_url) {
                unset($images[$key]);
                break; // Since there's only one featured image, we can break the loop early.
            }
        }
    }

    return $images;
}
add_filter('wpseo_sitemap_urlimages', 'filter_yoast_sitemap_images', 10, 2);
