<?php
/**
 * Archive Header
 *
 * Displays archive title, taxonomy descriptions, and author bios.
 * Supports categories, tags, custom taxonomies, post type archives, authors, and date archives.
 * Provides extrachill_after_author_bio action hook for plugin extensibility on author archives.
 *
 * @package ExtraChill
 * @since 69.58
 */
?>

<header class="page-header">
    <h1 class="page-title">
        <?php
        if (is_category()) {
            single_cat_title();
        } elseif (is_tag()) {
            single_tag_title();
        } elseif (is_tax()) {
            // Custom taxonomy archives (Artist, Venue, Festival, etc.)
            single_term_title();
        } elseif (is_post_type_archive()) {
            // Custom post type archives
            post_type_archive_title();
        } elseif (is_author()) {
            the_post();
            $archive_author_name = get_the_author();
            rewind_posts();
            printf(
                '<span class="archive-author-label">%s <span class="vcard">%s</span></span>',
                esc_html__('Author:', 'extrachill'),
                esc_html($archive_author_name)
            );
        } elseif (is_day()) {
            printf(__('Day: %s', 'extrachill'), get_the_date());
        } elseif (is_month()) {
            printf(__('Month: %s', 'extrachill'), get_the_date('F Y'));
        } elseif (is_year()) {
            printf(__('Year: %s', 'extrachill'), get_the_date('Y'));
        } else {
            _e('Archives', 'extrachill');
        }
        ?>
    </h1>
</header><!-- .page-header -->

<?php
if (!is_paged()) {
    $term_description = term_description();
    if (!empty($term_description)) {
        printf('<div class="taxonomy-description">%s</div>', wp_kses_post($term_description));
    }

    if (is_author()) {
        $author_bio = get_the_author_meta('description');
        if (!empty($author_bio)) {
            echo '<div class="author-bio">' . wpautop($author_bio) . '</div>';
        }
        do_action('extrachill_after_author_bio', get_queried_object_id());
    }
}
?>
