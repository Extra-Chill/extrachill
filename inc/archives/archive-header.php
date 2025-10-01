<?php
/**
 * Archive Header Component
 *
 * Displays archive page header with breadcrumbs and title.
 * Handles all archive types: category, tag, author, date, taxonomy, post type.
 *
 * @package ExtraChill
 * @since 1.0
 */

// Determine the correct archive link based on the type of archive
$archive_link = '';
if (is_category()) {
    $archive_link = get_category_link(get_queried_object_id());
} elseif (is_tag()) {
    $archive_link = get_tag_link(get_queried_object_id());
} elseif (is_author()) {
    $archive_link = get_author_posts_url(get_queried_object_id());
} elseif (is_day()) {
    $archive_link = get_day_link(get_query_var('year'), get_query_var('monthnum'), get_query_var('day'));
} elseif (is_month()) {
    $archive_link = get_month_link(get_query_var('year'), get_query_var('monthnum'));
} elseif (is_year()) {
    $archive_link = get_year_link(get_query_var('year'));
} else {
    $archive_link = get_post_type_archive_link(get_post_type());
}
?>

<header class="page-header">
    <h1 class="page-title">
        <a href="<?php echo esc_url($archive_link); ?>">
            <span>
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
            </span>
        </a>
    </h1>
</header><!-- .page-header -->

<?php
// Optional Term and Author Description
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
    }
}
?>
