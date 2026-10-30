<?php

/**
 * Forums Loop
 *
 * @package bbPress
 * @subpackage Theme
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;
?>

<!-- Community Forums Section -->
<div class="community-section-header">
	<h2>Community Forums</h2>
</div>
<?php
// Forums opted into the archive list via the "Forum Archive Display"
// metabox. This is the ONE live consumer of the flag — it curates the
// forum archive (/forums/) list, NOT the homepage (the homepage moved to
// the feed-first layout + Browse Rooms chips, #65/#66, which select by
// post_status and ignore this meta). The meta key is still
// `_show_on_homepage` (legacy name); a keyed rename + migration is tracked
// separately in #137.
$args = array(
	'post_parent'    => 0,
	'meta_query'     => array(
		array(
			'key'     => '_show_on_homepage',
			'value'   => '1',
			'compare' => '=',
		),
	),
	'orderby'        => 'meta_value',
	'meta_key'       => '_bbp_last_active_time',
	'order'          => 'DESC',
	'posts_per_page' => -1,
);
if ( bbp_has_forums( $args ) ) :
	?>
	<div id="forums-list-archive" class="bbp-forums-grid ec-mobile-full-width-panel">
		<?php
		while ( bbp_forums() ) :
			bbp_the_forum();
			?>
			<?php bbp_get_template_part( 'loop', 'single-forum-card' ); ?>
		<?php endwhile; ?>
	</div>
<?php else : ?>
	<p><?php esc_html_e( 'No forums are currently set to display in the forum archive.', 'extra-chill-community' ); ?></p>
<?php endif; ?>
