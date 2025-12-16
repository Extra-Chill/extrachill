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
		if ( ! empty( $interviews_posts ) ) :
			foreach ( $interviews_posts as $interview_post ) :
				$permalink = get_permalink( $interview_post->ID );
				$title     = get_the_title( $interview_post->ID );
				$date      = get_the_date( '', $interview_post->ID );
				?>
			<a href="<?php echo esc_url( $permalink ); ?>" class="home-3x3-card home-3x3-card-link" aria-label="<?php echo esc_attr( $title ); ?>">
				<?php if ( has_post_thumbnail( $interview_post->ID ) ) : ?>
				<span class="home-3x3-thumb"><?php echo get_the_post_thumbnail( $interview_post->ID, 'medium' ); ?></span>
				<?php endif; ?>
				<span class="home-3x3-title"><?php echo esc_html( $title ); ?></span>
				<span class="home-3x3-meta"><?php echo esc_html( $date ); ?></span>
			</a>
				<?php
			endforeach;
		else :
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
		if ( ! empty( $live_reviews_posts ) ) :
			foreach ( $live_reviews_posts as $live_review_post ) :
				$permalink = get_permalink( $live_review_post->ID );
				$title     = get_the_title( $live_review_post->ID );
				$date      = get_the_date( '', $live_review_post->ID );
				?>
			<a href="<?php echo esc_url( $permalink ); ?>" class="home-3x3-card home-3x3-card-link" aria-label="<?php echo esc_attr( $title ); ?>">
				<?php if ( has_post_thumbnail( $live_review_post->ID ) ) : ?>
				<span class="home-3x3-thumb"><?php echo get_the_post_thumbnail( $live_review_post->ID, 'medium' ); ?></span>
				<?php endif; ?>
				<span class="home-3x3-title"><?php echo esc_html( $title ); ?></span>
				<span class="home-3x3-meta"><?php echo esc_html( $date ); ?></span>
			</a>
				<?php
			endforeach;
		else :
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
				<a class="home-3x3-archive-link button-3 button-small" href="<?php echo esc_url( ec_get_site_url( 'newsletter' ) ); ?>">View All</a>
			</div>
			<div class="home-3x3-list">
			<?php
			$newsletter_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'newsletter' ) : null;
			if ( $newsletter_blog_id ) {
				switch_to_blog( $newsletter_blog_id );
			}
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
			<?php
			if ( $newsletter_blog_id ) {
				restore_current_blog();
			}
			?>
			</div>
		</div>
	</div>
	</div>
</div>
