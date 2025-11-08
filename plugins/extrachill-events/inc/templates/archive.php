<?php
/**
 * Events Archive Template
 *
 * Renders dm-events calendar block with automatic context-aware filtering.
 * Handles all archive types (taxonomy, post type, date, author).
 * Only applies on blog ID 7 (events.extrachill.com).
 *
 * @package ExtraChillEvents
 * @since 1.0.0
 */

get_header();
extrachill_breadcrumbs();
?>

<div class="events-calendar-container full-width-content">
	<?php echo do_blocks( '<!-- wp:dm-events/calendar /-->' ); ?>
</div>

<?php get_footer(); ?>
