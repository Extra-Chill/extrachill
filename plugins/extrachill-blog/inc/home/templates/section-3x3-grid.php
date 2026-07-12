<?php
/**
 * Homepage Live Network Grid
 *
 * Three columns ordered by freshness: Festival Wire dispatches (published
 * daily, timestamped), latest blog stories (any category), and the
 * newsletter subscribe form alongside live upcoming-event city counts.
 *
 * @package ExtraChillBlog
 * @since 0.1.0
 */

global $latest_blog_posts;

$wire_items      = extrachill_blog_get_wire_latest( 4 );
$wire_url        = function_exists( 'ec_get_site_url' ) ? ec_get_site_url( 'wire' ) : 'https://wire.extrachill.com';
$location_counts = extrachill_blog_get_location_event_counts();
$events_url      = function_exists( 'ec_get_site_url' ) ? ec_get_site_url( 'events' ) : 'https://events.extrachill.com';
?>
<div class="full-width-breakout ec-edge-shell">
	<div class="home-3x3-grid">
	<!-- Festival Wire Column: freshest surface on the network -->
	<div class="home-3x3-col">
		<div class="home-3x3-header">
		<span class="home-3x3-label"><?php esc_html_e( 'Festival Wire', 'extrachill-blog' ); ?></span>
		<a class="home-3x3-archive-link button-3 button-small" href="<?php echo esc_url( $wire_url ); ?>"><?php esc_html_e( 'View All', 'extrachill-blog' ); ?></a>
		</div>
		<div class="home-3x3-list">
		<?php
		if ( ! empty( $wire_items ) ) :
			foreach ( $wire_items as $wire_item ) :
				?>
			<a href="<?php echo esc_url( $wire_item['url'] ); ?>" class="home-3x3-card home-3x3-card-link" aria-label="<?php echo esc_attr( $wire_item['title'] ); ?>">
				<span class="home-3x3-title"><?php echo esc_html( $wire_item['title'] ); ?></span>
				<span class="home-3x3-meta">
					<?php
					printf(
						/* translators: %s: human-readable time difference, e.g. "3 hours" */
						esc_html__( '%s ago', 'extrachill-blog' ),
						esc_html( $wire_item['time_diff'] )
					);
					?>
				</span>
			</a>
				<?php
			endforeach;
		else :
			?>
			<div class="home-3x3-card home-3x3-empty"><?php esc_html_e( 'No dispatches yet.', 'extrachill-blog' ); ?></div>
		<?php endif; ?>
		</div>
	</div>

	<!-- Latest Stories Column -->
	<div class="home-3x3-col">
		<div class="home-3x3-header">
		<span class="home-3x3-label"><?php esc_html_e( 'Latest Stories', 'extrachill-blog' ); ?></span>
		<a class="home-3x3-archive-link button-3 button-small" href="<?php echo esc_url( home_url( '/blog/' ) ); ?>"><?php esc_html_e( 'View All', 'extrachill-blog' ); ?></a>
		</div>
		<div class="home-3x3-list">
		<?php
		if ( ! empty( $latest_blog_posts ) ) :
			$story_index = 0;
			foreach ( $latest_blog_posts as $story_post ) :
				$story_permalink = get_permalink( $story_post->ID );
				if ( false === $story_permalink ) {
					$story_permalink = '';
				}
				$story_title = (string) get_the_title( $story_post->ID );
				$story_date  = (string) get_the_date( '', $story_post->ID );
				++$story_index;
				?>
			<a href="<?php echo esc_url( $story_permalink ); ?>" class="home-3x3-card home-3x3-card-link" aria-label="<?php echo esc_attr( $story_title ); ?>">
				<?php if ( 1 === $story_index && has_post_thumbnail( $story_post->ID ) ) : ?>
				<span class="home-3x3-thumb"><?php echo get_the_post_thumbnail( $story_post->ID, 'medium' ); ?></span>
				<?php endif; ?>
				<span class="home-3x3-title"><?php echo esc_html( $story_title ); ?></span>
				<span class="home-3x3-meta"><?php echo esc_html( $story_date ); ?></span>
			</a>
				<?php
			endforeach;
		else :
			?>
			<div class="home-3x3-card home-3x3-empty"><?php esc_html_e( 'No stories yet.', 'extrachill-blog' ); ?></div>
		<?php endif; ?>
		</div>
	</div>

	<!-- Right Column: Newsletter Subscribe + Live Events by City -->
	<div class="home-3x3-col home-3x3-col-newsletter">
		<div class="home-3x3-stacked-section home-3x3-newsletter-form-section">
			<?php do_action( 'extrachill_render_newsletter_form', 'homepage' ); ?>
		</div>
		<div class="home-3x3-stacked-section">
			<div class="home-3x3-header">
				<span class="home-3x3-label"><?php esc_html_e( 'Shows Near You', 'extrachill-blog' ); ?></span>
				<a class="home-3x3-archive-link button-3 button-small" href="<?php echo esc_url( $events_url ); ?>"><?php esc_html_e( 'View All', 'extrachill-blog' ); ?></a>
			</div>
			<?php if ( ! empty( $location_counts ) ) : ?>
				<div class="taxonomy-badges home-city-badges">
					<?php foreach ( $location_counts as $location ) : ?>
						<a href="<?php echo esc_url( $location['url'] ); ?>" class="taxonomy-badge location-badge location-<?php echo esc_attr( $location['slug'] ); ?>">
							<?php echo esc_html( $location['name'] ); ?> (<?php echo esc_html( $location['count'] ); ?>)
						</a>
					<?php endforeach; ?>
				</div>
			<?php else : ?>
				<div class="home-3x3-card home-3x3-empty"><?php esc_html_e( 'Calendar loading — browse all events.', 'extrachill-blog' ); ?></div>
			<?php endif; ?>
		</div>
	</div>
	</div>
</div>
