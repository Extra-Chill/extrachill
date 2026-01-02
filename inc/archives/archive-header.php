<?php
/**
 * Archive Header
 *
 * Displays archive titles, descriptions, and author bios.
 * Hook: extrachill_after_author_bio fires on author archives (passes $author_id parameter).
 *
 * @package ExtraChill
 * @since 1.0.0
 */
?>

<header class="page-header">
    <div class="archive-header-row">
        <h1 class="page-title">
            <?php
            if ( is_category() ) {
                single_cat_title();
            } elseif ( is_tag() ) {
                single_tag_title();
            } elseif ( is_tax() ) {
                single_term_title();
            } elseif ( is_post_type_archive() ) {
                post_type_archive_title();
            } elseif ( is_author() ) {
                the_post();
                $archive_author_name = get_the_author();
                rewind_posts();
                printf(
                    '<span class="archive-author-label">%s <span class="vcard">%s</span></span>',
                    esc_html__( 'Author:', 'extrachill' ),
                    esc_html( $archive_author_name )
                );
            } elseif ( is_day() ) {
                printf( __( 'Day: %s', 'extrachill' ), get_the_date() );
            } elseif ( is_month() ) {
                printf( __( 'Month: %s', 'extrachill' ), get_the_date( 'F Y' ) );
            } elseif ( is_year() ) {
                printf( __( 'Year: %s', 'extrachill' ), get_the_date( 'Y' ) );
			} elseif ( get_query_var( 'extrachill_blog_archive' ) ) {
				esc_html_e( 'The Latest', 'extrachill' );
			} elseif ( is_search() ) {
				printf(
					/* translators: %s: search query */
					__( 'Search Results for: %s', 'extrachill' ),
					'<span class="search-query">' . esc_html( get_search_query() ) . '</span>'
				);
			} else {
				esc_html_e( 'Archives', 'extrachill' );
			}
            ?>
        </h1>

        <div class="archive-header-actions">
            <?php do_action( 'extrachill_archive_header_actions' ); ?>
        </div>
    </div>
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
