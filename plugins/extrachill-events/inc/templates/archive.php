<?php
/**
 * Events Archive Template
 *
 * Renders datamachine-events calendar block with automatic context-aware filtering.
 * Handles all archive types (taxonomy, post type, date, author).
 * Only applies on blog ID 7 (events.extrachill.com).
 *
 * @package ExtraChillEvents
 * @since 0.1.0
 */

get_header();
extrachill_breadcrumbs();
?>

<div class="events-calendar-container full-width-content">
	<?php if ( is_tax( 'venue' ) ) :
		$term = get_queried_object();
		$venue_data = \DataMachineEvents\Core\Venue_Taxonomy::get_venue_data( $term->term_id );
		$formatted_address = \DataMachineEvents\Core\Venue_Taxonomy::get_formatted_address( $term->term_id );
	?>
		<header class="taxonomy-archive-header venue-archive-header">
			<h1 class="page-title"><?php single_term_title(); ?> Live Music Calendar</h1>
			
			<?php if ( ! empty( $term->description ) ) : ?>
				<div class="taxonomy-description"><?php echo wp_kses_post( wpautop( $term->description ) ); ?></div>
			<?php endif; ?>
			
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
	<?php elseif ( is_tax( 'promoter' ) ) :
		$term = get_queried_object();
		$promoter_data = \DataMachineEvents\Core\Promoter_Taxonomy::get_promoter_data( $term->term_id );
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
	<?php elseif ( is_tax() ) : ?>
		<header class="taxonomy-archive-header">
			<h1 class="page-title"><?php single_term_title(); ?> Live Music Calendar</h1>
			<?php if ( term_description() ) : ?>
				<div class="taxonomy-description"><?php echo wp_kses_post( wpautop( term_description() ) ); ?></div>
			<?php endif; ?>
		</header>
	<?php endif; ?>

	<?php echo do_blocks( '<!-- wp:datamachine-events/calendar /-->' ); ?>
</div>

<?php get_footer(); ?>
