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

// Read the network snapshot; it is only built (with a blog switch) when missing.
$recent_newsletters = extrachill_newsletter_get_recent_snapshot();

if ( ! empty( $recent_newsletters ) ) :
	wp_enqueue_script( 'extrachill-newsletter' );
	wp_enqueue_style( 'extrachill-newsletter-sidebar' );
	?>
	<div class="sidebar-card ec-surface-card recent-newsletters-widget">
		<h3 class="widget-title"><?php esc_html_e( 'Recent Newsletters', 'extrachill-newsletter' ); ?></h3>
		<ul class="recent-newsletters-list">
			<?php
			foreach ( $recent_newsletters as $recent_newsletter ) :
				$newsletter_permalink = (string) $recent_newsletter['permalink'];
				$newsletter_title     = (string) $recent_newsletter['title'];
				$newsletter_date      = (string) $recent_newsletter['date'];
				?>
				<li>
					<a href="<?php echo esc_url( $newsletter_permalink ); ?>" class="newsletter-link">
						<strong><?php echo esc_html( $newsletter_title ); ?></strong>
						<span class="newsletter-date"><?php echo esc_html( $newsletter_date ); ?></span>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
		<a href="<?php echo esc_url( ec_get_site_url( 'newsletter' ) ); ?>" class="view-all-link">
			<?php esc_html_e( 'View All Newsletters', 'extrachill-newsletter' ); ?> →
		</a>
	</div>
	<?php
endif;
