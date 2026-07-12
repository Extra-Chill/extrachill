<?php
/**
 * All Events Template (/all)
 *
 * The full all-cities calendar firehose. This is the calendar block that
 * previously lived on the homepage, moved to its own page so the homepage
 * can act as a glanceable router.
 *
 * @package ExtraChillEvents
 * @since 0.24.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
extrachill_breadcrumbs();
?>

<div class="events-calendar-container ec-mobile-full-width-panel">
	<div class="page-content">
		<header class="taxonomy-archive-header">
			<h1 class="page-title"><?php esc_html_e( 'All Live Music Events', 'extrachill-events' ); ?></h1>
			<?php extrachill_events_render_calendar_stats(); ?>
		</header>
	</div>

	<div class="page-content">
		<?php extrachill_events_render_account_market_context(); ?>
		<?php
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- do_blocks() returns trusted, fully-rendered block HTML.
		echo do_blocks( '<!-- wp:data-machine-events/calendar {"showScopePresets":true} /-->' );
		?>
	</div>
</div>

<?php get_footer(); ?>
