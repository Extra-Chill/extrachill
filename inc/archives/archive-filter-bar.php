<?php
/**
 * Archive Filter Bar
 *
 * Provides sorting dropdown and category/artist filters for archive pages.
 * Hook: extrachill_archive_filter_bar allows plugins to inject navigation buttons.
 *
 * @package ExtraChill
 * @since 1.0.0
 */

add_action('extrachill_archive_above_posts', 'extrachill_archive_filter_bar', 10);

function extrachill_archive_filter_bar() {
    if (!is_archive() && !get_query_var('extrachill_blog_archive')) {
        return;
    }

    $archive_link = '';

    if (get_query_var('extrachill_blog_archive')) {
        $archive_link = home_url('/blog/');
    } elseif (is_category()) {
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

    echo '<div id="extrachill-custom-sorting">';

    do_action( 'extrachill_archive_filter_bar' );

    if (get_query_var('extrachill_blog_archive')) {
        echo '<div class="category-dropdown">';
        echo '<select id="category" name="category" onchange="if (this.value) window.location.href=this.value;">';
        echo '<option value="">' . esc_html__('Select Category', 'extrachill') . '</option>';
        $categories = get_categories(array('hide_empty' => false));
        foreach ($categories as $category) {
            echo '<option value="' . esc_url(get_category_link($category->term_id)) . '">' . esc_html($category->name) . '</option>';
        }
        echo '</select>';
        echo '</div>';
    }

    echo extrachill_child_terms_dropdown_html();

    if (is_category('song-meanings')) {
        extrachill_artist_filter_dropdown();
    } elseif (is_category('music-history')) {
        extrachill_artist_filter_dropdown();
    }
    $current_sort = isset( $_GET['sort'] ) ? sanitize_key( $_GET['sort'] ) : 'recent';
    $sort_options = array(
        'recent'  => 'Sort by Recent',
        'oldest'  => 'Sort by Oldest',
        'random'  => 'Sort by Random',
        'popular' => 'Sort by Most Popular',
    );

    echo '<div id="custom-sorting-dropdown">';
    echo '<select id="post-sorting" name="post_sorting" onchange="window.location.href=\'' . esc_url( $archive_link ) . '?sort=\'+this.value;">';
    foreach ( $sort_options as $value => $label ) {
        $selected = ( $current_sort === $value ) ? ' selected' : '';
        echo '<option value="' . esc_attr( $value ) . '"' . $selected . '>' . esc_html( $label ) . '</option>';
    }
    echo '</select>';
    echo '</div>';

    echo '</div>';
}
