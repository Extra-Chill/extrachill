<?php
/**
 * Discovery Page Template
 *
 * Time-scoped landing page for queries like "live music in austin tonight".
 * Renders scope navigation tabs, map, and a scoped calendar block.
 *
 * @package ExtraChillEvents
 * @since 0.7.0
 */

get_header();
extrachill_breadcrumbs();

$term  = get_queried_object();
$scope = extrachill_events_get_current_scope();
$label = extrachill_events_get_scope_label( $scope );
?>

<div class="events-calendar-container full-width-content">
	<header class="taxonomy-archive-header location-archive-header">
		<h1 class="page-title">Live Music in <?php echo esc_html( $term->name ); ?> <?php echo esc_html( $label ); ?></h1>
		<?php if ( term_description() ) : ?>
			<div class="taxonomy-description"><?php echo wp_kses_post( wpautop( term_description() ) ); ?></div>
		<?php endif; ?>
	</header>

	<?php extrachill_events_render_scope_nav( $term, $scope ); ?>

	<?php do_action( 'extrachill_archive_below_description' ); ?>

	<?php
	echo do_blocks(
		sprintf(
			'<!-- wp:data-machine-events/calendar {"defaultDateRange":"%s"} /-->',
			esc_attr( $scope )
		)
	);
	?>
</div>

<?php get_footer(); ?>
