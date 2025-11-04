<?php
/**
 * Events Archive Template
 *
 * Unified template for all archive pages on events.extrachill.com.
 * Displays dm-events calendar block which automatically detects and filters
 * by taxonomy context when rendered on taxonomy archive pages.
 *
 * This template is used for:
 * - Taxonomy archives (/festival/bonnaroo/, /venue/ryman/, etc.)
 * - Post type archives (/events/)
 * - Date archives (if any)
 * - Author archives (if any)
 *
 * The dm-events calendar block includes built-in taxonomy auto-filtering
 * that detects the current archive context and filters results accordingly.
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
