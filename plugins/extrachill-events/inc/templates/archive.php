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
	<?php if ( is_tax() && class_exists( 'DataMachineEvents\\Core\\Archive_Title' ) ) : ?>
		<header>
			<h1 class="page-title"><?php echo esc_html( \DataMachineEvents\Core\Archive_Title::get() ); ?></h1>
		</header>
	<?php endif; ?>

	<?php echo do_blocks( '<!-- wp:datamachine-events/calendar /-->' ); ?>
</div>

<?php get_footer(); ?>
