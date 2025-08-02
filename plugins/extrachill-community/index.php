<?php
/**
 * The main template file for Extra Chill Community theme
 * 
 * This is the most generic template file in a WordPress theme
 * and one of the two required files for a theme (the other being style.css).
 * It is used to display a page when nothing more specific matches a query.
 * 
 * @package ExtraChillCommunity
 */

get_header(); ?>

<div class="site-content">
	<div class="container">
		<main class="main-content">
			<?php if (have_posts()) : ?>
				<?php while (have_posts()) : the_post(); ?>
					<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
						<header class="entry-header">
							<?php 
							if (is_singular()) :
								the_title('<h1 class="entry-title">', '</h1>');
							else :
								the_title('<h2 class="entry-title"><a href="' . esc_url(get_permalink()) . '">', '</a></h2>');
							endif;
							?>
						</header>

						<div class="entry-content">
							<?php
							if (is_singular()) :
								the_content();
								wp_link_pages(array(
									'before' => '<div class="page-links">',
									'after'  => '</div>',
								));
							else :
								the_excerpt();
							endif;
							?>
						</div>

						<footer class="entry-footer">
							<?php if (!is_singular()) : ?>
								<a href="<?php echo esc_url(get_permalink()); ?>" class="read-more">
									<?php esc_html_e('Read More', 'extra-chill-community'); ?>
								</a>
							<?php endif; ?>
						</footer>
					</article>
				<?php endwhile; ?>

				<?php
				// Pagination
				the_posts_pagination(array(
					'prev_text' => esc_html__('Previous', 'extra-chill-community'),
					'next_text' => esc_html__('Next', 'extra-chill-community'),
				));
				?>

			<?php else : ?>
				<article class="no-results">
					<header class="page-header">
						<h1 class="page-title"><?php esc_html_e('Nothing Found', 'extra-chill-community'); ?></h1>
					</header>

					<div class="page-content">
						<?php if (is_home() && current_user_can('publish_posts')) : ?>
							<p>
								<?php 
								printf(
									wp_kses(
										/* translators: %s: link to wp-admin */
										__('Ready to publish your first post? <a href="%s">Get started here</a>.', 'extra-chill-community'),
										array(
											'a' => array(
												'href' => array(),
											),
										)
									),
									esc_url(admin_url('post-new.php'))
								);
								?>
							</p>
						<?php elseif (is_search()) : ?>
							<p><?php esc_html_e('Sorry, but nothing matched your search terms. Please try again with some different keywords.', 'extra-chill-community'); ?></p>
							<?php get_search_form(); ?>
						<?php else : ?>
							<p><?php esc_html_e('It seems we can&rsquo;t find what you&rsquo;re looking for. Perhaps searching can help.', 'extra-chill-community'); ?></p>
							<?php get_search_form(); ?>
						<?php endif; ?>
					</div>
				</article>
			<?php endif; ?>
		</main>

		<?php get_sidebar(); ?>
	</div>
</div>

<?php get_footer(); ?>