<?php
/**
 * Events Homepage — Location Directory
 *
 * Displays a grouped directory of cities organized by state, each linking
 * to its own calendar page. Replaces the monolithic calendar homepage
 * with a scalable directory that improves as coverage grows.
 *
 * @package ExtraChillEvents
 * @since 0.16.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$directory = extrachill_events_get_location_directory();
$states    = $directory['states'];
$totals    = $directory['totals'];

extrachill_breadcrumbs();
?>

<article class="events-homepage events-directory">
	<div class="inside-article">
		<header class="directory-header">
			<h1 class="page-title"><?php esc_html_e( 'Live Music Calendar', 'extrachill-events' ); ?></h1>
			<?php if ( $totals['events'] > 0 ) : ?>
				<p class="calendar-stats">
					<?php
					printf(
						/* translators: 1: event count, 2: city count, 3: state count */
						esc_html__( '%1$s upcoming events across %2$s cities in %3$s states', 'extrachill-events' ),
						esc_html( number_format( $totals['events'] ) ),
						esc_html( number_format( $totals['cities'] ) ),
						esc_html( number_format( $totals['states'] ) )
					);
					?>
				</p>
			<?php endif; ?>

			<nav class="directory-quick-links">
				<a href="<?php echo esc_url( home_url( '/near-me/' ) ); ?>" class="taxonomy-badge directory-quick-link">
					<?php esc_html_e( 'Near Me', 'extrachill-events' ); ?>
				</a>
				<a href="<?php echo esc_url( home_url( '/tonight/' ) ); ?>" class="taxonomy-badge directory-quick-link">
					<?php esc_html_e( 'Tonight', 'extrachill-events' ); ?>
				</a>
				<a href="<?php echo esc_url( home_url( '/this-weekend/' ) ); ?>" class="taxonomy-badge directory-quick-link">
					<?php esc_html_e( 'This Weekend', 'extrachill-events' ); ?>
				</a>
				<a href="<?php echo esc_url( home_url( '/this-week/' ) ); ?>" class="taxonomy-badge directory-quick-link">
					<?php esc_html_e( 'This Week', 'extrachill-events' ); ?>
				</a>
			</nav>
		</header>

		<?php do_action( 'extrachill_events_home_before_directory' ); ?>

		<div class="entry-content" itemprop="text">
			<?php if ( ! empty( $states ) ) : ?>
				<div class="location-directory">
					<?php foreach ( $states as $state ) : ?>
						<section class="directory-state" id="state-<?php echo esc_attr( $state['slug'] ); ?>">
							<h2 class="directory-state-name">
								<?php if ( ! empty( $state['url'] ) ) : ?>
									<a href="<?php echo esc_url( $state['url'] ); ?>">
										<?php echo esc_html( $state['name'] ); ?>
									</a>
								<?php else : ?>
									<?php echo esc_html( $state['name'] ); ?>
								<?php endif; ?>
							</h2>
							<div class="taxonomy-badges">
								<?php foreach ( $state['cities'] as $city ) : ?>
									<a href="<?php echo esc_url( $city['url'] ); ?>"
										class="taxonomy-badge location-badge location-<?php echo esc_attr( $city['slug'] ); ?>">
										<?php echo esc_html( $city['name'] ); ?>
										<span class="badge-count"><?php echo esc_html( number_format( $city['count'] ) ); ?></span>
									</a>
								<?php endforeach; ?>
							</div>
						</section>
					<?php endforeach; ?>
				</div>
			<?php else : ?>
				<p class="no-events-message">
					<?php esc_html_e( 'No upcoming events found. Check back soon.', 'extrachill-events' ); ?>
				</p>
			<?php endif; ?>

			<?php do_action( 'extrachill_events_home_after_directory' ); ?>
		</div>
	</div>
</article>
