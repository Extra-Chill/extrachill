<?php
/**
 * Concert Stats Block — Server Render
 *
 * Outputs a container div that React hydrates into.
 * Passes user context via data attributes.
 * Shows a loading skeleton while JS initializes.
 *
 * @package ExtraChillEvents
 * @since 0.18.0
 */

$user_id = ! empty( $attributes['userId'] ) ? (int) $attributes['userId'] : get_current_user_id();

// Don't render for logged-out users when no explicit userId is set.
if ( ! $user_id ) {
	if ( ! is_user_logged_in() ) {
		return;
	}
	$user_id = get_current_user_id();
}

$blog_id  = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'events' ) : get_current_blog_id();
$events_url = function_exists( 'ec_get_site_url' ) ? ec_get_site_url( 'events' ) : home_url();

$wrapper_attributes = get_block_wrapper_attributes(
	array(
		'class'            => 'ec-concert-stats',
		'data-user-id'     => esc_attr( $user_id ),
		'data-blog-id'     => esc_attr( $blog_id ),
		'data-events-url'  => esc_attr( $events_url ),
		'data-is-own'      => esc_attr( is_user_logged_in() && get_current_user_id() === $user_id ? '1' : '0' ),
	)
);
?>
<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped via get_block_wrapper_attributes. ?>>
	<div class="ec-concert-stats__loading">
		<div class="ec-concert-stats__skeleton ec-concert-stats__skeleton--header"></div>
		<div class="ec-concert-stats__skeleton-row">
			<div class="ec-concert-stats__skeleton ec-concert-stats__skeleton--stat"></div>
			<div class="ec-concert-stats__skeleton ec-concert-stats__skeleton--stat"></div>
			<div class="ec-concert-stats__skeleton ec-concert-stats__skeleton--stat"></div>
			<div class="ec-concert-stats__skeleton ec-concert-stats__skeleton--stat"></div>
		</div>
		<div class="ec-concert-stats__skeleton ec-concert-stats__skeleton--tabs"></div>
		<div class="ec-concert-stats__skeleton ec-concert-stats__skeleton--list"></div>
	</div>
</div>
