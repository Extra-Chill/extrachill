<?php
/**
 * This file loads the content partially for support on Auto Load Next Post plugin.
 */

$template_location = apply_filters( 'alnp_template_location', '' ); // e.g. "template-parts/post/"

if ( have_posts() ) :

	// Load content before the loop.
	do_action( 'alnp_load_before_loop' );

	// Check that there are more posts to load.
	while ( have_posts() ) : the_post();

		// Load content before the post content.
		do_action( 'alnp_load_before_content' );

		// Load the content part for single post
		get_template_part( 'content', 'single' );

		// Load content after the post content.
		do_action( 'alnp_load_after_content' );

		get_template_part( 'navigation', 'single' );

		if ( ( get_theme_mod( 'colormag_author_bio_disable_setting', 0 ) == 0 ) && ( get_the_author_meta( 'description' ) ) ) : ?>
			<div class="author-box">
				<div class="author-img"><?php echo get_avatar( get_the_author_meta( 'user_email' ), '100' ); ?></div>
				<div class="author-description-wrapper">
					<h4 class="author-name"><?php the_author_meta( 'display_name' ); ?></h4>

					<p class="author-description"><?php the_author_meta( 'description' ); ?></p>

					<?php if ( get_theme_mod( 'colormag_author_bio_social_sites_show', 0 ) == 1 ) {
						colormag_author_social_link();
					}
					?>

					<?php if ( get_theme_mod( 'colormag_author_bio_links', 0 ) == 1 ) { ?>
						<p class="author-url"><?php printf( __( '%1$s has %2$s posts and counting.', 'colormag-pro' ), get_the_author_meta( 'display_name' ), absint( count_user_posts( get_the_author_meta( 'ID' ) ) ) ); ?>
							<a href="<?php echo esc_url( get_author_posts_url( get_the_author_meta( 'ID' ) ) ); ?>"><?php printf( __( 'See all posts by %1$s', 'colormag-pro' ), get_the_author_meta( 'display_name' ) ); ?></a>
						</p>
					<?php } ?>
				</div>
			</div>

		<?php endif; ?>

		<?php if ( get_theme_mod( 'colormag_social_share', 0 ) == 1 ) {
			get_template_part( 'inc/share' );
		}
		?>

		<?php if ( get_theme_mod( 'colormag_related_posts_activate', 0 ) == 1 ) {
			get_template_part( 'inc/related-posts' );
		}

		// End the loop.
	endwhile;

	// Load content after the loop.
	do_action( 'alnp_load_after_loop' );

else :

	// Load content if there are no more posts.
	do_action( 'alnp_no_more_posts' );

endif;
