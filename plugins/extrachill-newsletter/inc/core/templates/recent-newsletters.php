<?php
/**
 * Recent Newsletters Sidebar Widget Template
 *
 * Displays recent newsletters in sidebar.
 *
 * @package ExtraChillNewsletter
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Switch to newsletter site to query newsletters
$newsletter_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'newsletter' ) : null;
if ( ! $newsletter_blog_id ) {
	return;
}

switch_to_blog( $newsletter_blog_id );

$newsletter_args = array(
	'post_type' => 'newsletter',
	'posts_per_page' => 3,
	'post_status' => 'publish',
	'orderby' => 'date',
	'order' => 'DESC',
);

$newsletter_query = new WP_Query( $newsletter_args );

restore_current_blog();

if ( $newsletter_query->have_posts() ) :
	wp_enqueue_script( 'extrachill-newsletter' );
	wp_enqueue_style( 'extrachill-newsletter-sidebar' );
	?>
	<div class="sidebar-card recent-newsletters-widget">
		<h3 class="widget-title"><?php esc_html_e( 'Recent Newsletters', 'extrachill-newsletter' ); ?></h3>
		<ul class="recent-newsletters-list">
			<?php
			while ( $newsletter_query->have_posts() ) : $newsletter_query->the_post();
				// Already on newsletter site from query - get data directly
				$newsletter_id = get_the_ID();

				// Switch back to newsletter site for permalink generation
				switch_to_blog( $newsletter_blog_id );
				$newsletter_permalink = get_permalink( $newsletter_id );
				$newsletter_title = get_the_title( $newsletter_id );
				$newsletter_date = get_the_date( '', $newsletter_id );
				restore_current_blog();
				?>
				<li>
					<a href="<?php echo esc_url( $newsletter_permalink ); ?>" class="newsletter-link">
						<strong><?php echo esc_html( $newsletter_title ); ?></strong>
						<span class="newsletter-date"><?php echo esc_html( $newsletter_date ); ?></span>
					</a>
				</li>
			<?php endwhile; ?>
		</ul>
		<a href="<?php echo esc_url( ec_get_site_url( 'newsletter' ) ); ?>" class="view-all-link">
			<?php esc_html_e( 'View All Newsletters', 'extrachill-newsletter' ); ?> →
		</a>
	</div>
	<?php
	wp_reset_postdata();
endif;
