<?php
/**
 * Events Archive Template
 *
 * Renders data-machine-events calendar block with automatic context-aware filtering.
 * Handles all archive types (taxonomy, post type, date, author).
 * Only applies on blog ID 7 (events.extrachill.com).
 *
 * @package ExtraChillEvents
 * @since 0.1.0
 */

get_header();
extrachill_breadcrumbs();
?>


<div class="events-calendar-container ec-mobile-full-width-panel">
	<div class="page-content">
	<?php
	if ( is_tax( 'venue' ) ) :
		$term              = get_queried_object();
		$venue_data        = function_exists( 'data_machine_events_get_venue_data' ) ? data_machine_events_get_venue_data( (int) $term->term_id ) : null;
		$formatted_address = function_exists( 'data_machine_events_get_venue_address' ) ? data_machine_events_get_venue_address( (int) $term->term_id, $venue_data ) : '';
		?>
		<header class="taxonomy-archive-header venue-archive-header">
			<h1 class="page-title"><?php single_term_title(); ?> Live Music Calendar</h1>
			
			<?php if ( ! empty( $term->description ) ) : ?>
				<div class="taxonomy-description"><?php echo wp_kses_post( wpautop( $term->description ) ); ?></div>
			<?php endif; ?>

			<?php extrachill_events_render_term_calendar_stats( 'venue', (int) $term->term_id ); ?>

			<?php if ( $formatted_address || ! empty( $venue_data['website'] ) ) : ?>
				<div class="taxonomy-meta">
					<?php if ( $formatted_address ) : ?>
						<span class="venue-address"><?php echo esc_html( $formatted_address ); ?></span>
					<?php endif; ?>
					
					<?php if ( ! empty( $venue_data['website'] ) ) : ?>
						<a class="taxonomy-website" href="<?php echo esc_url( $venue_data['website'] ); ?>" target="_blank" rel="noopener">
							<?php echo esc_html( wp_parse_url( $venue_data['website'], PHP_URL_HOST ) ); ?>
						</a>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</header>
		<?php
	elseif ( is_tax( 'promoter' ) ) :
		$term          = get_queried_object();
		$promoter_data = function_exists( 'data_machine_events_get_promoter_data' ) ? data_machine_events_get_promoter_data( (int) $term->term_id ) : null;
		?>
		<header class="taxonomy-archive-header promoter-archive-header">
			<h1 class="page-title"><?php single_term_title(); ?> Live Music Calendar</h1>
			
			<?php if ( ! empty( $term->description ) ) : ?>
				<div class="taxonomy-description"><?php echo wp_kses_post( wpautop( $term->description ) ); ?></div>
			<?php endif; ?>
			
			<?php if ( ! empty( $promoter_data['url'] ) ) : ?>
				<div class="taxonomy-meta">
					<a class="taxonomy-website" href="<?php echo esc_url( $promoter_data['url'] ); ?>" target="_blank" rel="noopener">
						<?php echo esc_html( wp_parse_url( $promoter_data['url'], PHP_URL_HOST ) ); ?>
					</a>
				</div>
			<?php endif; ?>
		</header>
		<?php
	elseif ( is_tax( 'location' ) ) :
		$term = get_queried_object();
		?>
		<header class="taxonomy-archive-header location-archive-header">
			<h1 class="page-title">Live Music in <?php single_term_title(); ?></h1>
			<?php if ( term_description() ) : ?>
				<div class="taxonomy-description"><?php echo wp_kses_post( wpautop( term_description() ) ); ?></div>
			<?php endif; ?>
			<?php extrachill_events_render_term_calendar_stats( 'location', (int) $term->term_id ); ?>
		</header>
		<?php
		if ( function_exists( 'extrachill_events_render_scope_nav' ) ) {
			extrachill_events_render_scope_nav( $term, '' );
		}
		?>
		<?php
	elseif ( is_tax( 'artist' ) ) :
		$term = get_queried_object();
		?>
		<header class="taxonomy-archive-header artist-archive-header">
			<h1 class="page-title"><?php single_term_title(); ?> Tour Dates</h1>
			<?php if ( term_description() ) : ?>
				<div class="taxonomy-description"><?php echo wp_kses_post( wpautop( term_description() ) ); ?></div>
			<?php endif; ?>
			<?php extrachill_events_render_term_calendar_stats( 'artist', (int) $term->term_id ); ?>
		</header>
	<?php elseif ( is_tax() ) : ?>
		<header class="taxonomy-archive-header">
			<h1 class="page-title"><?php single_term_title(); ?> Live Music Calendar</h1>
			<?php if ( term_description() ) : ?>
				<div class="taxonomy-description"><?php echo wp_kses_post( wpautop( term_description() ) ); ?></div>
			<?php endif; ?>
		</header>
	<?php endif; ?>

	<?php do_action( 'extrachill_archive_below_description' ); ?>
	</div>

	<div class="page-content">
		<?php echo do_blocks( '<!-- wp:data-machine-events/calendar /-->' ); ?>
	</div>
</div>

<?php get_footer(); ?>
