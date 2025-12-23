<?php
/**
 * Wire hub homepage content.
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
?>

<header class="page-header">
	<h1 class="page-title">Extra Chill News Wire</h1>
	<div class="archive-description">Automated news feeds across the Extra Chill network.</div>
</header>

<section class="ec-wire-hub-card">
	<h2>Festival Wire</h2>
	<p>Music festival announcements, Reddit chatter, schedule drops, and lineup news.</p>

	<?php if ( $festival_wire_archive_url ) : ?>
		<p><a class="button" href="<?php echo esc_url( $festival_wire_archive_url ); ?>">View all Festival Wire</a></p>
	<?php endif; ?>

	<div class="festival-wire-grid-container">
		<div class="festival-wire-grid">
			<?php
			global $post;
			foreach ( $latest_posts as $post ) {
				setup_postdata( $post );
				require __DIR__ . '/content-card.php';
			}
			wp_reset_postdata();
			?>
		</div>
	</div>
</section>