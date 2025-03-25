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

?>

<div id="tribe-events-content" class="tribe-events-single">

	<p class="tribe-events-back">
		<a href="<?php echo esc_url( tribe_get_events_link() ); ?>"> <?php printf( '&laquo; ' . esc_html_x( 'All %s', '%s Events plural label', 'the-events-calendar' ), $events_label_plural ); ?></a>
	</p>
	<?php
		    $locations = get_the_terms( get_the_ID(), 'location' );
		    if ( ! is_wp_error( $locations ) && ! empty( $locations ) ) {
		        foreach ( $locations as $location ) {
		            // Create a unique CSS class based on location slug
		            $location_class = 'location-' . sanitize_html_class( $location->slug );
		            echo '<div class="tribe-events-single-event-location ' . esc_attr( $location_class ) . '">'; // Added dynamic class
		            echo '<a class="location-link" href="' . esc_url( get_term_link( $location ) ) . '">' . esc_html( $location->name ) . '</a>';
		            echo '</div>'; // Close the container div
		            break; // Display only the first location, like in content.php
		        }
		    }
		?>

	<!-- Notices -->
	<?php tribe_the_notices() ?>

	<?php echo $title; ?>

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

	<?php while ( have_posts() ) :  the_post(); ?>
		<div id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
			<!-- Event featured image, but exclude link -->
			<?php echo tribe_event_featured_image( $event_id, 'full', false ); ?>

			<!-- Event content -->
			<?php do_action( 'tribe_events_single_event_before_the_content' ) ?>
			<div class="tribe-events-single-event-description tribe-events-content">
				<?php the_content(); ?>
			</div>
			<!-- .tribe-events-single-event-description -->
			<?php do_action( 'tribe_events_single_event_after_the_content' ) ?>

			<!-- Event meta -->
			<?php do_action( 'tribe_events_single_event_before_the_meta' ) ?>
			<?php tribe_get_template_part( 'modules/meta' ); ?>
			<?php do_action( 'tribe_events_single_event_after_the_meta' ) ?>
		</div> <!-- #post-x -->
		<?php if ( get_post_type() == Tribe__Events__Main::POSTTYPE && tribe_get_option( 'showComments', false ) ) comments_template() ?>
	<?php endwhile; ?>

	<!-- Event footer -->
	<div id="tribe-events-footer">
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
	<!-- #tribe-events-footer -->

</div><!-- #tribe-events-content -->
