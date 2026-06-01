<?php
/**
 * The Template for displaying all single Artist Profiles.
 *
 * The profile body is no longer hardcoded here. Each block is a section
 * registered via the `ec_artist_profile_sections` filter and rendered in
 * priority order by ec_render_artist_profile_sections(). AP core registers the
 * default sections (hero, overview) in inc/artist-profiles/frontend/default-sections.php;
 * foreign plugins register their own term-scoped sections from their own code.
 */

get_header(); ?>

    <div class="main-content">
        <main id="main" class="site-main">
            <?php
            /**
             * Custom hook for before main content.
             */
            do_action( 'extrachill_before_body_content' );

            // --- Display Breadcrumbs ---
            // Moved to be a direct child of <main>, before the while loop and <article>
            if ( function_exists( 'extrachill_breadcrumbs' ) ) {
                extrachill_breadcrumbs();
            }
            // --- End Breadcrumbs ---

            // --- Main Post Loop ---
            while ( have_posts() ) : the_post();

                $artist_profile_id = get_the_ID();

                // Resolve the bound main-blog `artist` term (Primitive 1) so
                // term-scoped sections (Shows/Coverage/Community) can query the
                // network. 0 when the profile has no term yet.
                $artist_term_id = function_exists( 'ec_get_artist_term_id' ) ? ec_get_artist_term_id( $artist_profile_id ) : 0;

            ?>

                <article id="post-<?php the_ID(); ?>" <?php post_class(); ?> >
                    <div class="ec-edge-gutter">
                        <?php
                        // Render every registered section in priority order.
                        // The default sections reproduce the previous hardcoded
                        // hero + entry-content blocks (no visual change).
                        if ( function_exists( 'ec_render_artist_profile_sections' ) ) {
                            ec_render_artist_profile_sections( $artist_profile_id, $artist_term_id );
                        }
                        ?>

                        <?php
                        /**
                         * generate_after_content hook.
                         *
                         * @since 0.1
                         */
                        do_action( 'extra_chill_after_content' );
                        ?>
                    </div>
                </article><!-- #post-## -->

            <?php endwhile; // end of the loop. ?>

            <?php
            /**
             * generate_after_main_content hook.
             *
             * @since 0.1
             */
            do_action( 'extrachill_after_body_content' );
            ?>
        </main><!-- #main -->
    </div><!-- .main-content -->

<?php
/**
 * generate_after_primary_content_area hook.
 *
 * @since 2.0
 */
do_action( 'extra_chill_after_primary_content_area' );

get_footer();
