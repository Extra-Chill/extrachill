<?php
/**
 * Single Event Template
 * A single event. This displays the event title, description, meta, and
 * optionally, the Google map for the event.
 *
 * Override this template in your own theme by creating a file at [your-theme]/tribe-events/single-event.php
 *
 * @package TribeEventsCalendar
 * @version 4.6.19
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( '-1' );
}

$events_label_singular = tribe_get_event_label_singular();
$events_label_plural   = tribe_get_event_label_plural();

$event_id = Tribe__Events__Main::postIdHelper( get_the_ID() );

/**
 * Allows filtering of the event ID.
 *
 * @since 6.0.1
 *
 * @param numeric $event_id
 */
$event_id = apply_filters( 'tec_events_single_event_id', $event_id );

/**
 * Allows filtering of the single event template title classes.
 *
 * @since 5.8.0
 *
 * @param array   $title_classes List of classes to create the class string from.
 * @param numeric $event_id      The ID of the displayed event.
 */
$title_classes = apply_filters( 'tribe_events_single_event_title_classes', [ 'tribe-events-single-event-title' ], $event_id );
$title_classes = implode( ' ', tribe_get_classes( $title_classes ) );

/**
 * Allows filtering of the single event template title before HTML.
 *
 * @since 5.8.0
 *
 * @param string  $before   HTML string to display before the title text.
 * @param numeric $event_id The ID of the displayed event.
 */
$before = apply_filters( 'tribe_events_single_event_title_html_before', '<h1 class="' . $title_classes . '">', $event_id );

/**
 * Allows filtering of the single event template title after HTML.
 *
 * @since 5.8.0
 *
 * @param string  $after    HTML string to display after the title text.
 * @param numeric $event_id The ID of the displayed event.
 */
$after = apply_filters( 'tribe_events_single_event_title_html_after', '</h1>', $event_id );

/**
 * Allows filtering of the single event template title HTML.
 *
 * @since 5.8.0
 *
 * @param string  $after    HTML string to display. Return an empty string to not display the title.
 * @param numeric $event_id The ID of the displayed event.
 */
$title = apply_filters( 'tribe_events_single_event_title_html', the_title( $before, $after, false ), $event_id );
$cost  = tribe_get_formatted_cost( $event_id );

$locations = get_the_terms( get_the_ID(), 'location' );
$location_classes = '';
if ( ! is_wp_error( $locations ) && ! empty( $locations ) ) {
    foreach ( $locations as $location ) {
        $location_classes .= ' location-' . sanitize_html_class( $location->slug );
    }
}
?>

<div id="tribe-events-content" class="tribe-events-single<?php echo esc_attr( $location_classes ); ?>">

	<p class="tribe-events-back">
		<a href="<?php echo esc_url( tribe_get_events_link() ); ?>"> <?php printf( '&laquo; ' . esc_html_x( 'All %s', '%s Events plural label', 'the-events-calendar' ), $events_label_plural ); ?></a>
	</p>
	<?php
	    // Get the location terms for the event
	    $locations = get_the_terms( get_the_ID(), 'location' );

	    if ( ! empty( $locations ) && ! is_wp_error( $locations ) ) : ?>
	    <div class="taxonomy-badges">
	        <?php foreach ( $locations as $location ) : ?>
	            <a href="<?php echo esc_url( get_term_link( $location ) ); ?>" class="taxonomy-badge location-badge"><?php echo esc_html( $location->name ); ?></a>
	        <?php endforeach; ?>
	    </div>
	<?php endif; ?>

	<!-- Notices -->
	<?php tribe_the_notices() ?>

	<?php echo $title; ?>

    <!-- Share button -->
    <?php 
        get_template_part( 'inc/share', array(
            'share_url'   => esc_url( tribe_get_event_link() ), // Use tribe_get_event_link() for single event URL
            'share_title' => esc_attr( get_the_title() ), // Use get_the_title() for single event title
            'share_description' => '', // Optional description - empty for single event view for now
        ) ); 
    ?>

	<div class="tribe-events-schedule tribe-clearfix">
		<?php echo tribe_events_event_schedule_details( $event_id, '<h2>', '</h2>' ); ?>
		<?php if ( ! empty( $cost ) ) : ?>
			<span class="tribe-events-cost"><?php echo esc_html( $cost ) ?></span>
		<?php endif; ?>
	</div>

	<?php
	// Get location terms for the current event
	$locations = get_the_terms( $event_id, 'location' );
	$current_location_term = !empty( $locations ) && !is_wp_error( $locations ) ? reset( $locations ) : false;

	$previous_event_post_location = null;
	$next_event_post_location = null;

	if ($current_location_term) {
		$args_prev = array(
			'post_type'      => 'tribe_events',
			'posts_per_page' => 1,
			'tax_query'      => array(
				array(
					'taxonomy' => 'location',
					'terms'    => array( $current_location_term->term_id ),
					'field'    => 'term_id',
				),
			),
			'meta_key'       => '_EventStartDate',
			'orderby'        => 'meta_value',
			'order'          => 'DESC',
			'meta_query' => array(
				array(
					'key'     => '_EventStartDate',
					'value'   => tribe_get_start_date( $event_id, false, 'Y-m-d H:i:s' ),
					'compare' => '<',
					'type'    => 'DATETIME'
				),
			),
			'post__not_in'   => array( $event_id ),
		);

		$query_prev = new WP_Query( $args_prev );
		$previous_event_post_location = $query_prev->posts ? reset($query_prev->posts) : null;
		wp_reset_postdata();


		$args_next = array(
			'post_type'      => 'tribe_events',
			'posts_per_page' => 1,
			'tax_query'      => array(
				array(
					'taxonomy' => 'location',
					'terms'    => array( $current_location_term->term_id ),
					'field'    => 'term_id',
				),
			),
			'meta_key'       => '_EventStartDate',
			'orderby'        => 'meta_value',
			'order'          => 'ASC',
			'meta_query' => array(
				array(
					'key'     => '_EventStartDate',
					'value'   => tribe_get_start_date( $event_id, false, 'Y-m-d H:i:s' ),
					'compare' => '>',
					'type'    => 'DATETIME'
				),
			),
			'post__not_in'   => array( $event_id ),
		);

		$query_next = new WP_Query( $args_next );
		$next_event_post_location = $query_next->posts ? reset($query_next->posts) : null;
		wp_reset_postdata();
	}


	$previous_event_link = '';
	if ( is_a( $previous_event_post_location, 'WP_Post' ) ) {
		$previous_event_link = get_permalink( $previous_event_post_location );
		$previous_event_title = get_the_title( $previous_event_post_location );
	} 

	$next_event_link = '';
	if ( is_a( $next_event_post_location, 'WP_Post' ) ) {
		$next_event_link = get_permalink( $next_event_post_location );
		$next_event_title = get_the_title( $next_event_post_location );
	}

	?>
	<!-- Event header -->
	<div id="tribe-events-header" <?php tribe_events_the_header_attributes() ?>>
		<!-- Navigation -->
		<nav class="tribe-events-nav-pagination" aria-label="<?php printf( esc_html__( '%s Navigation', 'the-events-calendar' ), $events_label_singular ); ?>">
			<ul class="tribe-events-sub-nav">
				 <li class="tribe-events-nav-previous">
					<?php if ($previous_event_link) : ?>
						<a href="<?php echo esc_url( $previous_event_link ); ?>">
							<span>&laquo;</span> <?php echo esc_html( $previous_event_title ) ?>
						</a>
					<?php endif; ?>
				</li>
				<li class="tribe-events-nav-next">
					<?php if ($next_event_link) : ?>
						<a href="<?php echo esc_url( $next_event_link ); ?>">
							<?php echo esc_html( $next_event_title ) ?> <span>&raquo;</span>
						</a>
					<?php endif; ?>
				</li>
			</ul>
			<!-- .tribe-events-sub-nav -->
		</nav>
	</div>
	<!-- #tribe-events-header -->

</div><!-- #tribe-events-content -->