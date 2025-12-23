<?php
/**
 * Wire hub homepage.
 *
 * @package ExtraChillNewsWire
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

extrachill_breadcrumbs();

$festival_wire_archive_url = get_post_type_archive_link( 'festival_wire' );

$latest_posts = get_posts(
	array(
		'post_type'      => 'festival_wire',
		'posts_per_page' => 10,
		'post_status'    => 'publish',
		'orderby'        => 'date',
		'order'          => 'DESC',
	)
);

echo '<div class="main-content festival-wire-page">';
echo '<main id="main" class="site-main" role="main">';

echo '<header class="page-header">';
echo '<h1 class="page-title">Extra Chill News Wire</h1>';
echo '<div class="archive-description">Automated news feeds across the Extra Chill network.</div>';
echo '</header>';

echo '<section class="ec-wire-hub-card">';
echo '<h2>Festival Wire</h2>';
echo '<p>Music festival announcements, Reddit chatter, schedule drops, and lineup news.</p>';

if ( $festival_wire_archive_url ) {
	echo '<p><a class="button" href="' . esc_url( $festival_wire_archive_url ) . '">View all Festival Wire</a></p>';
}

echo '<div class="festival-wire-grid-container">';
echo '<div class="festival-wire-grid">';

foreach ( $latest_posts as $post ) {
	setup_postdata( $post );
	require __DIR__ . '/content-card.php';
}

wp_reset_postdata();

echo '</div>';
echo '</div>';

echo '</section>';

echo '</main>';
echo '</div>';
