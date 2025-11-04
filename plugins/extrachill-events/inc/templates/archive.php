<?php
/**
 * Events Archive Template
 *
 * Unified archive template rendering dm-events calendar block with automatic
 * taxonomy filtering. Handles taxonomy, post type, date, and author archives.
 * Calendar block detects archive context and filters events accordingly.
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
