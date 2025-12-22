<?php
/**
 * Article Schema
 *
 * Outputs Article schema for singular posts.
 * Links to Organization as publisher and WebSite as isPartOf.
 *
 * @package ExtraChill\SEO
 */

namespace ExtraChill\SEO\Schema;

// Prevent direct access.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Add Article schema to graph for singular posts
 */
add_filter('extrachill_seo_schema_graph', function ($graph) {
    if (!is_singular('post')) {
        return $graph;
    }

    $post = get_queried_object();
    $base_url = ec_seo_get_schema_base_url();
    $permalink = get_permalink($post->ID);

    $article = [
        '@type'         => 'Article',
        '@id'           => $permalink . '#article',
        'isPartOf'      => [
            '@id' => $base_url . '/#website',
        ],
        'author'        => ec_seo_get_article_author($post),
        'headline'      => get_the_title($post->ID),
        'datePublished' => get_the_date('c', $post),
        'dateModified'  => get_the_modified_date('c', $post),
        'mainEntityOfPage' => [
            '@id' => $permalink . '#webpage',
        ],
        'publisher'     => [
            '@id' => $base_url . '/#organization',
        ],
        'inLanguage'    => 'en-US',
    ];

    // Add featured image
    if (has_post_thumbnail($post->ID)) {
        $image_id = get_post_thumbnail_id($post->ID);
        $image = wp_get_attachment_image_src($image_id, 'full');

        if ($image) {
            $article['image'] = [
                '@type'  => 'ImageObject',
                'url'    => $image[0],
                'width'  => $image[1],
                'height' => $image[2],
            ];
        }
    }

    // Add word count
    $word_count = str_word_count(wp_strip_all_tags($post->post_content));
    if ($word_count > 0) {
        $article['wordCount'] = $word_count;
    }

    $graph[] = $article;

    return $graph;
});

/**
 * Get author data for article schema
 *
 * @param WP_Post $post Post object
 * @return array Author schema data
 */
function ec_seo_get_article_author($post) {
    $author_id = $post->post_author;
    $author_name = get_the_author_meta('display_name', $author_id);
    $author_url = get_author_posts_url($author_id);

    $author = [
        '@type' => 'Person',
        'name'  => $author_name,
        'url'   => $author_url,
    ];

    // Add author image if available
    $avatar_url = get_avatar_url($author_id, ['size' => 96]);
    if ($avatar_url) {
        $author['image'] = $avatar_url;
    }

    return $author;
}
