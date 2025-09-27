<?php
/**
 * No results template for empty search results and missing content
 *
 * Displays contextual messages and search forms for different scenarios.
 * Provides admin links for post creation when appropriate.
 *
 * @package ExtraChill
 * @since 69.57
 */

if ( ! function_exists( 'extrachill_no_results' ) ) :
    function extrachill_no_results() {
        ?>
        <section class="no-results not-found">

            <div class="page-content">
                <?php if ( is_home() && current_user_can( 'publish_posts' ) ) : ?>

                    <p><?php printf( __( 'Ready to publish your first post? <a href="%1$s">Get started here</a>.', 'extrachill' ), esc_url( admin_url( 'post-new.php' ) ) ); ?></p>

                <?php elseif ( is_search() ) : ?>

                    <p><?php _e( 'Sorry, no results. Check spelling or try different keywords.', 'extrachill' ); ?></p>
                    <?php extrachill_search_form(); ?>

                <?php else : ?>

                    <p><?php _e( 'It seems we can&rsquo;t find what you&rsquo;re looking for. Perhaps searching can help.', 'extrachill' ); ?></p>
                    <?php extrachill_search_form(); ?>

                <?php endif; ?>
            </div><!-- .page-content -->

        </section><!-- .no-results -->
        <?php
    }
endif;