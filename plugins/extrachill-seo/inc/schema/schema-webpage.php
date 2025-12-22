<?php
/**
 * WebPage Schema
 *
 * Outputs WebPage or CollectionPage schema based on page type.
 * Links to WebSite and BreadcrumbList schemas.
 *
 * @package ExtraChill\SEO
 */

namespace ExtraChill\SEO\Schema;

// Prevent direct access.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Add WebPage schema to graph
 */
add_filter('extrachill_seo_schema_graph', function ($graph) {
    $base_url = ec_seo_get_schema_base_url();
    $current_url = ec_seo_get_current_url();

    // Determine page type
    $page_type = 'WebPage';

    if (is_home() || is_archive()) {
        $page_type = 'CollectionPage';
    }

    if (is_search()) {
        $page_type = 'SearchResultsPage';
    }

    $webpage = [
        '@type'      => $page_type,
        '@id'        => $current_url . '#webpage',
        'url'        => $current_url,
        'name'       => wp_get_document_title(),
        'isPartOf'   => [
            '@id' => $base_url . '/#website',
        ],
        'inLanguage' => 'en-US',
    ];

    // Add breadcrumb reference for non-homepage
    if (!is_front_page()) {
        $webpage['breadcrumb'] = [
            '@id' => $current_url . '#breadcrumb',
        ];
    }

    // Add description if available
    $description = \ExtraChill\SEO\Core\ec_seo_get_meta_description();
    if (!empty($description)) {
        $webpage['description'] = $description;
    }

    // Add date info for singular content
    if (is_singular()) {
        $post = get_queried_object();
        $webpage['datePublished'] = get_the_date('c', $post);
        $webpage['dateModified'] = get_the_modified_date('c', $post);
    }

    // Add featured image for singular content
    if (is_singular() && has_post_thumbnail()) {
        $post = get_queried_object();
        $image_id = get_post_thumbnail_id($post->ID);
        $image = wp_get_attachment_image_src($image_id, 'full');

        if ($image) {
            $webpage['primaryImageOfPage'] = [
                '@type'  => 'ImageObject',
                '@id'    => $current_url . '#primaryimage',
                'url'    => $image[0],
                'width'  => $image[1],
                'height' => $image[2],
            ];
        }
    }

    $graph[] = $webpage;

    return $graph;
});
