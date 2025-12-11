<?php
/**
 * Single Event Related Events
 *
 * Handles related events logic for single event pages.
 *
 * @package ExtraChillEvents
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueue assets for related events
 *
 * @since 0.1.1
 */
function ec_events_enqueue_related_assets() {
	if ( ! is_singular( 'datamachine_events' ) ) {
		return;
	}

	// Ensure core block styles are loaded since we are manually rendering components
	if ( wp_style_is( 'wp-block-datamachine-events-calendar', 'registered' ) ) {
		wp_enqueue_style( 'wp-block-datamachine-events-calendar' );
	}

	// Enqueue custom related events styles
	wp_enqueue_style(
		'ec-related-events',
		EXTRACHILL_EVENTS_PLUGIN_URL . 'assets/css/related-events.css',
		array(),
		filemtime( EXTRACHILL_EVENTS_PLUGIN_DIR . 'assets/css/related-events.css' )
	);
}
add_action( 'wp_enqueue_scripts', 'ec_events_enqueue_related_assets' );

/**
 * Use venue and location taxonomies for event posts
 *
 * Order matters:
 * 1. venue - Shows upcoming events at same venue
 * 2. location - Shows upcoming events in same location (different venues)
 *
 * @hook extrachill_related_posts_taxonomies
 * @param array  $taxonomies Default taxonomies (artist, venue)
 * @param int    $post_id    Current post ID
 * @param string $post_type  Current post type
 * @return array Modified taxonomies for event posts
 * @since 0.1.0
 */
function ec_events_filter_related_taxonomies( $taxonomies, $post_id, $post_type ) {
	if ( $post_type === 'datamachine_events' ) {
		return array( 'venue', 'location' );
	}
	return $taxonomies;
}
add_filter( 'extrachill_related_posts_taxonomies', 'ec_events_filter_related_taxonomies', 10, 3 );

/**
 * Allow location taxonomy in related posts whitelist
 *
 * @hook extrachill_related_posts_allowed_taxonomies
 * @param array  $allowed   Default allowed taxonomies
 * @param string $post_type Current post type
 * @return array Modified allowed taxonomies
 * @since 0.1.0
 */
function ec_events_allow_related_taxonomies( $allowed, $post_type ) {
	if ( $post_type === 'datamachine_events' ) {
		return array_merge( $allowed, array( 'location' ) );
	}
	return $allowed;
}
add_filter( 'extrachill_related_posts_allowed_taxonomies', 'ec_events_allow_related_taxonomies', 10, 2 );

/**
 * Override related posts display for datamachine_events
 *
 * @param bool   $override Whether to override default display
 * @param string $taxonomy Taxonomy being queried
 * @param int    $post_id  Current post ID
 * @return bool
 */
function ec_events_override_related_posts( $override, $taxonomy, $post_id ) {
	if ( get_post_type( $post_id ) === 'datamachine_events' ) {
		return true;
	}
	return $override;
}
add_filter( 'extrachill_override_related_posts_display', 'ec_events_override_related_posts', 10, 3 );

/**
 * Render custom related events display
 *
 * Uses Data Machine Events calendar templates to render event cards.
 *
 * @param string $taxonomy Taxonomy being queried
 * @param int    $post_id  Current post ID
 */
function ec_events_render_related_posts( $taxonomy, $post_id ) {
	if ( ! class_exists( '\DataMachineEvents\Blocks\Calendar\Template_Loader' ) || ! class_exists( '\DataMachineEvents\Blocks\Calendar\Calendar_Query' ) ) {
		return;
	}

	// Get terms
	$terms = get_the_terms( $post_id, $taxonomy );
	if ( ! $terms || is_wp_error( $terms ) ) {
		return;
	}

	$term      = $terms[0];
	$term_id   = $term->term_id;
	$term_link = get_term_link( $term );
	$term_name = esc_html( $term->name );

	// Build query args
	$tax_query = array(
		array(
			'taxonomy' => $taxonomy,
			'field'    => 'term_id',
			'terms'    => $term_id,
		),
	);

	// Exclude same venue when showing location-based related events
	if ( $taxonomy === 'location' ) {
		$venue_terms = get_the_terms( $post_id, 'venue' );
		if ( $venue_terms && ! is_wp_error( $venue_terms ) ) {
			$venue_term_ids = wp_list_pluck( $venue_terms, 'term_id' );
			$tax_query[]    = array(
				'taxonomy' => 'venue',
				'field'    => 'term_id',
				'terms'    => $venue_term_ids,
				'operator' => 'NOT IN',
			);
		}
	}

	$query_args = array(
		'post_type'      => 'datamachine_events',
		'posts_per_page' => 3,
		'post_status'    => 'publish',
		'tax_query'      => $tax_query,
		'post__not_in'   => array( $post_id ),
		'meta_key'       => '_datamachine_event_datetime',
		'orderby'        => 'meta_value',
		'order'          => 'ASC',
		'meta_query'     => array(
			array(
				'key'     => '_datamachine_event_datetime',
				'value'   => current_time( 'mysql' ),
				'compare' => '>=',
				'type'    => 'DATETIME',
			),
		),
	);

	$related_posts = new WP_Query( $query_args );

	$preposition = ( $taxonomy === 'venue' ) ? 'at' : 'in';

	if ( $related_posts->have_posts() ) :
		?>
		<div class="related-tax-section">
			<h3 class="related-tax-header">More <?php echo esc_html( $preposition ); ?> <a href="<?php echo esc_url( $term_link ); ?>"><?php echo $term_name; ?></a></h3>
			
			<div class="related-tax-grid">
				<?php
				while ( $related_posts->have_posts() ) :
					$related_posts->the_post();
					$post = get_post();
					
					$event_data = \DataMachineEvents\Blocks\Calendar\Calendar_Query::parse_event_data( $post );
					$image_url  = get_the_post_thumbnail_url( $post, 'medium_large' );
					
					// Format date and time
					$date_str = '';
					$time_str = '';
					
					if ( ! empty( $event_data['startDate'] ) ) {
						$start_time = ! empty( $event_data['startTime'] ) ? $event_data['startTime'] : '00:00:00';
						$date_obj   = new DateTime( $event_data['startDate'] . ' ' . $start_time, wp_timezone() );
						$date_str   = $date_obj->format( 'D, M j, Y' );
						$time_str   = $date_obj->format( 'g:i A' );
					}
					?>
					<div class="related-tax-card">
						<?php if ( $image_url ) : ?>
							<div class="related-tax-thumb">
								<a href="<?php the_permalink(); ?>">
									<img src="<?php echo esc_url( $image_url ); ?>" alt="<?php echo esc_attr( get_the_title() ); ?>" loading="lazy">
								</a>
							</div>
						<?php endif; ?>
						
						<?php
						if ( class_exists( '\DataMachineEvents\Blocks\Calendar\Taxonomy_Badges' ) ) {
							echo \DataMachineEvents\Blocks\Calendar\Taxonomy_Badges::render_taxonomy_badges( $post->ID );
						}
						?>
						<h4 class="related-tax-title">
							<a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
						</h4>
						
						<div class="related-tax-meta">
							<?php if ( $date_str ) : ?>
								<div class="ec-related-meta-item">
									<?php echo ec_icon('calendar'); ?>
									<span><?php echo esc_html( $date_str ); ?></span>
								</div>
							<?php endif; ?>
							
							<?php if ( $time_str ) : ?>
								<div class="ec-related-meta-item">
									<?php echo ec_icon('clock'); ?>
									<span><?php echo esc_html( $time_str ); ?></span>
								</div>
							<?php endif; ?>

							<a href="<?php the_permalink(); ?>" class="datamachine-more-info-button button-3 button-small">More Info</a>
						</div>
					</div>
					<?php
				endwhile;
				wp_reset_postdata();
				?>
			</div>
		</div>
		<?php
	endif;
}
add_action( 'extrachill_custom_related_posts_display', 'ec_events_render_related_posts', 10, 2 );
