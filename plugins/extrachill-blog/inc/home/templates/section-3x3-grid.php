<?php
/**
 * Homepage 3x3 Content Grid
 *
 * @package ExtraChillBlog
 * @since 0.1.0
 */

global $live_reviews_posts, $interviews_posts;
?>
<div class="full-width-breakout">
	<div class="home-3x3-grid">
	<!-- Interviews Column -->
	<div class="home-3x3-col">
		<div class="home-3x3-header">
		<span class="home-3x3-label">Interviews</span>
		<a class="home-3x3-archive-link button-3 button-small" href="<?php echo esc_url( get_category_link( 723 ) ); ?>">View All</a>
		</div>
		<div class="home-3x3-list">
		<?php
		global $post;
		if ( ! empty( $interviews_posts ) ) :
			foreach ( $interviews_posts as $interview_post ) :
				$post = $interview_post;
				setup_postdata( $post );
				?>
			<a href="<?php the_permalink(); ?>" class="home-3x3-card home-3x3-card-link" aria-label="<?php the_title_attribute(); ?>">
				<?php if ( has_post_thumbnail() ) : ?>
				<span class="home-3x3-thumb"><?php the_post_thumbnail( 'medium' ); ?></span>
				<?php endif; ?>
				<span class="home-3x3-title"><?php the_title(); ?></span>
				<span class="home-3x3-meta"><?php echo get_the_date(); ?></span>
			</a>
				<?php
			endforeach;
			wp_reset_postdata(); else :
				?>
			<div class="home-3x3-card home-3x3-empty">No interviews yet.</div>
					<?php endif; ?>
		</div>
	</div>

	<!-- Live Reviews Column -->
	<div class="home-3x3-col">
		<div class="home-3x3-header">
		<span class="home-3x3-label">Live Reviews</span>
		<a class="home-3x3-archive-link button-3 button-small" href="<?php echo esc_url( get_category_link( 2608 ) ); ?>">View All</a>
		</div>
		<div class="home-3x3-list">
		<?php
		global $post;
		if ( ! empty( $live_reviews_posts ) ) :
			foreach ( $live_reviews_posts as $live_review_post ) :
				$post = $live_review_post;
				setup_postdata( $post );
				?>
			<a href="<?php the_permalink(); ?>" class="home-3x3-card home-3x3-card-link" aria-label="<?php the_title_attribute(); ?>">
				<?php if ( has_post_thumbnail() ) : ?>
				<span class="home-3x3-thumb"><?php the_post_thumbnail( 'medium' ); ?></span>
				<?php endif; ?>
				<span class="home-3x3-title"><?php the_title(); ?></span>
				<span class="home-3x3-meta"><?php echo get_the_date(); ?></span>
			</a>
				<?php
			endforeach;
			wp_reset_postdata(); else :
				?>
			<div class="home-3x3-card home-3x3-empty">No reviews yet.</div>
					<?php endif; ?>
		</div>
	</div>

	<!-- Right Column: Newsletter Subscribe + Recent Newsletters -->
	<div class="home-3x3-col home-3x3-col-newsletter">
		<div class="home-3x3-stacked-section home-3x3-newsletter-form-section">
			<?php do_action( 'extrachill_render_newsletter_form', 'homepage' ); ?>
		</div>
		<div class="home-3x3-stacked-section">
			<div class="home-3x3-header">
				<span class="home-3x3-label">Recent Newsletters</span>
				<a class="home-3x3-archive-link button-3 button-small" href="https://newsletter.extrachill.com">View All</a>
			</div>
			<div class="home-3x3-list">
			<?php
			switch_to_blog( 9 );
			$newsletter_posts = get_posts(
				array(
					'numberposts' => 3,
					'post_type'   => 'newsletter',
				)
			);

			if ( ! empty( $newsletter_posts ) ) :
				foreach ( $newsletter_posts as $newsletter ) :
					$permalink        = get_permalink( $newsletter->ID );
					$newsletter_title = get_the_title( $newsletter->ID );
					$date             = get_the_date( '', $newsletter->ID );
					?>
				<a href="<?php echo esc_url( $permalink ); ?>" class="home-3x3-card home-3x3-card-link" aria-label="<?php echo esc_attr( $newsletter_title ); ?>">
					<span class="home-3x3-title"><?php echo esc_html( $newsletter_title ); ?></span>
					<span class="home-3x3-meta">Sent <?php echo esc_html( $date ); ?></span>
				</a>
					<?php
				endforeach;
			else :
				?>
				<div class="home-3x3-card home-3x3-empty">No newsletters yet.</div>
			<?php endif; ?>
			<?php restore_current_blog(); ?>
			</div>
		</div>
	</div>
	</div>
</div>
