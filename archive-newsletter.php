<?php
/**
 * The template for displaying Newsletter archive.
 *
 */

get_header(); ?>

<div id="mediavine-settings" data-blocklist-all="1"></div>

<?php do_action( 'extrachill_before_body_content' ); ?>

<section id="primary" class="newsletter-archive">
    <?php if ( have_posts() ) : ?>

        <header class="page-header">
            <h1 class="page-title"><span>Newsletters</span></h1>
            <p>Our newsletter goes out regularly with updates from our editor, music news, and featured Extra Chill content. This page contains our newsletter archive, with links to all past newsletters, and the date that they were sent. Subscribe to get a copy of these in your inbox as we send them out.</p>
        </header><!-- .page-header -->

        <div class="newsletter-subscribe-form">
            <h2>Subscribe to Our Newsletter</h2>
            <form id="newsletterForm">
                <label for="subscriber_email">Email:</label><br>
                <input type="email" id="subscriber_email" name="subscriber_email" required>
                <input type="hidden" name="action" value="submit_newsletter_form">
                <?php wp_nonce_field( 'newsletter_nonce', 'newsletter_nonce_field' ); ?>
                <button type="submit" class="submit-button">Subscribe</button>
            </form>
            <p>Explore past Extra Chill newsletters below.</p>
        </div>

        <script>
        document.getElementById('newsletterForm').addEventListener('submit', function(event) {
            event.preventDefault();
            var xhr = new XMLHttpRequest();
            var formData = new FormData();
            formData.append('action', 'submit_newsletter_form');
            formData.append('email', document.getElementById('subscriber_email').value);
            formData.append('nonce', document.getElementById('newsletter_nonce_field').value);

            xhr.open('POST', '<?php echo admin_url('admin-ajax.php'); ?>', true);
            xhr.onreadystatechange = function () {
                if (xhr.readyState == 4 && xhr.status == 200) {
                    var response = JSON.parse(xhr.responseText);
                    if (response.success) {
                        alert('Subscription successful! Check your email.');
                        // Update localStorage to reflect the subscription
                        localStorage.setItem('subscribed', 'true');
                        localStorage.setItem('lastSubscribedTime', new Date().getTime());
                    } else {
                        alert('Error: ' + response.data);
                    }
                }
            };
            xhr.send(formData);
        });
        </script>

        <?php
        // Optional Term and Author Description
        if ( !is_paged() && empty($_GET['tag']) ) {
            $term_description = term_description();
            if ( ! empty( $term_description ) ) {
                printf( '<div class="taxonomy-description">%s</div>', $term_description );
            }

            if ( is_author() ) {
                $author_bio = get_the_author_meta('description');
                if ( !empty($author_bio) ) {
                    echo '<div class="author-bio">' . wpautop($author_bio) . '</div>';
                }
            }
        }
        ?>

        <div class="article-container">
            <?php global $post_i; $post_i = 1; ?>
            <?php while ( have_posts() ) : the_post(); ?>
                <article id="post-<?php the_ID(); ?>" <?php post_class('newsletter-card'); ?>>
                    <?php if ( has_post_thumbnail() ) { ?>
                        <div class="featured-image">
                            <a href="<?php the_permalink(); ?>" title="<?php the_title_attribute(); ?>"><?php the_post_thumbnail( 'large' ); ?></a>
                        </div>
                    <?php } ?>

                    <header class="entry-header">
    <?php if ( is_single() ) : ?>
        <h1 class="entry-title"><?php the_title(); ?></h1>
    <?php else : ?>
        <h2 class="entry-title"><a href="<?php the_permalink(); ?>" title="<?php the_title_attribute(); ?>"><?php the_title(); ?></a></h2>
    <?php endif; ?>
    <span class="below-entry-meta">Sent on <?php echo get_the_date(); ?></span>
</header>
                </article>
            <?php endwhile; ?>
        </div>

        <?php get_template_part( 'navigation', 'archive' ); ?>

    <?php else : ?>
        <?php get_template_part( 'no-results', 'archive' ); ?>
    <?php endif; ?>
</section><!-- #primary -->

<?php get_sidebar(); ?>

<?php do_action( 'extrachill_after_body_content' ); ?>

<?php get_footer(); ?>
