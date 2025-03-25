<?php
/**
 * Theme Single Post Section for our theme.
 *
 * @package    ThemeGrill
 * @subpackage ColorMag
 * @since      ColorMag 1.0
 */

get_header(); ?>

<?php do_action( 'extrachill_before_body_content' ); ?>

<section id="primary" id="main">

        <?php while ( have_posts() ) : the_post(); ?>

            <?php get_template_part( 'content', 'single' ); ?>

        <?php endwhile; ?>
<aside>


	    <?php
    do_action( 'extrachill_before_comments_template' );
    // If comments are open or we have at least one comment, load up the comment template
    if ( comments_open() || '0' != get_comments_number() ) {
        comments_template();
		global $post;
		$postId = get_the_ID();
		$restUrl = get_rest_url(null, '/extrachill/v1/'); // Ensure correct REST API base URL
		?>
		<script type="text/javascript">
			var extrachillPostData = {
				restUrl: '<?php echo esc_url_raw($restUrl); ?>',
				postId: <?php echo absint($postId); ?>
			};
		</script>
    <?php
    }
    do_action( 'extrachill_after_comments_template' );
    ?>


    </aside>
    </section><!-- #primary -->
<?php get_sidebar(); ?>

<?php do_action( 'extrachill_after_body_content' ); ?>

<?php get_footer(); ?>