<?php
/**
 * Meta Tags Output
 *
 * Handles title tag modification and meta description generation.
 * Uses WordPress native document_title_parts filter for title manipulation.
 *
 * @package ExtraChill\SEO
 */

namespace ExtraChill\SEO\Core;

// Prevent direct access.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Filter document title separator
 */
add_filter('document_title_separator', function () {
    return '-';
}, 999);

/**
 * Filter document title parts for site-specific patterns
 *
 * Modifies the site name suffix based on current blog context
 * and improves archive/pagination title context.
 */
add_filter('document_title_parts', function ($title_parts) {
    $blog_id = get_current_blog_id();

    // Site-specific suffixes
    $site_suffixes = [
        1  => 'Extra Chill',
        2  => 'Extra Chill Community',
        3  => 'Extra Chill Shop',
        4  => 'Extra Chill Artists',
        5  => 'Extra Chill Chat',
        7  => 'Extra Chill Events',
        8  => 'Extra Chill Stream',
        9  => 'Extra Chill Newsletter',
        10 => 'Extra Chill Docs',
        11 => 'Extra Chill Horoscope',
    ];

    // Set site name suffix
    if (isset($site_suffixes[$blog_id])) {
        $title_parts['site'] = $site_suffixes[$blog_id];
    }

    // Improve pagination context for blog archive
    if (is_home() && is_paged()) {
        $page_num = get_query_var('paged');
        $title_parts['title'] = sprintf('Blog - Page %d', $page_num);
    }

    // Improve category pagination context
    if (is_category() && is_paged()) {
        $page_num = get_query_var('paged');
        $cat_name = single_cat_title('', false);
        $title_parts['title'] = sprintf('%s - Page %d', $cat_name, $page_num);
    }

    // Improve tag pagination context
    if (is_tag() && is_paged()) {
        $page_num = get_query_var('paged');
        $tag_name = single_tag_title('', false);
        $title_parts['title'] = sprintf('%s - Page %d', $tag_name, $page_num);
    }

    // Improve author pagination context
    if (is_author() && is_paged()) {
        $page_num = get_query_var('paged');
        $author_name = get_the_author();
        $title_parts['title'] = sprintf('Posts by %s - Page %d', $author_name, $page_num);
    }

    return $title_parts;
});

/**
 * Output meta description tag
 */
add_action('wp_head', function () {
    $description = ec_seo_get_meta_description();

    if (!empty($description)) {
        printf(
            '<meta name="description" content="%s" />' . "\n",
            esc_attr($description)
        );
    }
}, 5);

/**
 * Generate meta description for current page
 *
 * Priority: Post excerpt > Auto-generated from content > Site tagline (homepage)
 *
 * @return string Meta description (max 160 chars)
 */
function ec_seo_get_meta_description() {
    if (is_singular()) {
        $post = get_queried_object();

        // Use excerpt if available
        if (!empty($post->post_excerpt)) {
            return ec_seo_truncate_description($post->post_excerpt);
        }

        // Generate from content
        $content = wp_strip_all_tags($post->post_content);
        $content = str_replace(["\n", "\r", "\t"], ' ', $content);
        $content = preg_replace('/\s+/', ' ', $content);

        return ec_seo_truncate_description($content);
    }

    if (is_front_page() || is_home()) {
        return ec_seo_truncate_description(get_bloginfo('description'));
    }

    if (is_category() || is_tag()) {
        $term = get_queried_object();
        if (!empty($term->description)) {
            return ec_seo_truncate_description($term->description);
        }
    }

    if (is_author()) {
        $author = get_queried_object();
        $bio = get_the_author_meta('description', $author->ID);
        if (!empty($bio)) {
            return ec_seo_truncate_description($bio);
        }
    }

    return '';
}

/**
 * Truncate description to 160 characters at word boundary
 *
 * @param string $text Text to truncate
 * @return string Truncated text
 */
function ec_seo_truncate_description($text) {
    $text = trim(wp_strip_all_tags($text));

    if (strlen($text) <= 160) {
        return $text;
    }

    $text = substr($text, 0, 157);
    $last_space = strrpos($text, ' ');

    if ($last_space !== false) {
        $text = substr($text, 0, $last_space);
    }

    return $text . '...';
}
